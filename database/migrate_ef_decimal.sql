-- ═══════════════════════════════════════════════════════════════════════
-- migrate_ef_decimal.sql — เปลี่ยนชนิด EF / ค่าดูดกลับ จาก float เป็น DECIMAL(13,6) (เขียน 17 ก.ย. 2569)
--
-- ✅ รันลงฐานข้อมูลแล้ว 17 ก.ย. 2569 (สำรองไว้ที่ database/backup_20260917_before_ef_decimal.sql)
-- ‼️ รันซ้ำได้ (MODIFY เป็นชนิดเดิม ไม่เปลี่ยนค่า) แต่ไม่จำเป็น
--
-- เหตุผล: float แม่นประมาณ 7 หลัก — PDO อ่านค่าออกมาได้ 6 หลัก (3.141593 → 3.14159) แต่ SQL คูณด้วยค่าเต็ม 3.1415929794…
--         → ยอดที่หน้ากรอกคำนวณสด (PHP/JS) กับรายงาน (SUM ใน SQL) ต่างกันเล็กน้อย
-- DECIMAL(13,6): หน้าจุด 7 หลัก (เพดาน 1,000,000) + ทศนิยม 6 ตำแหน่ง · ชนิดเดียวกับ Vol / qty (decimal) → ผลคูณตรงกันทุกที่
-- ก่อนรัน ข้อมูลจริงทุกค่ามีทศนิยมไม่เกิน 4 ตำแหน่ง → แปลงแล้วค่าไม่เปลี่ยน
-- ฝั่งโค้ด: ค่าทศนิยมเกิน 6 ตำแหน่งถูกปฏิเสธก่อนบันทึก (officer_ef_error) ไม่ปล่อยให้ฐานข้อมูลปัดทิ้งเงียบ ๆ
--
-- ‼️ สำรองฐานข้อมูลก่อนรัน:
--    C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -u root --default-character-set=utf8mb4 upnetzero > database\backup_YYYYMMDD_before_ef_decimal.sql
-- ตรวจหลังรัน: C:\xampp\php\php.exe tests\admin_item_integrity_test.php (E4 ชนิดคอลัมน์ / E5 ความแม่น)

-- ── ก่อนรัน: จดค่าไว้เทียบ (ต้องเท่ากันหลังรัน) ──
SELECT (SELECT COUNT(*) FROM admin_item)         AS ai_rows, (SELECT ROUND(SUM(ROUND(AD, 4)), 4) FROM admin_item)             AS ai_sum,
       (SELECT COUNT(*) FROM removal_item)       AS ri_rows, (SELECT ROUND(SUM(ROUND(factor, 4)), 4) FROM removal_item)       AS ri_sum,
       (SELECT COUNT(*) FROM removal_event_item) AS re_rows, (SELECT ROUND(SUM(ROUND(factor, 4)), 4) FROM removal_event_item) AS re_sum;

ALTER TABLE admin_item         MODIFY `AD`     DECIMAL(13,6) NULL DEFAULT NULL;
ALTER TABLE removal_item       MODIFY `factor` DECIMAL(13,6) NULL DEFAULT NULL;
ALTER TABLE removal_event_item MODIFY `factor` DECIMAL(13,6) NULL DEFAULT NULL;

-- ── หลังรัน: ชนิดคอลัมน์ + ค่าเทียบ ──
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'admin_item' AND COLUMN_NAME = 'AD')
   OR (TABLE_NAME IN ('removal_item', 'removal_event_item') AND COLUMN_NAME = 'factor'));
SELECT (SELECT COUNT(*) FROM admin_item)         AS ai_rows, (SELECT ROUND(SUM(ROUND(AD, 4)), 4) FROM admin_item)             AS ai_sum,
       (SELECT COUNT(*) FROM removal_item)       AS ri_rows, (SELECT ROUND(SUM(ROUND(factor, 4)), 4) FROM removal_item)       AS ri_sum,
       (SELECT COUNT(*) FROM removal_event_item) AS re_rows, (SELECT ROUND(SUM(ROUND(factor, 4)), 4) FROM removal_event_item) AS re_sum;
