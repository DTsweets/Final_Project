<?php
/**
 * จัดการบัญชีผู้ใช้ของ admin (admin/settings.php)
 * ------------------------------------------------------------
 * แยก logic ออกจากหน้าเพื่อให้ unit test เรียกได้ · กฎตรวจข้อมูลชุดเดียวกับหน้าโปรไฟล์ (includes/profile_entry.php)
 *
 * รหัสผ่านเก็บเป็น hash (includes/password.php) และไม่ส่งรหัสผ่านไปที่หน้าเว็บ
 */

require_once __DIR__ . '/profile_entry.php';
require_once __DIR__ . '/affiliation.php';

const ADMIN_ROLES = ['admin' => 'ผู้ดูแลระบบ', 'officer' => 'เจ้าหน้าที่บันทึกข้อมูล', 'dean' => 'บุคลากร/คณบดี'];

/** รายชื่อผู้ใช้ทั้งหมด (ไม่มีรหัสผ่าน) + จำนวนงานที่เคยสร้าง (ลบผู้ใช้แล้วช่องผู้สร้างจะว่าง) */
function admin_user_list(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.role, u.Affiliation AS affiliation_id,
               u.profile_image, u.created_at, a.affiliation_item,
               (SELECT COUNT(*) FROM event e WHERE e.created_by = u.id)
             + (SELECT COUNT(*) FROM questionnaire q WHERE q.created_by = u.id) AS works
        FROM users u
        LEFT JOIN affiliation_id a ON a.id = u.Affiliation
        ORDER BY FIELD(u.role, 'admin', 'dean', 'officer'), u.id")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => [
        'id' => (int) $r['id'], 'username' => $r['username'], 'firstname' => $r['firstname'], 'lastname' => $r['lastname'],
        'email' => $r['email'], 'role' => $r['role'], 'affiliation_id' => (int) $r['affiliation_id'],
        'affiliation' => $r['affiliation_item'] ?? '-', 'profile_image' => $r['profile_image'],
        'created_at' => $r['created_at'], 'works' => (int) $r['works'],
    ], $rows);
}

/** จำนวนผู้ใช้ตามสิทธิ์ ['admin' => n, 'officer' => n, 'dean' => n] */
function admin_role_counts(array $users): array
{
    $out = array_fill_keys(array_keys(ADMIN_ROLES), 0);
    foreach ($users as $u) if (isset($out[$u['role']])) $out[$u['role']]++;
    return $out;
}

/**
 * ตรวจข้อมูลฟอร์มเพิ่ม/แก้ผู้ใช้
 * @param bool $isNew เพิ่มใหม่ = ต้องกรอกรหัสผ่าน · แก้ไข = เว้นว่างได้ (ไม่เปลี่ยน)
 * @return array [errors (ชื่อช่อง => ข้อความ), data]
 *   data = firstname, lastname, username, email|null, role, affiliation (int|null), new_affiliation (string|null), password (string|null)
 */
function admin_user_validate(PDO $pdo, array $in, bool $isNew): array
{
    // ชื่อ / username / อีเมล — กฎเดียวกับหน้าโปรไฟล์ (ไม่ส่งช่องรหัสผ่าน จึงไม่ตรวจรหัสเดิม)
    [$e, $base] = profile_validate(['firstname' => $in['firstname'] ?? '', 'lastname' => $in['lastname'] ?? '',
        'username' => $in['username'] ?? '', 'email' => $in['email'] ?? ''], []);

    $role = (string) ($in['role'] ?? '');
    if (!isset(ADMIN_ROLES[$role])) $e['role'] = 'กรุณาเลือกสิทธิ์การใช้งาน';

    $aff = (string) ($in['affiliation'] ?? '');
    $affId = null; $newAff = null;
    if ($aff === '__new__') {
        $newAff = trim((string) ($in['new_affiliation'] ?? ''));
        if ($newAff === '') $e['affiliation'] = 'กรุณาพิมพ์ชื่อหน่วยงานใหม่';
        elseif (mb_strlen($newAff) > 100) $e['affiliation'] = 'ชื่อหน่วยงานยาวเกิน 100 ตัวอักษร';
    } else {
        $affId = (int) $aff;
        $st = $pdo->prepare('SELECT 1 FROM affiliation_id WHERE id = ?');
        $st->execute([$affId]);
        if ($affId <= 0 || !$st->fetchColumn()) { $e['affiliation'] = 'กรุณาเลือกหน่วยงาน'; $affId = null; }
    }

    $pw = (string) ($in['password'] ?? '');
    $confirm = (string) ($in['password_confirm'] ?? '');
    if ($pw === '' && $isNew) $e['password'] = 'กรุณากรอกรหัสผ่าน';
    elseif (strlen($pw) > PROFILE_PW_MAX) $e['password'] = 'รหัสผ่านยาวเกิน ' . PROFILE_PW_MAX . ' ตัวอักษร';
    elseif ($pw !== '' && mb_strlen($pw) < PROFILE_PW_MIN) $e['password'] = 'รหัสผ่านต้องมีอย่างน้อย ' . PROFILE_PW_MIN . ' ตัวอักษร';
    if (($pw !== '' || $confirm !== '') && $confirm !== $pw) $e['password_confirm'] = 'รหัสผ่านยืนยันไม่ตรงกัน';

    return [$e, [
        'firstname' => $base['firstname'], 'lastname' => $base['lastname'], 'username' => $base['username'], 'email' => $base['email'],
        'role' => $role, 'affiliation' => $affId, 'new_affiliation' => $newAff, 'password' => $pw === '' ? null : $pw,
    ]];
}

/**
 * กฎความปลอดภัยของบัญชี admin
 * - ห้ามลดสิทธิ์ตัวเอง (หลุดจากหน้านี้ทันที)
 * - ต้องเหลือผู้ดูแลระบบอย่างน้อย 1 คนเสมอ
 * @return string|null ข้อความผิดพลาด
 */
function admin_user_role_guard(PDO $pdo, int $uid, string $newRole, int $self): ?string
{
    if ($uid <= 0 || $newRole === 'admin') return null;
    $st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$uid]);
    if ($st->fetchColumn() !== 'admin') return null;
    if ($uid === $self) return 'ไม่สามารถเปลี่ยนสิทธิ์ของบัญชีตัวเองได้';
    if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) return 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน';
    return null;
}

/** ผู้ใช้ 1 คน (ไม่มีรหัสผ่าน) หรือ null */
function admin_user_find(PDO $pdo, int $uid): ?array
{
    $st = $pdo->prepare('SELECT id, username, firstname, lastname, email, role, Affiliation, profile_image FROM users WHERE id = ?');
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * บันทึกผู้ใช้ (ข้อมูลผ่าน admin_user_validate แล้ว) — ผู้เรียกคุม transaction
 * รหัสผ่าน null = ไม่เปลี่ยน · หน่วยงานใหม่ → สร้าง/ใช้ id เดิมถ้าชื่อซ้ำ
 * @return int id ของผู้ใช้
 */
function admin_user_save(PDO $pdo, ?int $uid, array $d): int
{
    $aff = $d['new_affiliation'] !== null ? create_or_get_affiliation($pdo, $d['new_affiliation']) : (int) $d['affiliation'];
    try {
        if ($uid === null) {
            $pdo->prepare('INSERT INTO users (username, password, firstname, lastname, email, role, Affiliation) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$d['username'], user_password_hash((string) $d['password']), $d['firstname'], $d['lastname'], $d['email'], $d['role'], $aff]);
            return (int) $pdo->lastInsertId();
        }
        $sql = 'UPDATE users SET username = ?, firstname = ?, lastname = ?, email = ?, role = ?, Affiliation = ?' . ($d['password'] !== null ? ', password = ?' : '') . ' WHERE id = ?';
        $args = [$d['username'], $d['firstname'], $d['lastname'], $d['email'], $d['role'], $aff];
        if ($d['password'] !== null) $args[] = user_password_hash($d['password']);
        $args[] = $uid;
        $pdo->prepare($sql)->execute($args);
        return $uid;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new Exception('ชื่อผู้ใช้งานหรืออีเมลซ้ำกับผู้ใช้อื่น');
        throw $e;
    }
}

/** ตรวจก่อนลบ → ข้อความผิดพลาด / null = ลบได้ */
function admin_user_delete_check(PDO $pdo, int $uid, int $self): ?string
{
    $u = admin_user_find($pdo, $uid);
    if (!$u) return 'ไม่พบผู้ใช้งาน';
    if ($uid === $self) return 'ไม่สามารถลบบัญชีตัวเองได้';
    if ($u['role'] === 'admin' && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) return 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน';
    return null;
}

/**
 * ลบผู้ใช้ + ไฟล์รูปโปรไฟล์ (เดิมลบแต่แถว รูปค้างในโฟลเดอร์)
 * แถบเมนูแสดงรูปด้วยนามสกุล .webp เสมอ → ลบทั้งชื่อที่เก็บไว้และชื่อ .webp
 * @return int จำนวนแถวที่ลบ
 */
function admin_user_delete(PDO $pdo, int $uid, string $imgDir): int
{
    $u = admin_user_find($pdo, $uid);
    if (!$u) return 0;
    $st = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $st->execute([$uid]);
    if ($st->rowCount() && $u['profile_image']) {
        profile_unlink($imgDir, $u['profile_image']);
        profile_unlink($imgDir, pathinfo($u['profile_image'], PATHINFO_FILENAME) . '.webp');
    }
    return $st->rowCount();
}

/** ข้อความยืนยันการลบ */
function admin_user_delete_text(array $u): string
{
    $name = trim($u['firstname'] . ' ' . $u['lastname']);
    return "ลบบัญชี \"$name\" ({$u['username']})?\n"
        . ($u['works'] > 0 ? "กิจกรรม/แบบสอบถาม {$u['works']} รายการที่ผู้ใช้นี้สร้างยังอยู่ แต่จะไม่แสดงชื่อผู้สร้าง" : 'ผู้ใช้นี้ยังไม่เคยสร้างกิจกรรมหรือแบบสอบถาม');
}
