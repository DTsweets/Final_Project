<?php
/**
 * Role Labels — single source of truth
 * -------------------------------------
 * ค่า enum ในฐานข้อมูล (admin / officer / dean / user)
 *   admin   = ผู้ดูแลระบบ (System Admin)
 *   officer = เจ้าหน้าที่บันทึกข้อมูล (Officer)
 *   dean    = บุคลากร/คณบดี (Dean, ดูอย่างเดียว)
 */

const ROLE_LABELS = [
    'admin'   => 'ผู้ดูแลระบบ',
    'officer' => 'เจ้าหน้าที่บันทึกข้อมูล',
    'dean'    => 'บุคลากร/คณบดี',
];

/** คืนป้ายภาษาไทยของ role (ถ้าไม่รู้จักคืนค่า role ดิบ) */
function role_label(string $role): string
{
    return ROLE_LABELS[$role] ?? $role;
}
