<?php
/**
 * Role Labels — single source of truth
 * -------------------------------------
 * ค่า enum ในฐานข้อมูลคงเดิม (admin / user / user_n) แต่ป้ายที่แสดงผลรวมไว้ที่นี่ที่เดียว
 *   admin   = ผู้ดูแลระบบ (System Admin)
 *   user    = เจ้าหน้าที่บันทึกข้อมูล (Officer)
 *   user_n  = บุคลากร/คณบดี (Dean, ดูอย่างเดียว)
 */

const ROLE_LABELS = [
    'admin'  => 'ผู้ดูแลระบบ',
    'user'   => 'เจ้าหน้าที่บันทึกข้อมูล',
    'user_n' => 'บุคลากร/คณบดี',
];

/** คืนป้ายภาษาไทยของ role (ถ้าไม่รู้จักคืนค่า role ดิบ) */
function role_label(string $role): string
{
    return ROLE_LABELS[$role] ?? $role;
}
