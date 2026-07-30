<?php
/**
 * Affiliation helper — จัดการหน่วยงาน/คณะ (affiliation_id)
 */

/**
 * สร้างหน่วยงานใหม่ หรือคืน id เดิมถ้าชื่อนี้มีอยู่แล้ว (กันสร้างซ้ำ — ตามนโยบาย "ก")
 * @return int affiliation_id
 * @throws InvalidArgumentException ถ้าชื่อว่าง
 */
function create_or_get_affiliation(PDO $pdo, string $name): int
{
    $name = mb_substr(trim($name), 0, 100);   // ตัดช่องว่าง + จำกัด 100 ตัว
    if ($name === '') {
        throw new InvalidArgumentException('กรุณาระบุชื่อหน่วยงาน');
    }
    // มีชื่อนี้อยู่แล้ว → ใช้ id เดิม
    $sel = $pdo->prepare('SELECT id FROM affiliation_id WHERE affiliation_item = ? LIMIT 1');
    $sel->execute([$name]);
    $id = $sel->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    // ยังไม่มี → สร้างใหม่
    $pdo->prepare('INSERT INTO affiliation_id (affiliation_item) VALUES (?)')->execute([$name]);
    return (int) $pdo->lastInsertId();
}
