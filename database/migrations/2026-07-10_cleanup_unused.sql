-- =====================================================================
-- Migration: 2026-07-10_cleanup_unused.sql
-- Purpose : ลบของค้างที่ไม่มีโค้ดใช้แล้ว
--   - response / response_item : คำตอบนักศึกษารายคนแบบเก่า (เปลี่ยนเป็นกรอกสรุปแทน)
--   - app_config               : ค่าคงที่ของฟีเจอร์สถิติ±CI ที่เลิกใช้
--   - questionnaire.status      : สถานะเปิด/ปิดให้นักศึกษาตอบในระบบ (ไม่มีใครอ่านแล้ว)
--
-- คง questionnaire.title ไว้ (ชื่อชุด, ไม่กระทบ)
-- ปลอดภัย: ยืนยันแล้วไม่มี PHP (นอก tests) อ้างถึง 3 ตาราง/คอลัมน์นี้
-- วิธีรัน: C:\xampp\mysql\bin\mysql.exe -u root upnetzero < database\migrations\2026-07-10_cleanup_unused.sql
-- =====================================================================

USE `upnetzero`;

DROP TABLE IF EXISTS `response_item`;
DROP TABLE IF EXISTS `response`;
DROP TABLE IF EXISTS `app_config`;

ALTER TABLE `questionnaire` DROP COLUMN IF EXISTS `status`;
