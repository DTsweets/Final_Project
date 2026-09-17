<?php
/**
 * ADMIN_G — ตัวช่วยจัดการ "หมวดย่อยตามขอบเขต" (ตาราง admin_g)
 * ---------------------------------------------------------------
 * แต่ละขอบเขต (Scope 1-3) มีหมวดย่อยได้หลายหมวด ตามแนวทาง TGO เช่น ขอบเขต 1 แบ่งเป็น
 *   - การเผาไหม้อยู่กับที่ (Stationary Combustion)
 *   - การเผาไหม้ที่มีการเคลื่อนที่ On Road
 *   - การเผาไหม้ที่มีการเคลื่อนที่ Off Road
 * เชื้อเพลิงชนิดเดียวกันมีค่า EF ต่างกันตามหมวด จึงต้องเลือกหมวดให้ถูก ไม่ใช่เลือกแค่เลขขอบเขต
 *
 * แยกออกมาจาก collect_page.php เพื่อให้ unit test เรียกใช้ได้โดยไม่ต้องรันทั้งหน้า
 */

/**
 * ขอบเขตของข้อมูลที่เก็บผ่านแบบสอบถามและกิจกรรม = ขอบเขต 3 เสมอ
 * ---------------------------------------------------------------
 * เหตุผล 2 ข้อ:
 *
 * 1) ตามนิยาม — แบบสอบถามถามนิสิต/บุคลากรเรื่องการเดินทางและการบริโภคส่วนตัว
 *    ส่วนกิจกรรมนับการเดินทางของผู้เข้าร่วม วัสดุที่ซื้อ และของเสียจากงาน
 *    ทั้งหมดเป็นแหล่งปล่อยที่องค์กร "ไม่ได้เป็นเจ้าของหรือควบคุม"
 *    TGO CFO (อิง ISO 14064-1 / GHG Protocol) จัดไว้ในขอบเขต 3 การปล่อยทางอ้อมอื่น ๆ
 *    เช่น Employee Commuting (หมวด 7) และ Business Travel (หมวด 6)
 *
 * 2) กันนับซ้ำ — เชื้อเพลิงและไฟฟ้าที่องค์กรซื้อเอง ถูกบันทึกครบแล้วในแท็บของเจ้าหน้าที่
 *    (ขอบเขต 1 และ 2) ถ้าปล่อยให้ตั้งรายการแบบสอบถาม/กิจกรรมเป็นขอบเขต 1 หรือ 2 ได้
 *    ยอดจะถูกบวกทับของเดิม เพราะรายงานคณบดีบวกยอดกิจกรรมเข้ากับยอดเจ้าหน้าที่รายขอบเขต
 *
 * ถ้าหน่วยงานเผาเชื้อเพลิงของตัวเองเพื่อจัดกิจกรรมจริง ให้บันทึกในแท็บเจ้าหน้าที่
 * (ซึ่งอยู่ในบิลค่าเชื้อเพลิงรายปีอยู่แล้ว) ไม่ใช่บันทึกซ้ำที่นี่
 */
const INDIRECT_SCOPE = 3;

/**
 * หา admin_g.id (หมวดย่อย) ที่จะผูกกับรายการใหม่
 *
 * เดิมโค้ดใช้ "SELECT id FROM admin_g WHERE scope=N ORDER BY id LIMIT 1" ซึ่งหยิบหมวดแรกเสมอ
 * ทำให้รายการเดินทางถูกยัดเข้าหมวด "การซื้อวัตถุดิบและบริการ" ทั้งหมด (ผิดตามแนวทาง TGO)
 * ตอนนี้ฟอร์มส่ง group_id (admin_g.id) มาตรง ๆ — ฟังก์ชันนี้ตรวจว่ามีจริงแล้วคืน [id, scope]
 *
 * ถ้าไม่ส่ง group_id มา (ฟอร์มเก่า/เรียกจากที่อื่น) จะถอยไปใช้พฤติกรรมเดิมเพื่อไม่ให้ของที่ใช้อยู่พัง
 *
 * @param int|null $require_scope ถ้าระบุ จะยอมรับเฉพาะหมวดของขอบเขตนั้น (ตรวจฝั่ง server
 *                                ไม่ใช่แค่ซ่อนตัวเลือกใน dropdown — POST ปลอมต้องไม่ผ่าน)
 * @return array{0:int,1:int} [admin_g.id, scope 1-3]
 */
function resolve_admin_g(PDO $pdo, ?int $group_id, int $scope_fallback, ?int $require_scope = null): array {
    if ($require_scope !== null) $scope_fallback = $require_scope;

    if ($group_id) {
        $s = $pdo->prepare("SELECT id, scope FROM admin_g WHERE id = ?");
        $s->execute([$group_id]);
        $row = $s->fetch();
        if (!$row) throw new Exception('ไม่พบหมวดย่อยที่เลือก');
        if ($require_scope !== null && (int)$row['scope'] !== $require_scope) {
            throw new Exception("รายการนี้ต้องอยู่ในขอบเขตที่ $require_scope เท่านั้น");
        }
        return [(int)$row['id'], (int)$row['scope']];
    }
    $s = $pdo->prepare("SELECT id, scope FROM admin_g WHERE scope = ? ORDER BY order_num ASC, id ASC LIMIT 1");
    $s->execute([$scope_fallback]);
    if ($row = $s->fetch()) return [(int)$row['id'], (int)$row['scope']];
    throw new Exception("ไม่พบกลุ่มขอบเขต Scope $scope_fallback");
}

/**
 * ตัวเลือกหมวดย่อยสำหรับ dropdown: [['value'=>admin_g.id, 'label'=>'ขอบเขต N · ชื่อหมวด'], ...]
 *
 * @param int|null $only_scope กรองเหลือเฉพาะขอบเขตที่ระบุ (แบบสอบถาม/กิจกรรม ใช้ INDIRECT_SCOPE)
 */
function admin_g_options(array $groups, ?int $only_scope = null): array {
    if ($only_scope !== null) {
        $groups = array_filter($groups, fn($g) => (int)$g['scope'] === $only_scope);
    }
    return array_values(array_map(fn($g) => [
        'value' => (int)$g['id'],
        'label' => 'ขอบเขต ' . $g['scope'] . ' · ' . $g['name_tiem'],
    ], $groups));
}

