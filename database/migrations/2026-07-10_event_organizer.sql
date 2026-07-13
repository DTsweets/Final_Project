-- =====================================================================
-- Migration: 2026-07-10_event_organizer.sql
-- Purpose : รองรับผู้จัด "อื่นๆ" (พิมพ์ชื่อเอง) ในกิจกรรม
--   - event.organizer_name : ชื่อผู้จัดแบบพิมพ์เอง (NULL = ใช้คณะในระบบตาม affiliation_id)
--     ผู้จัด "อื่นๆ" → affiliation_id = 1 (ศูนย์กลาง) + organizer_name = ชื่อที่พิมพ์
--
-- วิธีรัน: C:\xampp\mysql\bin\mysql.exe -u root upnetzero < database\migrations\2026-07-10_event_organizer.sql
-- =====================================================================
USE `upnetzero`;

ALTER TABLE `event`
  ADD COLUMN IF NOT EXISTS `organizer_name` VARCHAR(100) DEFAULT NULL AFTER `affiliation_id`;
