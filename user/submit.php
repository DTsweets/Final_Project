<?php
/**
 * USER — Submit / Edit Data (submit.php)
 * ----------------------------------------
 * สิทธิ์: user เท่านั้น (user_n ดูได้อย่างเดียว)
 * กรอกและอัพเดทข้อมูล Vol ใน user_item
 * แต่ละรายการ = admin_item ที่ admin กำหนดไว้ต่อปี
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['user']); // user_n ไม่มีสิทธิ์กรอกข้อมูล

$pdo      = getDB();
$root     = '../';
$affil_id = (int)$_SESSION['affiliation_id'];
$affil_name = $_SESSION['affiliation_name'];
$fullname = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];
$remaining = session_remaining();
$msg      = '';
$msg_type = 'success';

// ── ปีที่มีอยู่ ──────────────────────────────────
$years = $pdo->query('SELECT * FROM admin_year ORDER BY year DESC')->fetchAll();
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($years[0]['id'] ?? 0);

// ── Handle POST: บันทึกข้อมูล Vol ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_year = (int)($_POST['year_id'] ?? $selected_year);
    $vols      = $_POST['vol'] ?? []; // [admin_item_id => vol_value]

    $pdo->beginTransaction();
    try {
        $stmt_check = $pdo->prepare(
            'SELECT id FROM user_item WHERE admin_item_id=:ai AND affiliation_id=:af AND year_id=:y LIMIT 1'
        );
        $stmt_ins = $pdo->prepare(
            'INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, create_year)
             VALUES (:ai, :af, :y, :v, CURDATE())'
        );
        $stmt_upd = $pdo->prepare(
            'UPDATE user_item SET Vol=:v, create_year=CURDATE() WHERE id=:id'
        );

        foreach ($vols as $ai_id => $vol_val) {
            $ai_id   = (int)$ai_id;
            
            // ถ้าเว้นว่างไว้ข้ามไป
            if ($vol_val === '') continue;
            
            $vol_val = (float)str_replace(',', '', $vol_val);

            $stmt_check->execute([':ai'=>$ai_id, ':af'=>$affil_id, ':y'=>$post_year]);
            $existing = $stmt_check->fetchColumn();

            if ($existing) {
                $stmt_upd->execute([':v'=>$vol_val, ':id'=>$existing]);
            } else {
                $stmt_ins->execute([':ai'=>$ai_id, ':af'=>$affil_id, ':y'=>$post_year, ':v'=>$vol_val]);
            }
        }

        $pdo->commit();
        $msg = 'บันทึกข้อมูลสำเร็จ ' . count(array_filter($vols, fn($v) => $v !== '')) . ' รายการ';
        $selected_year = $post_year;

    } catch (PDOException $e) {
        $pdo->rollBack();
        $msg_type = 'danger';
        $msg = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// ── ดึง admin_item ตามปีที่เลือก ─────────────────
$items_sql = '
    SELECT ai.id AS admin_item_id, ai.name_tiem, ai.unit, ai.AD,
           ag.scope AS scope_group, ag.name_tiem AS group_name,
           ui.Vol, ui.id AS user_item_id
    FROM admin_item ai
    JOIN admin_g ag ON ag.id = ai.scope
    LEFT JOIN user_item ui
        ON ui.admin_item_id = ai.id
        AND ui.affiliation_id = :affil
        AND ui.year_id = :year
    WHERE ai.year_id = :year2
    ORDER BY ag.scope, ai.id
';
$stmt = $pdo->prepare($items_sql);
$stmt->execute([':affil'=>$affil_id, ':year'=>$selected_year, ':year2'=>$selected_year]);
$items = $stmt->fetchAll();

// จัดกลุ่มตาม scope_group
$grouped = [];
foreach ($items as $it) {
    $grouped[$it['scope_group']][] = $it;
}

$scope_labels = [1=>'Scope 1 — การเผาไหม้', 2=>'Scope 2 — การใช้ไฟฟ้า', 3=>'Scope 3 — ทางอ้อม'];
$scope_icons  = [1=>'🔥', 2=>'⚡', 3=>'🌍'];

$year_label = '';
foreach ($years as $y) {
    if ($y['id'] === $selected_year) { $year_label = $y['year']; break; }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กรอกข้อมูล GHG — UP Net Zero</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/user-new.css">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css">
    <style>
        /* Specific Styles for Form matching Figma Light Theme */
        .form-section {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
        }
        .form-header {
            background: #F9FAFB;
            padding: 15px 25px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
        }
        .form-icon {
            font-size: 24px;
            margin-right: 15px;
        }
        .form-title-group {
            flex: 1;
        }
        .form-title-group strong {
            font-size: 16px;
            color: var(--text-main);
            display: block;
        }
        .form-title-group span {
            font-size: 13px;
            color: var(--text-muted);
        }
        .progress-count {
            font-size: 13px;
            font-weight: 500;
            color: var(--purple);
            background: var(--purple-light);
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        .form-body {
            padding: 10px 25px 25px 25px;
        }
        
        .item-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1.5fr;
            gap: 15px;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #F3F4F6;
        }
        .item-row:last-child {
            border-bottom: none;
        }
        .row-head {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            padding-bottom: 12px;
        }
        .input-vol {
            width: 100%;
            height: 46px;
            border: 1px solid #D1D5DB;
            border-radius: 10px;
            padding: 0 15px;
            font-family: inherit;
            font-size: 15px;
            color: var(--purple);
            font-weight: 600;
            transition: all 0.2s;
            background: #FFFFFF;
        }
        .input-vol:focus {
            outline: none;
            border-color: var(--purple);
            box-shadow: 0 0 0 3px var(--purple-light);
            background: #FFFFFF;
        }
        .status-dot {
            display: inline-block;
            width: 10px; height: 10px; border-radius: 50%;
            margin-right: 10px;
        }
        .dot-filled { background: #10B981; box-shadow: 0 0 8px rgba(16,185,129,0.4); }
        .dot-empty { background: #D1D5DB; }


        @media (max-width: 768px) {
            .item-row, .row-head {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body class="light-theme">

    <?php 
    // ใช้ component ของหน้า user/index.php
    include_once __DIR__ . '/includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="page-content">
            <div style="margin-bottom: 30px;">
                <h2 style="font-size: 26px; color: var(--text-main); margin-bottom: 8px;">จัดการข้อมูล GHG ปี <?= $year_label ?></h2>
                <p style="color: var(--text-muted); font-size: 15px;">กรอกและตรวจสอบปริมาณการใช้งาน (Vol) ในแต่ละรายการของ <?= htmlspecialchars($affil_name) ?></p>
            </div>
            
            <div class="tab-nav">
                <?php foreach ($years as $y): ?>
                    <a href="?year=<?= $y['id'] ?>" class="tab-item <?= $y['id'] === $selected_year ? 'active' : '' ?>">
                        ปีงบประมาณ <?= $y['year'] ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($msg): ?>
            <div style="padding: 16px 20px; border-radius: 12px; margin-bottom: 25px; background: <?= $msg_type === 'success' ? '#D1FAE5' : '#FEE2E2' ?>; color: <?= $msg_type === 'success' ? '#065F46' : '#991B1B' ?>; border: 1px solid <?= $msg_type === 'success' ? '#A7F3D0' : '#FECACA' ?>; font-weight: 500; display:flex; align-items:center; gap:10px;">
                <svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <?= htmlspecialchars($msg) ?>
            </div>
            <?php endif; ?>

            <?php if (empty($items)): ?>
            <div style="text-align:center; padding:80px 20px; background:white; border-radius:20px; border:1px solid var(--border-color);">
                <div style="font-size:54px; margin-bottom:15px;">📋</div>
                <h3 style="font-size:22px; margin-bottom:10px;">ยังไม่มีรายการ Emission Factor สำหรับปี <?= $year_label ?></h3>
                <p style="color:var(--text-muted); font-size: 15px;">กรุณาติดต่อผู้ดูแลระบบส่วนกลาง (Admin) เพื่อเพิ่มรายการ</p>
            </div>
            <?php else: ?>

            <form method="POST" id="data-form">
                <input type="hidden" name="year_id" value="<?= $selected_year ?>">

                <?php foreach ($grouped as $scope_group => $group_items): ?>
                <div class="form-section">
                    <div class="form-header">
                        <div class="form-icon"><?= $scope_icons[$scope_group] ?? '📊' ?></div>
                        <div class="form-title-group">
                            <strong><?= $scope_labels[$scope_group] ?? 'Scope '.$scope_group ?></strong>
                            <span><?= $group_items[0]['group_name'] ?? '' ?></span>
                        </div>
                        <div class="progress-count">
                            <?= count(array_filter($group_items, fn($i) => $i['Vol'] !== null)) ?> / <?= count($group_items) ?> 
                        </div>
                    </div>
                    
                    <div class="form-body">
                        <div class="item-row row-head">
                            <div>ชื่อกิจกรรม Emission</div>
                            <div>หน่วย (Unit)</div>
                            <div>ค่าแฟคเตอร์ (AD)</div>
                            <div>ปริมาณ (Vol) *</div>
                        </div>
                        
                        <?php foreach ($group_items as $it): ?>
                        <div class="item-row">
                            <div style="font-weight: 500; font-size: 15px; display:flex; align-items:center;">
                                <span class="status-dot <?= $it['Vol'] !== null ? 'dot-filled' : 'dot-empty' ?>"></span>
                                <?= htmlspecialchars($it['name_tiem']) ?>
                            </div>
                            <div style="color: var(--text-muted); font-size: 14px;">
                                <span style="background:#F3F4F6; padding:4px 10px; border-radius:6px;"><?= htmlspecialchars($it['unit'] ?? '-') ?></span>
                            </div>
                            <div style="color: var(--text-muted); font-size: 15px;">
                                <?= number_format((float)$it['AD'], 4) ?>
                            </div>
                            <div>
                                <input
                                    type="number"
                                    name="vol[<?= $it['admin_item_id'] ?>]"
                                    class="input-vol"
                                    step="0.0001"
                                    min="0"
                                    value="<?= $it['Vol'] !== null ? number_format((float)$it['Vol'], 4, '.', '') : '' ?>"
                                    placeholder="0.0000"
                                >
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div style="display:flex; justify-content:flex-end; gap:15px; margin-top:30px; margin-bottom: 50px;">
                    <a href="./" class="year-tab" style="padding: 14px 30px; background: white;">
                        กลับไปหน้าภาพรวม
                    </a>
                    <button type="submit" class="btn-edit" style="width: auto; padding: 14px 40px; border: none; cursor: pointer; font-size:16px;" id="save-btn">
                        บันทึกข้อมูลทั้งหมด
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </main>

<script>
document.getElementById('save-btn')?.addEventListener('click', function(e) {
    if(!this.closest('form').checkValidity()) return;
    this.disabled = true;
    this.innerText = 'กำลังอัปเดตข้อมูล...';
    document.getElementById('data-form').submit();
});
</script>
</body>
</html>
