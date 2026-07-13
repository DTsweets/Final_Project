-- =====================================================================
-- Migration: 2026-07-10_survey_groups.sql
-- Purpose : ยุบแบบสอบถามเป็นกลุ่มอิสระ (นักศึกษา/บุคลากร/อื่นๆ พิมพ์เอง)
--   - questionnaire.audience  : enum → VARCHAR (ชื่อกลุ่มอิสระ)
--   - admin_item.data_source  : enum → VARCHAR (หัวข้อแบบสอบถามทุกกลุ่ม = 'survey')
--                               *แก้บั๊กเดิมที่ enum ไม่มี 'staff'*
--   - user_item.source        : enum → VARCHAR (ทุกกลุ่ม = 'survey')
--   - ย้ายข้อมูลเดิม student/staff → ชื่อกลุ่มไทย / 'survey'
--
-- วิธีรัน: C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 upnetzero < database\migrations\2026-07-10_survey_groups.sql
-- =====================================================================
SET NAMES utf8mb4;
USE `upnetzero`;

-- questionnaire.audience → กลุ่มอิสระ (เก็บชื่อกลุ่มเป็นข้อความ)
ALTER TABLE `questionnaire` MODIFY COLUMN `audience` VARCHAR(50) NOT NULL DEFAULT '';
UPDATE `questionnaire` SET `audience`='นักศึกษา' WHERE `audience`='student';
UPDATE `questionnaire` SET `audience`='บุคลากร'  WHERE `audience`='staff';

-- admin_item.data_source → VARCHAR (survey topics = 'survey')
ALTER TABLE `admin_item` MODIFY COLUMN `data_source` VARCHAR(20) NOT NULL DEFAULT 'officer';
UPDATE `admin_item` SET `data_source`='survey' WHERE `data_source` <> 'officer';

-- user_item.source → VARCHAR (ทุกแบบสอบถาม = 'survey')
ALTER TABLE `user_item` MODIFY COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'officer';
UPDATE `user_item` SET `source`='survey' WHERE `source` IN ('student','staff');
