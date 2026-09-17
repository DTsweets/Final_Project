<?php
/**
 * Unit Test — responsive ของหน้า admin (หน้าต่าง popup + การ์ดยอดหน้ารายงาน) — ตรวจ CSS ไม่แตะข้อมูล
 * รัน: C:\xampp\php\php.exe tests\responsive_layout_test.php
 *
 * ล็อกกฎ (บั๊กที่เจอบนจอ 1920×1080 ขยาย 125% / 1366×768):
 *   - หน้าต่าง .co-modal ไม่เลื่อนในกล่อง (เดิมแถบเลื่อนยื่นนอกขอบโค้ง + ซ้อนกับแถบเลื่อนของรายการหมวด + ปุ่มท้ายตกขอบ + dropdown ถูกตัด)
 *     จอเตี้ยกว่ากล่อง → ฉากหลังเลื่อนแทน, จอเตี้ย → ลดระยะห่าง
 *   - รายการหมวดในหน้าต่างจัดการหมวดสูงตามจอที่เหลือ
 *   - หน้าต่างเพิ่ม/แก้ไขผู้ใช้บนจอเตี้ย: กว้างขึ้น + การ์ดสิทธิ์แนวนอน
 *   - การ์ดยอดหน้ารายงานไม่ล้นจอ 768–1024px
 * การวัดจริงทุกหน้า × 7 ขนาดจอ ทำในเบราว์เซอร์ (ดูรีวิว)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
$root = dirname(__DIR__);
$co  = (string) file_get_contents("$root/assets/css/collect.css");
$oe  = (string) file_get_contents("$root/assets/css/officer-entry.css");
$au  = (string) file_get_contents("$root/assets/css/admin-users.css");
$rep = (string) file_get_contents("$root/dean/reports.php");

preg_match('/^\.co-modal \{([^}]*)\}/m', $co, $m);
ck('M1 .co-modal ไม่จำกัดความสูง/ไม่เลื่อนในกล่อง และจัดกึ่งกลางด้วย margin:auto (ฉากหลังเลื่อนได้โดยไม่ตัดหัวกล่อง)',
    isset($m[1]) && !str_contains($m[1], 'max-height') && !str_contains($m[1], 'overflow') && str_contains($m[1], 'margin: auto'), $m[0] ?? 'ไม่พบ');
ck('M2 ฉากหลังของหน้าต่าง .co-modal เลื่อนได้ + เว้นขอบ 16px',
    (bool) preg_match('/\.modal-overlay:has\(> \.co-modal\) \{[^}]*overflow-y: auto;[^}]*padding: 16px;/', $co));
ck('M3 จอเตี้ย ≤ 820px / ≤ 680px ลดระยะห่างของหน้าต่าง (หัว, ช่องกรอก, ท้าย)',
    (bool) preg_match('/@media \(max-height: 820px\) \{[^@]*\.co-modal \{ padding:[^@]*\.co-modal \.form-control-dark \{ height: 46px; \}[^@]*\.co-modal \.modal-footer \{/s', $co)
    && (bool) preg_match('/@media \(max-height: 680px\) \{[^@]*\.co-modal \{ padding:/s', $co));
ck('M4 รายการหมวด: สูงตามจอที่เหลือ (ขั้นต่ำ 150px) แทน 46vh ตายตัว',
    str_contains($oe, '.oe-gm-list { max-height: max(150px, calc(100dvh - 430px)); overflow-y: auto;') && !str_contains($oe, 'max-height: 46vh'));
ck('M5 หน้าต่างผู้ใช้บนจอเตี้ย (จอกว้าง > 600px): กว้าง 720px + การ์ดสิทธิ์แนวนอน',
    (bool) preg_match('/@media \(max-height: 820px\) and \(min-width: 601px\) \{\s*\.modal-box\.au-modal \{ max-width: 720px; \}\s*\.au-role \{ flex-direction: row;/', $au));
$rpc = (string) file_get_contents("$root/assets/css/reports.css");
ck('R1 การ์ดยอดหน้ารายงาน: ใช้ ad-hero (3 → 1 ใบตาม container ≤ 700px ชุดเดียวกับ Dashboard) · แผงสองคอลัมน์ → หนึ่งคอลัมน์ ≤ 880px · ไม่เหลือ <style> 3 คอลัมน์ตายตัวในหน้า',
    str_contains($rep, '<div class="ad-hero rp-kpis">') && !str_contains($rep, '.rpf-kpis {') && !str_contains($rep, '@media (max-width:1100px)')
    && (bool) preg_match('/@container \(max-width: 880px\) \{\s*\.rp-row, \.rpf-donuts \{ grid-template-columns: minmax\(0, 1fr\); \}/', $rpc));

// ── จุดเปลี่ยน layout (container = พื้นที่เนื้อหาหลังหักเมนูข้าง 390px) ──
// จอ 1280 → ~810px, 1366 → ~900px, 1536 → ~1070px · เดิมตัดที่ 860/1100 → โน้ตบุ๊กทั่วไปได้ layout มือถือ (การ์ดเรียงแถวเดียว, ตารางเป็นการ์ด)
$ad = (string) file_get_contents("$root/assets/css/admin-dashboard.css");
ck('B1 ตาราง → การ์ด เมื่อพื้นที่ ≤ 760px (officer-entry / admin-users / ปุ่มในแถวของ collect) — ไม่เหลือจุดตัด 860 ของตาราง',
    (bool) preg_match('/@container \(max-width: 760px\) \{\s*\.oe-sec-head/', $oe) && !str_contains($oe, '@container (max-width: 860px)')
    && (bool) preg_match('/@container \(max-width: 760px\) \{\s*\.au-tools/', $au) && !str_contains($au, '@container (max-width: 860px)')
    && (bool) preg_match('/@container \(max-width: 760px\) \{\s*\.oe-table \.item-row td\.co-tools-cell/', $co));
ck('B2 การ์ดสรุป .co-kpis เรียงแถวเดียวเมื่อพื้นที่ ≤ 600px เท่านั้น',
    (bool) preg_match('/@container \(max-width: 600px\) \{\s*\.co-kpis \{ grid-template-columns: 1fr;/', $co)
    && !preg_match('/@container \(max-width: 860px\) \{[^}]*\.co-kpis/', $co));
ck('B3 Dashboard: การ์ดล่างสองคอลัมน์จนถึงพื้นที่ 880px (1366 ได้ 2 คอลัมน์) · การ์ดหลัก/ขอบเขต/แถบ 3–2 ใบจนถึง 700px · ≤1000px ลดขนาดตัวเลข',
    (bool) preg_match('/@container \(max-width: 880px\) \{\s*\.ad-grid \{ grid-template-columns: minmax\(0, 1fr\); \}/', $ad)
    && (bool) preg_match('/@container \(max-width: 700px\) \{\s*\.ad-srcbars \{ grid-template-columns: minmax\(0, 1fr\); \}\s*\.ad-hero, \.ad-scopes \{ grid-template-columns: minmax\(0, 1fr\); \}/', $ad)
    && (bool) preg_match('/@container \(max-width: 1000px\) \{[^}]*\.ad-kpi \{ padding: 18px; \}\s*\.ad-kpi-val b \{ font-size: 1\.75rem; \}/', $ad)
    && !str_contains($ad, '@container (max-width: 1100px)'));
ck('B4 การ์ดสรุป .co-kpi 3 ใบในพื้นที่ 601–760px: ไอคอน 40px + ตัวเลข 1.3rem (จอ 1024 ตัวเลข "30.976 tCO₂e" เคยถูกตัด) · ≤ 600px ไม่โดน (แถวเดียว ขนาดเดิม)',
    (bool) preg_match('/@container \(min-width: 601px\) and \(max-width: 760px\) \{\s*\.co-kpi \{ gap: 10px; \}\s*\.co-kpi-ic \{ width: 40px; height: 40px; \}\s*\.co-kpi-val \{ font-size: 1\.3rem; \}\s*\}/', $co));

echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
