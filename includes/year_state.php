<?php
/**
 * ปีงบประมาณที่ผู้ใช้เลือก — จำไว้ข้ามหน้า (ทุก role)
 * ------------------------------------------------------------------
 * เลือกปีที่หน้าไหนก็ตาม หน้าอื่นต้องใช้ปีเดียวกันต่อ จนกว่าจะเลือกใหม่หรือออกจากระบบ
 *
 * ลำดับการตัดสินใจ (ghg_pick_year):
 *   1) ?year= ใน URL ที่ตรงกับปีในระบบ → ใช้ปีนั้น และ "จำ" ไว้ใน session
 *   2) ปีที่จำไว้ใน session และยังมีอยู่ในระบบ → ใช้ปีนั้น
 *   3) ปีล่าสุดในระบบ
 * ปีที่ส่งมาไม่มีในระบบ (เช่น ปีที่เพิ่งถูกลบ หรือพิมพ์มั่ว) จะถูกข้ามไปใช้ข้อถัดไป ไม่ error
 *
 * เก็บใน $_SESSION['year_id'] — ล้างพร้อม session ตอน logout/หมดอายุ (includes/auth.php)
 * เทสต์: tests/year_state_test.php
 */

/** คีย์ใน session ที่เก็บปีที่เลือกไว้ */
const YEAR_STATE_KEY = 'year_id';

/**
 * ปีที่ควรใช้ของหน้านี้ + จำปีที่เลือกใหม่ลง session
 *
 * @param array $years  ผลจาก ghg_years() — [['year_id'=>int,'year'=>int|string], ...] เรียงปีล่าสุดก่อน
 * @param mixed $req    ค่าปีที่ส่งมากับ request (ปกติคือ $_GET['year']) · null = ไม่ได้ส่งมา
 * @param array $sess   session (ส่ง $_SESSION เข้ามา แก้ค่าให้ด้วย) — เทสต์ส่ง array ปลอมได้
 * @return int year_id ที่เลือก · ไม่มีปีในระบบเลย → 0
 */
function ghg_pick_year(array $years, $req, array &$sess): int
{
    $ids = array_map('intval', array_column($years, 'year_id'));
    if (!$ids) { unset($sess[YEAR_STATE_KEY]); return 0; }

    $wanted = is_numeric($req) ? (int) $req : 0;
    if ($wanted > 0 && in_array($wanted, $ids, true)) {          // 1) เลือกมาใหม่จาก URL
        $sess[YEAR_STATE_KEY] = $wanted;
        return $wanted;
    }

    $saved = (int) ($sess[YEAR_STATE_KEY] ?? 0);
    if ($saved > 0 && in_array($saved, $ids, true)) return $saved;   // 2) ปีที่จำไว้

    $sess[YEAR_STATE_KEY] = $ids[0];                                  // 3) ปีล่าสุด
    return $ids[0];
}

/** ป้ายปี (พ.ศ.) ของ year_id · ไม่พบ → '' */
function ghg_year_label(array $years, int $yearId): string
{
    foreach ($years as $y) if ((int) $y['year_id'] === $yearId) return (string) $y['year'];
    return '';
}

/**
 * เติม year ของผู้ใช้ลงใน URL ภายในระบบ (ใช้กับลิงก์เมนู/ปุ่มข้ามหน้า)
 * - URL ที่ระบุ year มาเองแล้ว จะไม่ถูกทับ
 * - ปี 0 (ยังไม่มีปีในระบบ) → คืน URL เดิม
 */
function ghg_year_url(string $url, int $yearId): string
{
    if ($yearId <= 0) return $url;

    [$path, $hash] = array_pad(explode('#', $url, 2), 2, null);
    if (preg_match('/[?&]year=/', $path)) return $url;

    $out = $path . (strpos($path, '?') === false ? '?' : '&') . 'year=' . $yearId;
    return $hash === null ? $out : $out . '#' . $hash;
}
