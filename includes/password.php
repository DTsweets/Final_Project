<?php
/**
 * รหัสผ่านผู้ใช้ (users.password) — ใช้ร่วม login.php / admin_users_entry.php / profile_entry.php
 * เก็บเป็น hash (password_hash, PASSWORD_DEFAULT = bcrypt) · เดิมเก็บ plaintext (แปลงแล้ว: database/migrate_password_hash.php)
 * ค่าเก่าที่ยังเป็น plaintext (เช่น กู้ฐานจาก upnetzero.sql / ไฟล์สำรอง) ยังตรวจได้ และ login.php เข้ารหัสให้ทันทีที่ login สำเร็จ
 */

/** รหัสผ่านที่จะเก็บลงฐานข้อมูล */
function user_password_hash(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

/** ค่าที่เก็บไว้เป็น hash หรือยัง (password_get_info รู้จัก algorithm) */
function user_password_is_hash(string $stored): bool
{
    return password_get_info($stored)['algo'] !== null;
}

/** รหัสที่กรอกตรงกับค่าที่เก็บไว้หรือไม่ — hash ใช้ password_verify, plaintext เก่าเทียบแบบ constant-time · ค่าว่าง = ไม่ผ่าน */
function user_password_verify(string $stored, string $input): bool
{
    if ($stored === '' || $input === '') return false;
    return user_password_is_hash($stored) ? password_verify($input, $stored) : hash_equals($stored, $input);
}

/** ต้องเข้ารหัสใหม่หรือไม่ (ยังเป็น plaintext หรือ algorithm/cost เปลี่ยน) */
function user_password_needs_upgrade(string $stored): bool
{
    return !user_password_is_hash($stored) || password_needs_rehash($stored, PASSWORD_DEFAULT);
}
