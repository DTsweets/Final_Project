<?php
/**
 * Unit Test — ข้อมูลทดสอบปี 2567/2568 (database/mock_history.php)
 * รัน: C:\xampp\php\php.exe tests\mock_history_test.php   (ต้องเปิด MySQL ใน XAMPP)
 *
 * ล็อกกฎ:
 *   A. ปี 2569 ต้องไม่เปลี่ยน — mock_2569_rows() ยังให้ผลตรงกับ database/mock_2569.sql (กันการแยกฟังก์ชันทำของเดิมพัง)
 *   B. รายการที่ใช้ต้องอยู่ในแม่บทของปีนั้นจริง เป็นของเจ้าหน้าที่ (data_source='officer') — ไม่มี id ของปี 2569 หลุดมา
 *   C. ทุกหน่วยงานกรอกครบ 3 ขอบเขต และสัดส่วนขอบเขตคลาดจากที่กำหนดรายปีไม่เกิน 0.5 จุด
 *   D. ยอดรวมทั้งมหาวิทยาลัยรายปี ≤ เพดาน (2568 = 20,000 · 2567 = 19,000) และไม่ต่ำกว่า 90% ของเพดาน
 *   E. ส่วนผสมรายการ "คละกัน" — ต่างจากปี 2569 และต่างกันเองระหว่างสองปี
 *   F. database/mock_history.sql ตรงกับผลของตัวสร้าง (ไม่ถูกแก้มือ) และแตะเฉพาะ source='officer' ของปี 2567/2568
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../database/mock_history.php';

$pdo  = getDB();
$pass = $fail = 0;

function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

/** แม่บทรายการของเจ้าหน้าที่ในปีหนึ่ง */
function load_catalog(PDO $pdo, int $year_id): array {
    $st = $pdo->prepare('SELECT ai.id, ai.name_tiem, ai.scope AS g, ai.AD, ag.scope
        FROM admin_item ai JOIN admin_g ag ON ag.id = ai.scope
        WHERE ai.year_id = ? AND ai.data_source = \'officer\'');
    $st->execute([$year_id]);
    $out = [];
    foreach ($st as $r) {
        $out[(int) $r['id']] = ['name' => $r['name_tiem'], 'g' => (int) $r['g'], 'ad' => (float) $r['AD'], 'scope' => (int) $r['scope']];
    }
    return $out;
}

/** อ่านแถวจากไฟล์ SQL ที่ตัวสร้างเขียนไว้: (item, affil, year, vol, date, 'officer') */
function sql_rows(string $file): array {
    $rows = [];
    if (!is_file($file)) return $rows;
    if (preg_match_all("/\((\d+), (\d+), (\d+), ([0-9.]+), '([\d-]+)', 'officer'\)/", file_get_contents($file), $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $rows[] = ['admin_item_id' => (int) $x[1], 'affiliation_id' => (int) $x[2], 'year_id' => (int) $x[3],
                       'vol' => (float) $x[4], 'date' => $x[5]];
        }
    }
    return $rows;
}

$cat_2569 = load_catalog($pdo, MOCK_YEAR_ID);

// ── A. ปี 2569 ต้องไม่เปลี่ยน ──────────────────────────────────────────────
echo "== A. ปี 2569 ไม่เปลี่ยน ==\n";
$cat_calib = [];
foreach ($cat_2569 as $id => $c) $cat_calib[$id] = ['ad' => $c['ad'], 'scope' => $c['scope']];
$rows_2569 = mock_2569_rows($cat_calib);
$file_2569 = sql_rows(__DIR__ . '/../database/mock_2569.sql');

ck('mock_2569.sql มีแถวให้เทียบ', $file_2569 !== []);
ck('จำนวนแถวปี 2569 เท่าเดิม', count($rows_2569) === count($file_2569), count($rows_2569) . ' vs ' . count($file_2569));

$diff = 0;
foreach ($rows_2569 as $i => $r) {
    $f = $file_2569[$i] ?? null;
    if (!$f || $f['admin_item_id'] !== $r['admin_item_id'] || $f['affiliation_id'] !== $r['affiliation_id']
        || abs($f['vol'] - $r['vol']) > 1e-9 || $f['date'] !== $r['date']) $diff++;
}
ck('ทุกแถวปี 2569 ตรงกับไฟล์เดิม', $diff === 0, "ต่างกัน $diff แถว");

// ── B–E. ข้อมูลปี 2567/2568 ────────────────────────────────────────────────
$generated = [];
foreach (mock_hist_years() as $year_id => $cfg) {
    echo "\n== ปี {$cfg['year']} (year_id $year_id) ==\n";

    $catalog = load_catalog($pdo, $year_id);
    ck("แม่บทรายการปี {$cfg['year']} มีข้อมูล", $catalog !== []);
    if (!$catalog) continue;

    $map  = mock_hist_item_map($cat_2569, $catalog);
    $rows = mock_hist_rows($cfg, $catalog, $map);
    $generated[$year_id] = $rows;

    // B. รายการอยู่ในแม่บทของปีนั้นจริง
    $bad = array_values(array_unique(array_filter(array_column($rows, 'admin_item_id'), fn($id) => !isset($catalog[$id]))));
    ck('ทุกรายการอยู่ในแม่บทของปีนี้ (ไม่มี id ปี 2569 หลุดมา)', $bad === [], implode(',', $bad));

    // C. ครบ 3 ขอบเขต + สัดส่วนตามที่กำหนด
    $by = [];
    foreach ($rows as $r) {
        $c = $catalog[$r['admin_item_id']] ?? ['ad' => 0, 'scope' => 0];
        $by[$r['affiliation_id']] ??= [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $by[$r['affiliation_id']][$c['scope']] += $r['vol'] * $c['ad'] / 1000;
    }
    $units   = array_keys(mock_2569_profiles());
    $no3     = array_values(array_filter($units, fn($a) => min($by[$a] ?? [0]) <= 0));
    ck('ทุกหน่วยงานกรอกครบ 3 ขอบเขต (' . count($units) . ' หน่วยงาน)', $no3 === [], implode(',', $no3));

    $off = [];
    foreach ($units as $a) {
        $sc = $by[$a] ?? [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $t  = array_sum($sc);
        if ($t <= 0) continue;
        foreach ($cfg['share'] as $s => $want) {
            if (abs($sc[$s] / $t - $want) > 0.005) $off[] = sprintf('%d:S%d=%.2f%%', $a, $s, $sc[$s] / $t * 100);
        }
    }
    $want_txt = implode('/', array_map(fn($v) => round($v * 100), $cfg['share']));
    ck("สัดส่วนขอบเขตทุกหน่วยงาน = $want_txt (คลาดไม่เกิน 0.5 จุด)", $off === [], implode(' ', $off));

    // D. ยอดรวมทั้งมหาวิทยาลัย
    $st = $pdo->prepare('SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id WHERE ui.year_id = ? AND ui.source <> \'officer\'');
    $st->execute([$year_id]);
    $total = mock_hist_total($rows, $catalog) + (float) $st->fetchColumn();
    ck(sprintf('ยอดรวม %.2f ไม่เกินเพดาน %.0f', $total, $cfg['cap']), $total <= $cfg['cap']);
    ck(sprintf('ยอดรวม %.2f ไม่ต่ำกว่า 90%% ของเพดาน', $total), $total >= $cfg['cap'] * 0.90);

    // ฟังก์ชันตรวจของตัวสร้างเองต้องผ่านด้วย
    ck('mock_hist_check() ไม่พบข้อผิดพลาด', mock_hist_check($rows, $catalog, $cfg) === []);
}

// E. ส่วนผสมต้องคละกัน — เทียบ "ชุดรายการต่อหน่วยงาน" ข้ามปี (เทียบด้วยชื่อ+หมวด เพราะ id คนละชุด)
echo "\n== E. ความคละของส่วนผสม ==\n";
$sig = function (array $rows, array $catalog): array {
    $out = [];
    foreach ($rows as $r) {
        $c = $catalog[$r['admin_item_id']] ?? null;
        if ($c) $out[$r['affiliation_id']][] = $c['name'] . '|' . $c['g'];
    }
    foreach ($out as &$v) { sort($v); $v = implode(',', $v); }
    return $out;
};
$s2569 = $sig($rows_2569, $cat_2569);
$sigs  = [];
foreach ($generated as $year_id => $rows) $sigs[$year_id] = $sig($rows, load_catalog($pdo, $year_id));

foreach ($sigs as $year_id => $s) {
    $same = count(array_intersect_assoc($s, $s2569));
    ck("ปี " . mock_hist_years()[$year_id]['year'] . " มีส่วนผสมต่างจากปี 2569 (ซ้ำ $same/" . count($s) . " หน่วยงาน)", $same < count($s) / 2);
}
if (count($sigs) === 2) {
    [$a, $b] = array_values($sigs);
    $same = count(array_intersect_assoc($a, $b));
    ck("ปี 2567 กับ 2568 ส่วนผสมต่างกัน (ซ้ำ $same/" . count($a) . " หน่วยงาน)", $same < count($a) / 2);
}

// ── F. ไฟล์ SQL ที่เขียนไว้ตรงกับผลของตัวสร้าง ───────────────────────────────
echo "\n== F. database/mock_history.sql ==\n";
$hist_file = __DIR__ . '/../database/mock_history.sql';
$file_rows = sql_rows($hist_file);
$gen_rows  = [];
foreach ($generated as $year_id => $rows) {
    foreach ($rows as $r) $gen_rows[] = $r + ['year_id' => $year_id];
}
ck('ไฟล์ SQL มีอยู่และอ่านแถวได้', $file_rows !== [], $hist_file);
ck('จำนวนแถวในไฟล์ตรงกับตัวสร้าง', count($file_rows) === count($gen_rows), count($file_rows) . ' vs ' . count($gen_rows));

$dif = 0;
foreach ($gen_rows as $i => $r) {
    $f = $file_rows[$i] ?? null;
    if (!$f || $f['admin_item_id'] !== $r['admin_item_id'] || $f['affiliation_id'] !== $r['affiliation_id']
        || $f['year_id'] !== $r['year_id'] || abs($f['vol'] - $r['vol']) > 1e-9 || $f['date'] !== $r['date']) $dif++;
}
ck('ทุกแถวในไฟล์ตรงกับตัวสร้าง (ไม่ถูกแก้มือ)', $dif === 0, "ต่างกัน $dif แถว");

$sql = is_file($hist_file) ? file_get_contents($hist_file) : '';
ck('ไฟล์ไม่แตะข้อมูลปี 2569', strpos($sql, ', 25, ') === false && strpos($sql, 'year_id IN (14, 15)') !== false);
ck('ลบเฉพาะแถวของเจ้าหน้าที่ (source = officer)', substr_count($sql, "DELETE FROM user_item WHERE source = 'officer' AND year_id IN (14, 15);") === 1);

echo "\nสรุป: PASS $pass / FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
