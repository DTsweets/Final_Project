<?php
/**
 * ADMIN — ตั้งค่าผู้ใช้งาน (settings.php)
 * ---------------------------------------------------------
 * การ์ดสรุปตามสิทธิ์ (กดเพื่อกรอง) → ตารางผู้ใช้ (ค้นหา / กรองหน่วยงาน / แบ่งหน้า) → เพิ่ม·แก้ไขในหน้าต่างเดียวกัน · ลบผ่าน confirmDelete · ส่งออก Excel
 * logic อยู่ที่ includes/admin_users_entry.php · สไตล์: officer-entry.css + collect.css + admin-users.css (au-)
 *
 * เดิม: ส่งรหัสผ่านไปในหน้าเว็บ (ตาราง + data-password + ช่องแก้ไข), อีเมลว่างเก็บเป็น '' จนคนที่สองชนกัน,
 *       ไม่ตรวจสิทธิ์/หน่วยงาน (ว่างแล้วขึ้นว่า "ซ้ำ"), ลดสิทธิ์/ลบผู้ดูแลคนสุดท้ายได้, รีเฟรชแล้วส่งซ้ำ, แสดง PDO error ดิบ,
 *       ลบผู้ใช้แล้วรูปค้าง, โค้ดอัปโหลดรูปที่ไม่มีช่องให้อัปโหลด, CSS/JS inline ~1,200 บรรทัด
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_users_entry.php';
require_role(['admin']);

$root = '../';
$pdo  = getDB();
$self = (int) ($_SESSION['user_id'] ?? 0);
$img_dir = __DIR__ . '/../assets/images/profiles';

// ── POST ──
$form = null;   // ฟอร์มที่ส่งไม่ผ่าน → เปิดหน้าต่างเดิมพร้อมค่าที่กรอกและข้อความของแต่ละช่อง
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $go = function (string $msg, string $type = 'success', string $extra = '') {
        header('Location: settings.php?msg=' . urlencode($msg) . ($type === 'danger' ? '&msg_type=danger' : '') . $extra);
        exit;
    };

    if ($action === 'add' || $action === 'edit') {
        $isNew = $action === 'add';
        $uid   = $isNew ? null : (int) ($_POST['user_id'] ?? 0);
        [$err, $data] = admin_user_validate($pdo, $_POST, $isNew);
        if (!$isNew && !admin_user_find($pdo, (int) $uid)) $err['_'] = 'ไม่พบผู้ใช้งาน';
        if (!$err) $err = profile_duplicates($pdo, (int) $uid, $data);
        if (!$err && ($g = admin_user_role_guard($pdo, (int) $uid, $data['role'], $self))) $err['role'] = $g;
        if (!$err) {
            try {
                $pdo->beginTransaction();
                $id = admin_user_save($pdo, $uid, $data);
                $pdo->commit();
                if ($id === $self) {
                    $_SESSION['firstname'] = $data['firstname'];
                    $_SESSION['lastname']  = $data['lastname'];
                    $_SESSION['username']  = $data['username'];
                }
                $name = $data['firstname'] . ' ' . $data['lastname'];
                $go($isNew ? "เพิ่มผู้ใช้ $name แล้ว" : "บันทึกข้อมูลของ $name แล้ว", 'success', '&hl=' . $id);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err['_'] = safe_error_message($e);
            }
        }
        $sticky = array_intersect_key($_POST, array_flip(['firstname', 'lastname', 'username', 'email', 'role', 'affiliation', 'new_affiliation']));
        $form = ['mode' => $action, 'id' => (int) $uid, 'values' => array_map('strval', $sticky), 'errors' => $err];
    } elseif ($action === 'delete') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $u = admin_user_find($pdo, $uid);
        if ($msg = admin_user_delete_check($pdo, $uid, $self)) $go('ลบไม่สำเร็จ: ' . $msg, 'danger');
        try {
            admin_user_delete($pdo, $uid, $img_dir);
            $go('ลบบัญชี ' . $u['firstname'] . ' ' . $u['lastname'] . ' แล้ว');
        } catch (PDOException $e) {
            $go('ลบไม่สำเร็จ กรุณาลองใหม่', 'danger');
        }
    } else {
        $go('คำสั่งไม่ถูกต้อง', 'danger');
    }
}

// ── ข้อมูลของหน้า ──
$users   = admin_user_list($pdo);
$counts  = admin_role_counts($users);
$affils  = $pdo->query('SELECT id, affiliation_item FROM affiliation_id ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$admins  = $counts['admin'];
$flash   = $form ? 'บันทึกไม่สำเร็จ กรุณาตรวจสอบข้อมูลในหน้าต่าง' : (string) ($_GET['msg'] ?? '');
$flash_t = $form || (($_GET['msg_type'] ?? '') === 'danger') ? 'danger' : 'success';
$hl      = (int) ($_GET['hl'] ?? 0);

$page_title  = 'ตั้งค่า';
$page_title2 = 'ตั้งค่าผู้ใช้งาน';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$role_icon = ['admin' => ic('shield', 22), 'officer' => ic('note', 22), 'dean' => ic('user', 22)];
$avatar = function (array $u) use ($img_dir, $root, $h) {
    $file = $u['profile_image'] ? pathinfo($u['profile_image'], PATHINFO_FILENAME) . '.webp' : '';
    if ($file && is_file($img_dir . '/' . $file)) return '<img class="au-avatar" src="' . $root . 'assets/images/profiles/' . $h($file) . '" alt="" loading="lazy">';
    return '<span class="au-avatar is-initial" aria-hidden="true">' . $h(mb_substr($u['firstname'], 0, 1)) . '</span>';
};
$svg_edit = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
$svg_del  = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
$svg_dl   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
$svg_eye  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
$fv = fn(string $k) => $h($form['values'][$k] ?? '');
$fe = fn(string $k) => $h($form['errors'][$k] ?? '');
$export_cols = ['col_firstname' => 'ชื่อ', 'col_lastname' => 'นามสกุล', 'col_username' => 'Username', 'col_email' => 'อีเมล', 'col_affiliation' => 'หน่วยงาน', 'col_role' => 'สิทธิ์การใช้งาน'];
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าผู้ใช้งาน — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/collect.css<?= asset_v('assets/css/collect.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin-users.css<?= asset_v('assets/css/admin-users.css') ?>">
</head>

<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/includes/header.php'; ?>
        <?php $toast_msg = $flash; $toast_type = $flash_t; include __DIR__ . '/../components/toast.php'; ?>

        <div class="oe-page au-page">
            <div class="oe-head oe-rise">
                <div>
                    <h1 class="oe-title">ตั้งค่าผู้ใช้งาน</h1>
                    <div class="oe-sub">เพิ่ม แก้ไข และลบบัญชี · กำหนดสิทธิ์การใช้งานและหน่วยงาน</div>
                </div>
                <div class="oe-actions">
                    <button type="button" class="oe-btn oe-btn-ghost" onclick="auExportOpen()"><?= $svg_dl ?> ส่งออก Excel</button>
                    <button type="button" class="oe-btn oe-btn-success" onclick="auOpen(null)">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> เพิ่มผู้ใช้
                    </button>
                </div>
            </div>

            <!-- การ์ดสรุปตามสิทธิ์: กดเพื่อกรองตาราง (กดซ้ำ = ยกเลิก) -->
            <div class="co-kpis au-kpis">
                <?php $k = 1; foreach (ADMIN_ROLES as $role => $label): ?>
                <button type="button" class="co-kpi au-kpi au-role-<?= $role ?> oe-rise" style="--i:<?= $k++ ?>;" data-role="<?= $role ?>" aria-pressed="false" onclick="auRoleFilter(this)">
                    <span class="co-kpi-ic"><?= $role_icon[$role] ?></span>
                    <span><span class="co-kpi-label"><?= $label ?></span>
                        <b class="co-kpi-val" data-au-count="<?= $role ?>"><?= $counts[$role] ?> <small>บัญชี</small></b>
                        <span class="co-kpi-sub">กดเพื่อกรองเฉพาะสิทธิ์นี้</span></span>
                </button>
                <?php endforeach; ?>
            </div>

            <section class="oe-panel oe-rise oe-kpis-gap" style="--i:4;" id="au-panel">
                <div class="co-panel-head">
                    <h2 class="co-h2">ผู้ใช้งานทั้งหมด <span class="co-count" id="auCount"><?= count($users) ?></span></h2>
                    <div class="au-tools">
                        <input type="search" id="auSearch" class="oe-search au-search" placeholder="ค้นหาชื่อ, username, อีเมล..." aria-label="ค้นหาผู้ใช้งาน" autocomplete="off">
                        <?php
                        $dd_id = 'auAffilFilter'; $dd_name = 'affil_filter'; $dd_selected = ''; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:260px;max-width:100%;';
                        $dd_options = array_merge([['value' => '', 'label' => 'ทุกหน่วยงาน']], array_map(fn($a) => ['value' => $a['id'], 'label' => $a['affiliation_item']], $affils));
                        $dd_placeholder = 'ทุกหน่วยงาน';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                </div>

                <table class="au-table">
                    <thead>
                        <tr><th>ผู้ใช้งาน</th><th>อีเมล</th><th>หน่วยงาน</th><th style="width:170px;">สิทธิ์การใช้งาน</th><th style="width:96px;">จัดการ</th></tr>
                    </thead>
                    <tbody id="auBody">
                        <?php foreach ($users as $u):
                            $isSelf = $u['id'] === $self;
                            $lastAdmin = $u['role'] === 'admin' && $admins <= 1;
                            $search = mb_strtolower($u['firstname'] . ' ' . $u['lastname'] . ' ' . $u['username'] . ' ' . ($u['email'] ?? '') . ' ' . $u['affiliation']); ?>
                        <tr class="au-row<?= $u['id'] === $hl ? ' is-hl' : '' ?>" data-id="<?= $u['id'] ?>" data-role="<?= $h($u['role']) ?>" data-affil="<?= $u['affiliation_id'] ?>" data-search="<?= $h($search) ?>">
                            <td>
                                <span class="au-user">
                                    <?= $avatar($u) ?>
                                    <span class="au-user-text">
                                        <b><?= $h($u['firstname'] . ' ' . $u['lastname']) ?><?php if ($isSelf): ?> <span class="au-self">คุณ</span><?php endif; ?></b>
                                        <small>@<?= $h($u['username']) ?></small>
                                    </span>
                                </span>
                            </td>
                            <td data-label="อีเมล"><?= $u['email'] ? $h($u['email']) : '<span class="oe-muted">-</span>' ?></td>
                            <td data-label="หน่วยงาน"><?= $h($u['affiliation']) ?></td>
                            <td data-label="สิทธิ์"><span class="au-pill au-role-<?= $h($u['role']) ?>"><?= $h(ADMIN_ROLES[$u['role']] ?? $u['role']) ?></span></td>
                            <td class="oe-c">
                                <span class="oe-tools">
                                    <button type="button" class="oe-icon-btn oe-icon-edit" title="แก้ไข" aria-label="แก้ไข <?= $h($u['username']) ?>"
                                        data-id="<?= $u['id'] ?>" data-firstname="<?= $h($u['firstname']) ?>" data-lastname="<?= $h($u['lastname']) ?>"
                                        data-username="<?= $h($u['username']) ?>" data-email="<?= $h($u['email'] ?? '') ?>" data-role="<?= $h($u['role']) ?>"
                                        data-affiliation="<?= $u['affiliation_id'] ?>" data-lock-role="<?= $isSelf || $lastAdmin ? ($isSelf ? 'self' : 'last') : '' ?>"
                                        onclick="auOpen(this)"><?= $svg_edit ?></button>
                                    <?php if ($isSelf || $lastAdmin): ?>
                                    <button type="button" class="oe-icon-btn oe-icon-del" disabled title="<?= $isSelf ? 'ลบบัญชีตัวเองไม่ได้' : 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน' ?>" aria-label="ลบไม่ได้"><?= $svg_del ?></button>
                                    <?php else: ?>
                                    <button type="button" class="oe-icon-btn oe-icon-del" title="ลบ" aria-label="ลบ <?= $h($u['username']) ?>"
                                        data-id="<?= $u['id'] ?>" data-msg="<?= $h(admin_user_delete_text($u)) ?>" onclick="auDelete(this)"><?= $svg_del ?></button>
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="oe-empty co-empty au-empty" id="auEmpty" hidden>
                    <h3>ไม่พบผู้ใช้งานที่ตรงกับเงื่อนไข</h3>
                    <p>ลองเปลี่ยนคำค้นหา หรือล้างตัวกรอง</p>
                    <button type="button" class="oe-btn oe-btn-ghost" onclick="auClearFilters()">ล้างตัวกรอง</button>
                </div>

                <div class="au-foot">
                    <span class="oe-muted" id="auInfo"></span>
                    <span class="au-pager">
                        <label class="oe-muted" for="auSize">แสดง</label>
                        <select id="auSize" class="au-size"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="0">ทั้งหมด</option></select>
                        <button type="button" class="oe-gm-btn" id="auPrev" aria-label="หน้าก่อน"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg></button>
                        <b id="auPage">1 / 1</b>
                        <button type="button" class="oe-gm-btn" id="auNext" aria-label="หน้าถัดไป"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></button>
                    </span>
                </div>
                <form method="POST" action="settings.php" id="auDelForm" hidden><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="">
                </form>
            </section>
        </div>

        <!-- เพิ่ม / แก้ไขผู้ใช้ -->
        <div class="modal-overlay<?= $form ? ' open' : '' ?>" id="auModal"<?= $form ? ' style="display:flex;"' : '' ?>>
            <div class="modal-box co-modal co-modal-wide au-modal">
                <div class="modal-title"><span data-au-icon><?= ic($form && $form['mode'] === 'edit' ? 'edit' : 'add', 22) ?></span><span data-au-title><?= $form && $form['mode'] === 'edit' ? 'แก้ไขบัญชีผู้ใช้' : 'เพิ่มบัญชีผู้ใช้' ?></span></div>
                <form method="POST" action="settings.php" id="auForm" novalidate autocomplete="off"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $form['mode'] ?? 'add' ?>" data-au-action>
                    <input type="hidden" name="user_id" value="<?= $form ? (int) $form['id'] : '' ?>" id="auUserId">
                    <p class="co-modal-msg" data-au-err="_" role="alert"><?= $fe('_') ?></p>
                    <div class="co-form-grid">
                        <div class="form-group-dark"><label class="form-label-dark" for="auFirst">ชื่อ *</label>
                            <input class="form-control-dark" id="auFirst" name="firstname" maxlength="100" value="<?= $fv('firstname') ?>"><span class="au-err" data-au-err="firstname"><?= $fe('firstname') ?></span></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="auLast">นามสกุล *</label>
                            <input class="form-control-dark" id="auLast" name="lastname" maxlength="100" value="<?= $fv('lastname') ?>"><span class="au-err" data-au-err="lastname"><?= $fe('lastname') ?></span></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="auUsername">ชื่อผู้ใช้งาน (username) *</label>
                            <input class="form-control-dark" id="auUsername" name="username" maxlength="50" value="<?= $fv('username') ?>" autocapitalize="off" spellcheck="false"><span class="au-err" data-au-err="username"><?= $fe('username') ?></span></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="auEmail">อีเมล</label>
                            <input class="form-control-dark" id="auEmail" name="email" type="email" maxlength="255" value="<?= $fv('email') ?>" placeholder="เว้นว่างได้"><span class="au-err" data-au-err="email"><?= $fe('email') ?></span></div>
                    </div>

                    <div class="form-group-dark"><span class="form-label-dark">สิทธิ์การใช้งาน *</span>
                        <div class="au-roles" role="radiogroup" aria-label="สิทธิ์การใช้งาน">
                            <?php foreach (ADMIN_ROLES as $role => $label): ?>
                            <label class="au-role au-role-<?= $role ?>"><input type="radio" name="role" value="<?= $role ?>"<?= ($form['values']['role'] ?? 'officer') === $role ? ' checked' : '' ?>>
                                <span class="au-role-ic"><?= $role_icon[$role] ?></span><span><?= $label ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <span class="au-err" data-au-err="role"><?= $fe('role') ?></span>
                        <span class="oe-muted au-role-lock" data-au-lock hidden></span>
                    </div>

                    <div class="form-group-dark"><label class="form-label-dark">หน่วยงาน *</label>
                        <?php
                        $dd_id = 'auAffil'; $dd_name = 'affiliation'; $dd_selected = $form['values']['affiliation'] ?? ''; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:100%;';
                        $dd_options = array_merge(array_map(fn($a) => ['value' => $a['id'], 'label' => $a['affiliation_item']], $affils), [['value' => '__new__', 'label' => '+ เพิ่มหน่วยงานใหม่…']]);
                        $dd_placeholder = '-- เลือกหน่วยงาน --';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                        <input class="form-control-dark au-new-affil" id="auNewAffil" name="new_affiliation" maxlength="100" placeholder="พิมพ์ชื่อหน่วยงานใหม่" value="<?= $fv('new_affiliation') ?>"<?= ($form['values']['affiliation'] ?? '') === '__new__' ? '' : ' hidden' ?>>
                        <span class="au-err" data-au-err="affiliation"><?= $fe('affiliation') ?></span>
                    </div>

                    <div class="co-form-grid">
                        <div class="form-group-dark"><label class="form-label-dark" for="auPw">รหัสผ่าน <span data-au-pw-req>*</span></label>
                            <span class="au-pw"><input class="form-control-dark" id="auPw" name="password" type="password" maxlength="50" autocomplete="new-password">
                                <button type="button" class="au-eye" aria-label="แสดงรหัสผ่าน" aria-pressed="false" data-for="auPw"><?= $svg_eye ?></button></span>
                            <span class="au-err" data-au-err="password"><?= $fe('password') ?></span></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="auPw2">ยืนยันรหัสผ่าน</label>
                            <span class="au-pw"><input class="form-control-dark" id="auPw2" name="password_confirm" type="password" maxlength="50" autocomplete="new-password">
                                <button type="button" class="au-eye" aria-label="แสดงรหัสผ่านยืนยัน" aria-pressed="false" data-for="auPw2"><?= $svg_eye ?></button></span>
                            <span class="au-err" data-au-err="password_confirm"><?= $fe('password_confirm') ?></span></div>
                    </div>
                    <div class="oe-note" data-au-pw-note<?= ($form['mode'] ?? '') === 'edit' ? '' : ' hidden' ?>>เว้นช่องรหัสผ่านว่างไว้ = ใช้รหัสผ่านเดิม · ระบบไม่แสดงรหัสผ่านปัจจุบัน</div>

                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('auModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary" data-au-submit><?= ($form['mode'] ?? '') === 'edit' ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้' ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ส่งออก Excel -->
        <div class="modal-overlay" id="auExportModal">
            <div class="modal-box co-modal co-modal-wide">
                <div class="modal-title"><?= $svg_dl ?><span>ส่งออกข้อมูลผู้ใช้งาน</span></div>
                <form method="POST" action="export_users.php" id="auExportForm" target="_self"><?= csrf_field() ?>
                    <input type="hidden" name="cols[]" value="col_no">
                    <div class="form-group-dark"><span class="form-label-dark">คอลัมน์</span>
                        <div class="au-chips">
                            <?php foreach ($export_cols as $key => $label): ?>
                            <label class="au-chip"><input type="checkbox" name="cols[]" value="<?= $key ?>" checked><span><?= $label ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group-dark"><span class="form-label-dark">ผู้ใช้งาน</span>
                        <div class="au-chips">
                            <label class="au-chip"><input type="radio" name="au_scope" value="all" checked><span>ทั้งหมด (<?= count($users) ?>)</span></label>
                            <label class="au-chip"><input type="radio" name="au_scope" value="filtered"><span>ตามตัวกรองในตาราง (<b data-au-filtered><?= count($users) ?></b>)</span></label>
                        </div>
                    </div>
                    <div data-au-ids></div>
                    <p class="co-modal-msg" data-au-export-msg role="alert"></p>
                    <div class="oe-note">ไฟล์ Excel ไม่มีรหัสผ่าน · # ลำดับใส่ให้เสมอ</div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('auExportModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary"><?= $svg_dl ?> ดาวน์โหลด Excel</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        // ประกาศผ่าน window / IIFE — SPA รันสคริปต์ซ้ำทุกครั้งที่เข้าหน้านี้
        (function () {
            var d = document;
            function $(id) { return d.getElementById(id); }
            var AFFIL = <?= json_encode(array_column($affils, 'affiliation_item', 'id'), JSON_UNESCAPED_UNICODE) ?>;
            var ICON_ADD = <?= json_encode(ic('add', 22)) ?>, ICON_EDIT = <?= json_encode(ic('edit', 22)) ?>;

            window.openModal = function (id) { var m = $(id); if (!m) return; m.classList.add('open'); m.style.display = 'flex'; var b = m.querySelector('.modal-box'); if (b) { b.style.animation = 'none'; void b.offsetWidth; b.style.animation = ''; } };
            window.closeModal = function (id) { var m = $(id); if (m) { m.classList.remove('open'); m.style.display = 'none'; } };
            d.querySelectorAll('.au-page ~ .modal-overlay').forEach(function (el) {
                el.addEventListener('click', function (e) { if (e.target === el) closeModal(el.id); });
            });
            if (!window.__coEsc) {
                window.__coEsc = true;
                d.addEventListener('keydown', function (e) {
                    if (e.key !== 'Escape') return;
                    d.querySelectorAll('.modal-overlay.open').forEach(function (m) { closeModal(m.id); });
                });
            }

            // ── ตาราง: ค้นหา / กรองสิทธิ์ / กรองหน่วยงาน / แบ่งหน้า ──
            var rows = Array.prototype.slice.call(d.querySelectorAll('#auBody .au-row'));
            var state = { q: '', role: '', affil: '', page: 1, size: 10 };
            var matched = rows;
            function render(animate) {
                matched = rows.filter(function (r) {
                    return (!state.q || r.dataset.search.indexOf(state.q) !== -1)
                        && (!state.role || r.dataset.role === state.role)
                        && (!state.affil || r.dataset.affil === state.affil);
                });
                var size = state.size || matched.length || 1;
                var pages = Math.max(1, Math.ceil(matched.length / size));
                state.page = Math.min(Math.max(1, state.page), pages);
                var from = (state.page - 1) * size, shown = 0;
                rows.forEach(function (r) { r.hidden = true; });
                matched.forEach(function (r, i) {
                    var on = i >= from && i < from + size;
                    r.hidden = !on;
                    if (on && animate) { r.classList.remove('au-in'); void r.offsetWidth; r.style.setProperty('--i', shown); r.classList.add('au-in'); }
                    if (on) shown++;
                });
                $('auEmpty').hidden = matched.length > 0;
                d.querySelector('.au-table thead').hidden = matched.length === 0;
                $('auCount').textContent = matched.length;
                $('auInfo').textContent = matched.length ? 'แสดง ' + (from + 1) + '–' + (from + shown) + ' จาก ' + matched.length + ' บัญชี' : '';
                $('auPage').textContent = state.page + ' / ' + pages;
                $('auPrev').disabled = state.page <= 1;
                $('auNext').disabled = state.page >= pages;
                var f = d.querySelector('[data-au-filtered]'); if (f) f.textContent = matched.length;
            }
            var timer = null;
            $('auSearch').addEventListener('input', function (e) {
                clearTimeout(timer);
                timer = setTimeout(function () { state.q = e.target.value.trim().toLowerCase(); state.page = 1; render(true); }, 120);
            });
            $('auAffilFilter').addEventListener('dd:change', function (e) { state.affil = String(e.detail.value); state.page = 1; render(true); });
            $('auSize').addEventListener('change', function (e) { state.size = parseInt(e.target.value, 10) || 0; state.page = 1; render(true); });
            $('auPrev').addEventListener('click', function () { state.page--; render(true); });
            $('auNext').addEventListener('click', function () { state.page++; render(true); });
            window.auRoleFilter = function (btn) {
                var on = state.role !== btn.dataset.role;
                state.role = on ? btn.dataset.role : '';
                d.querySelectorAll('.au-kpi').forEach(function (b) { b.setAttribute('aria-pressed', String(on && b === btn)); });
                state.page = 1; render(true);
            };
            window.auClearFilters = function () {
                state.q = ''; state.role = ''; state.affil = ''; state.page = 1;
                $('auSearch').value = '';
                ddSetValue('auAffilFilter', '', 'ทุกหน่วยงาน');
                d.querySelectorAll('.au-kpi').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
                render(true);
            };
            // แถวที่เพิ่ง เพิ่ม/แก้ → ไปหน้าที่มีแถวนั้น
            var hl = d.querySelector('.au-row.is-hl');
            if (hl) state.page = Math.floor(rows.indexOf(hl) / state.size) + 1;
            render(false);
            if (hl) setTimeout(function () { hl.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 150);

            // ── หน้าต่างเพิ่ม/แก้ไข ──
            var form = $('auForm'), modal = $('auModal');
            function field(name) { return form.querySelector('[name="' + name + '"]'); }
            function setErr(name, msg) {
                var el = form.querySelector('[data-au-err="' + name + '"]'); if (el) el.textContent = msg || '';
                var inp = name === 'affiliation' ? $('auAffil_trigger') : (name === 'role' ? null : field(name));
                if (inp) inp.setAttribute('aria-invalid', msg ? 'true' : 'false');
            }
            function clearErrs() { form.querySelectorAll('[data-au-err]').forEach(function (el) { setErr(el.dataset.auErr, ''); }); }
            function isEdit() { return form.querySelector('[data-au-action]').value === 'edit'; }
            function toggleNewAffil() {
                var isNew = $('auAffil_input').value === '__new__';
                $('auNewAffil').hidden = !isNew;
                if (isNew) setTimeout(function () { $('auNewAffil').focus(); }, 30);
            }
            $('auAffil').addEventListener('dd:change', function () { toggleNewAffil(); if (form.dataset.touched) validate(); });

            window.auOpen = function (b) {
                var edit = !!b;
                form.reset(); clearErrs(); delete form.dataset.touched;
                form.querySelector('[data-au-action]').value = edit ? 'edit' : 'add';
                $('auUserId').value = edit ? b.dataset.id : '';
                modal.querySelector('[data-au-title]').textContent = edit ? 'แก้ไขบัญชีผู้ใช้' : 'เพิ่มบัญชีผู้ใช้';
                modal.querySelector('[data-au-icon]').innerHTML = edit ? ICON_EDIT : ICON_ADD;
                modal.querySelector('[data-au-submit]').textContent = edit ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้';
                modal.querySelector('[data-au-pw-note]').hidden = !edit;
                modal.querySelector('[data-au-pw-req]').hidden = edit;
                field('firstname').value = edit ? b.dataset.firstname : '';
                field('lastname').value = edit ? b.dataset.lastname : '';
                field('username').value = edit ? b.dataset.username : '';
                field('email').value = edit ? b.dataset.email : '';
                var role = edit ? b.dataset.role : 'officer';
                form.querySelectorAll('input[name="role"]').forEach(function (r) { r.checked = r.value === role; });
                // บัญชีตัวเอง / ผู้ดูแลคนสุดท้าย → เปลี่ยนสิทธิ์ไม่ได้ (เซิร์ฟเวอร์ตรวจซ้ำ)
                var lock = edit ? b.dataset.lockRole : '';
                form.querySelectorAll('input[name="role"]').forEach(function (r) { r.disabled = !!lock && r.value !== role; });
                var lockEl = modal.querySelector('[data-au-lock]');
                lockEl.hidden = !lock;
                lockEl.textContent = lock === 'self' ? 'เปลี่ยนสิทธิ์ของบัญชีตัวเองไม่ได้' : (lock === 'last' ? 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน จึงเปลี่ยนสิทธิ์ไม่ได้' : '');
                var aff = edit ? b.dataset.affiliation : '';
                if (aff && AFFIL[aff]) ddSetValue('auAffil', aff, AFFIL[aff]);
                else { $('auAffil_input').value = ''; $('auAffil_label').textContent = $('auAffil').dataset.emptyLabel; $('auAffil_label').style.color = '#9CA3AF'; d.querySelectorAll('#auAffil_menu .dd-option').forEach(function (o) { o.classList.remove('active'); }); }
                toggleNewAffil();
                d.querySelectorAll('.au-eye').forEach(function (e) { setEye(e, false); });
                openModal('auModal');
                setTimeout(function () { field('firstname').focus(); }, 60);
            };

            // กฎเดียวกับ admin_user_validate (เซิร์ฟเวอร์ตรวจซ้ำ + ตรวจชื่อซ้ำ)
            function validate() {
                var e = {}, v = function (n) { return field(n).value.trim(); };
                if (!v('firstname')) e.firstname = 'กรุณากรอกชื่อจริง';
                if (!v('lastname')) e.lastname = 'กรุณากรอกนามสกุล';
                if (!v('username')) e.username = 'กรุณากรอกชื่อผู้ใช้งาน';
                else if (/\s/.test(v('username'))) e.username = 'ชื่อผู้ใช้งานต้องไม่มีช่องว่าง';
                if (v('email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('email'))) e.email = 'รูปแบบอีเมลไม่ถูกต้อง';
                if (!form.querySelector('input[name="role"]:checked')) e.role = 'กรุณาเลือกสิทธิ์การใช้งาน';
                var aff = $('auAffil_input').value;
                if (!aff) e.affiliation = 'กรุณาเลือกหน่วยงาน';
                else if (aff === '__new__' && !$('auNewAffil').value.trim()) e.affiliation = 'กรุณาพิมพ์ชื่อหน่วยงานใหม่';
                var pw = field('password').value, pw2 = field('password_confirm').value;
                if (!pw && !isEdit()) e.password = 'กรุณากรอกรหัสผ่าน';
                else if (pw && pw.length < 8) e.password = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
                if ((pw || pw2) && pw !== pw2) e.password_confirm = 'รหัสผ่านยืนยันไม่ตรงกัน';
                ['firstname', 'lastname', 'username', 'email', 'role', 'affiliation', 'password', 'password_confirm'].forEach(function (n) { setErr(n, e[n]); });
                return e;
            }
            form.addEventListener('input', function () { if (form.dataset.touched) validate(); });
            form.addEventListener('change', function () { if (form.dataset.touched) validate(); });
            form.addEventListener('submit', function (ev) {
                form.dataset.touched = '1';
                setErr('_', '');
                var e = validate(), first = Object.keys(e)[0];
                if (!first) { form.querySelector('[data-au-submit]').disabled = true; return; }
                ev.preventDefault();
                var box = modal.querySelector('.modal-box');
                box.classList.remove('oe-shake'); void box.offsetWidth; box.classList.add('oe-shake');
                var target = first === 'affiliation' ? $('auAffil_trigger') : (first === 'role' ? form.querySelector('input[name="role"]') : field(first));
                if (target) target.focus();
            });

            function setEye(btn, on) {
                var inp = $(btn.dataset.for);
                inp.type = on ? 'text' : 'password';
                btn.setAttribute('aria-pressed', String(on));
                btn.classList.toggle('is-on', on);
            }
            d.querySelectorAll('.au-eye').forEach(function (btn) {
                btn.addEventListener('click', function () { setEye(btn, btn.getAttribute('aria-pressed') !== 'true'); });
            });

            <?php if ($form): ?>
            // ส่งไม่ผ่านที่เซิร์ฟเวอร์ → หน้าต่างเปิดค้างไว้พร้อมค่าเดิม
            (function () {
                var mode = <?= json_encode($form['mode']) ?>;
                form.dataset.touched = '1';
                modal.querySelector('[data-au-pw-req]').hidden = mode === 'edit';
                d.querySelectorAll('[data-au-err]').forEach(function (el) { if (el.textContent && el.dataset.auErr !== '_') setErr(el.dataset.auErr, el.textContent); });
                var box = modal.querySelector('.modal-box'); box.classList.add('oe-shake');
            })();
            <?php endif; ?>

            window.auDelete = function (b) {
                confirmDelete({ title: 'ลบบัญชีผู้ใช้?', message: b.dataset.msg, confirmText: 'ลบบัญชี' }).then(function (ok) {
                    if (!ok) return;
                    var f = $('auDelForm');
                    f.querySelector('[name="user_id"]').value = b.dataset.id;
                    f.submit();
                });
            };

            // ── ส่งออก ──
            var ex = $('auExportForm');
            window.auExportOpen = function () { ex.querySelector('[data-au-export-msg]').textContent = ''; render(false); openModal('auExportModal'); };
            ex.addEventListener('submit', function (e) {
                var msg = ex.querySelector('[data-au-export-msg]');
                var cols = ex.querySelectorAll('.au-chip input[name="cols[]"]:checked').length;
                var scope = ex.querySelector('input[name="au_scope"]:checked').value;
                var ids = ex.querySelector('[data-au-ids]');
                ids.innerHTML = '';
                var err = !cols ? 'กรุณาเลือกอย่างน้อย 1 คอลัมน์' : (scope === 'filtered' && !matched.length ? 'ไม่มีผู้ใช้ตามตัวกรองในตาราง' : '');
                if (err) {
                    e.preventDefault(); msg.textContent = err;
                    var box = ex.closest('.modal-box'); box.classList.remove('oe-shake'); void box.offsetWidth; box.classList.add('oe-shake');
                    return;
                }
                msg.textContent = '';
                (scope === 'filtered' ? matched.map(function (r) { return r.dataset.id; }) : ['all']).forEach(function (id) {
                    var i = d.createElement('input'); i.type = 'hidden'; i.name = 'user_ids[]'; i.value = id; ids.appendChild(i);
                });
                setTimeout(function () { closeModal('auExportModal'); }, 400);   // ดาวน์โหลดไฟล์ หน้าเดิมไม่เปลี่ยน
            });
        })();
        </script>
        <?php include __DIR__ . '/../components/confirm_modal.php'; ?>
    </main>
</body>

</html>
