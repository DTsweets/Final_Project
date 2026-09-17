-- ═══════════════════════════════════════════════════════════════
-- cleanup_admin_item.sql — แก้หน่วยที่ขัดกับ TGO + ลบข้อมูลขยะจากการทดสอบ
--
-- ✅ รันลงฐานข้อมูลจริงแล้วเมื่อ 1 ก.ย. 2569 (สำรองไว้ที่ database/backup_before_cleanup_20260901.sql)
--    ผลลัพธ์: แก้หน่วย 2 แถว, ลบ admin_item 6 แถว (CASCADE ไป user_item 6 แถว)
--    ตรวจด้วย tests/admin_item_integrity_test.php → ผ่าน 12 ไม่ผ่าน 0
--
-- ‼️ อย่ารันรวดเดียว — รันทีละส่วน แล้วตรวจผลก่อนไปส่วนถัดไป
-- ‼️ สำรองฐานข้อมูลก่อน:  C:\xampp\mysql\bin\mysqldump.exe -u root upnetzero > backup.sql
--
-- ตรวจสอบหลังรันด้วย:  C:\xampp\php\php.exe tests\admin_item_integrity_test.php
-- ═══════════════════════════════════════════════════════════════


-- ───────────────────────────────────────────────────────────────
-- ส่วนที่ 0 — ดูผลกระทบก่อน (SELECT ล้วน ไม่แก้อะไร) ให้รันส่วนนี้ก่อนเสมอ
-- ───────────────────────────────────────────────────────────────

-- 0.1 รายการที่จะถูกลบในส่วนที่ 2
SELECT ai.id, g.scope AS ขอบเขต, g.name_tiem AS หมวด, ai.name_tiem AS ชื่อรายการ,
       ai.unit, ai.AD, ai.data_source,
       (SELECT COUNT(*) FROM user_item          u WHERE u.admin_item_id = ai.id) AS อ้างอิง_user_item,
       (SELECT COUNT(*) FROM event_item         e WHERE e.admin_item_id = ai.id) AS อ้างอิง_event_item,
       (SELECT COUNT(*) FROM questionnaire_item q WHERE q.admin_item_id = ai.id) AS อ้างอิง_questionnaire
FROM admin_item ai JOIN admin_g g ON g.id = ai.scope
WHERE ai.id IN (91, 92, 99, 101, 102, 103);

-- 0.2 รายการที่จะถูกแก้หน่วยในส่วนที่ 1
SELECT ai.id, g.scope AS ขอบเขต, g.name_tiem AS หมวด, ai.name_tiem, ai.unit, ai.AD
FROM admin_item ai JOIN admin_g g ON g.id = ai.scope
WHERE ai.id IN (34, 35);


-- ───────────────────────────────────────────────────────────────
-- ส่วนที่ 1 — แก้หน่วยให้ตรงกับที่ อบก. (TGO) ประกาศ
--   ไม่แตะค่า AD เลย (AD ถูกต้องอยู่แล้ว) แก้เฉพาะป้ายหน่วย
-- ───────────────────────────────────────────────────────────────

-- 1.1 เบนซิน (Gasoline) หมวดเผาไหม้อยู่กับที่ — TGO ประกาศเป็น kgCO2e ต่อ "ลิตร"
--     ปัจจุบันป้ายเป็น kg ทำให้เจ้าหน้าที่กรอกน้ำหนักแทนปริมาตร → ค่าผิด
-- หมายเหตุ: AD เป็นชนิด float — เทียบ "AD = 2.1894" ตรง ๆ จะไม่ match (ความคลาดเคลื่อนของ float)
--           จึงต้องใช้ ABS(AD - ค่า) < ค่าความคลาดเคลื่อน แทน
UPDATE admin_item SET unit = 'L' WHERE id = 34 AND unit = 'kg' AND ABS(AD - 2.1894) < 0.0001;

-- 1.2 แอลพีจี (LPG) หมวดเผาไหม้อยู่กับที่ — TGO ประกาศเป็น kgCO2e ต่อ "กิโลกรัม"
--     แถว id=55 (หมวด Off-Road) ใช้ kg ถูกแล้ว แต่ id=35 ใช้ L ทั้งที่ค่า AD เท่ากันเป๊ะ
--     → ป้ายหน่วยผิด ไม่ใช่ค่าคนละตัว
UPDATE admin_item SET unit = 'kg' WHERE id = 35 AND unit = 'L' AND ABS(AD - 3.1134) < 0.0001;

-- ตรวจผลส่วนที่ 1
SELECT id, name_tiem, unit, AD FROM admin_item WHERE id IN (34, 35, 48, 55);


-- ───────────────────────────────────────────────────────────────
-- ส่วนที่ 2 — ลบรายการขยะที่เกิดจากการทดสอบ
--
--   ⚠️ admin_item มี ON DELETE CASCADE ไปยัง user_item
--      → ลบ 6 รายการนี้ จะลบ user_item 6 แถว, event_item 3 แถว,
--        questionnaire_item 3 แถว ตามไปด้วย (ยืนยันแล้วจากข้อ 0.1)
--      ทั้งหมดเป็นข้อมูลทดสอบ ไม่ใช่ข้อมูลจริงของมหาวิทยาลัย
--
--   เหตุผลรายตัว:
--     id=91  "น้ำหนักกกกก..."     อยู่ในหมวด "การใช้ไฟฟ้า" (ขอบเขต 2) — ผิดหมวดชัดเจน
--     id=92  "เดินทางงงง..."      ชื่อพิมพ์ผิดซ้ำอักษร
--     id=99  "น้ำมันเบนซิน" AD=0.9875 — ไม่ตรงค่าใดของ TGO
--     id=101 "ระะะะยะทางงง..."    ชื่อพิมพ์ผิดซ้ำอักษร (ค่าสูงสุดของขอบเขต 3 = 1.9700)
--     id=102 "แก๊สโซฮอลลลล..."   ชื่อพิมพ์ผิดซ้ำอักษร
--     id=103 "เบนซิน" AD=0.564    ไม่ตรงค่าใดของ TGO และอยู่ผิดขอบเขต (3 แทนที่จะเป็น 1)
-- ───────────────────────────────────────────────────────────────

DELETE FROM admin_item WHERE id IN (91, 92, 99, 101, 102, 103);

-- ตรวจผลส่วนที่ 2 — ต้องคืนค่า 0 แถว
SELECT id, name_tiem FROM admin_item
WHERE name_tiem REGEXP 'กกกก|ะะะะ|ลลลล|งงงง|ยยยย|ทททท';


-- ───────────────────────────────────────────────────────────────
-- ส่วนที่ 3 — ตรวจความถูกต้องรวม (SELECT ล้วน)
-- ───────────────────────────────────────────────────────────────

-- 3.1 ต้องไม่มีเชื้อเพลิงชนิดเดียวกัน อยู่หมวดเดียวกัน ซ้ำสองแถว
--     (ชื่อซ้ำข้ามหมวดเป็นเรื่องปกติและถูกต้องตาม TGO — คนละ EF)
SELECT year_id, scope AS หมวด, name_tiem, COUNT(*) n
FROM admin_item GROUP BY year_id, scope, name_tiem, affiliation_id HAVING n > 1;

-- 3.2 หน่วยของเชื้อเพลิงชนิดเดียวกันต้องตรงกันทุกหมวด
SELECT name_tiem, GROUP_CONCAT(DISTINCT unit) หน่วยที่ใช้, COUNT(DISTINCT unit) n
FROM admin_item WHERE data_source = 'officer'
GROUP BY name_tiem HAVING n > 1;
