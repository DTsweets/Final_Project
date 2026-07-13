<?php
/**
 * SHARED — หน้ากรอกข้อมูล 2 แท็บ: แบบสอบถาม (หลายกลุ่ม) / กิจกรรม
 * ---------------------------------------------------------------
 * แท็บ แบบสอบถาม (survey): เลือกกลุ่ม (นักศึกษา/บุคลากร/อื่นๆ พิมพ์เอง) + กำหนดหัวข้อ (admin)
 *   + กรอก จำนวนผู้ตอบ×ค่าเฉลี่ย → user_item(source='survey', affiliation=ศูนย์กลาง)
 * แท็บ กิจกรรม (event): จัดการกิจกรรมรายอีเวนต์ (ปริมาณจริง) → user_item(source=event) รายคณะ
 * ทุกอย่างคำนวณ Emission = Vol × EF ÷ 1000 แล้วรวมเข้ายอดคณะ
 *
 * ผู้เรียกกำหนดก่อน include: $pdo, $root, $is_admin, $lock_affil, $SIDEBAR, $HEADER
 */

/** survey: รวม respondents×avg ของ "กลุ่ม" เข้า user_item(source='survey') รายคณะ (rebuild เฉพาะกลุ่มนั้น) */
function reaggregate_survey(PDO $pdo, int $affil, int $year, string $group): void {
    // ลบเฉพาะแถว survey ของกลุ่มนี้ (ระบุกลุ่มผ่าน questionnaire.audience ของ admin_item)
    $pdo->prepare("DELETE ui FROM user_item ui
        JOIN questionnaire_item qi ON qi.admin_item_id = ui.admin_item_id
        JOIN questionnaire q ON q.id = qi.questionnaire_id
        WHERE ui.source='survey' AND ui.affiliation_id=? AND ui.year_id=? AND q.audience=?")
        ->execute([$affil,$year,$group]);
    $pdo->prepare("INSERT INTO user_item (admin_item_id,affiliation_id,year_id,Vol,create_year,source)
        SELECT ss.admin_item_id, ss.affiliation_id, ss.year_id, SUM(ss.respondents*ss.avg_value), CURDATE(), 'survey'
        FROM survey_summary ss JOIN questionnaire_item qi ON qi.id=ss.questionnaire_item_id JOIN questionnaire q ON q.id=qi.questionnaire_id
        WHERE ss.affiliation_id=? AND ss.year_id=? AND q.audience=? GROUP BY ss.admin_item_id")
        ->execute([$affil,$year,$group]);
}
/** event: รวม SUM(Vol) ของทุกกิจกรรมเข้า user_item(source=event) รายคณะ */
function reaggregate_events(PDO $pdo, int $affil, int $year): void {
    $pdo->prepare("DELETE FROM user_item WHERE source='event' AND affiliation_id=? AND year_id=?")->execute([$affil,$year]);
    $pdo->prepare("INSERT INTO user_item (admin_item_id,affiliation_id,year_id,Vol,create_year,source)
        SELECT ei.admin_item_id, e.affiliation_id, e.year_id, SUM(ei.Vol), CURDATE(), 'event'
        FROM event e JOIN event_item ei ON ei.event_id=e.id
        WHERE e.affiliation_id=? AND e.year_id=? GROUP BY ei.admin_item_id")->execute([$affil,$year]);
}
/** ensure 1 questionnaire ต่อ (ปี, กลุ่ม) — คืน id */
function ensure_questionnaire(PDO $pdo, int $year, string $group): int {
    $s = $pdo->prepare("SELECT id FROM questionnaire WHERE year_id=? AND audience=?"); $s->execute([$year,$group]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $yr = $pdo->query("SELECT year FROM admin_year WHERE id=".(int)$year)->fetchColumn();
    $pdo->prepare("INSERT INTO questionnaire (year_id,audience,title) VALUES (?,?,?)")
        ->execute([$year,$group,"แบบสอบถามคาร์บอน{$group} ปี $yr"]);
    return (int)$pdo->lastInsertId();
}

// แท็บ: แบบสอบถาม (แอดมิน/ศูนย์เท่านั้น) + กิจกรรม; เจ้าหน้าที่คณะเห็นเฉพาะกิจกรรม
$TABS = $is_admin ? ['survey' => 'แบบสอบถาม', 'event' => 'กิจกรรม'] : ['event' => 'กิจกรรม'];
$DEFAULT_GROUPS = ['นักศึกษา', 'บุคลากร'];   // กลุ่มมาตรฐาน (เพิ่ม "อื่นๆ" พิมพ์เองได้)
$page_title = 'กรอกข้อมูล';
$CENTRAL_AFFIL = 1; // ศูนย์สิ่งแวดล้อม = หน่วยกลาง (แบบสอบถาม = สเกลมหาวิทยาลัย)
$years  = $pdo->query("SELECT id AS year_id, year FROM admin_year ORDER BY year DESC")->fetchAll();
$affils = $is_admin ? $pdo->query("SELECT id, affiliation_item FROM affiliation_id ORDER BY id")->fetchAll() : [];
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : ($years[0]['year_id'] ?? 0);
$tab = (isset($_GET['tab']) && isset($TABS[$_GET['tab']])) ? $_GET['tab'] : array_key_first($TABS);
$is_survey = $is_admin && $tab === 'survey';
$group = trim((string)($_GET['group'] ?? '')) ?: 'นักศึกษา';   // กลุ่มที่เลือก (แบบสอบถาม)
if ($is_survey)      $sel_affil = $CENTRAL_AFFIL;                       // แบบสอบถาม = หน่วยกลาง (มหาวิทยาลัย)
elseif ($is_admin)   $sel_affil = isset($_GET['affil']) ? (int) $_GET['affil'] : ($affils[0]['id'] ?? 0);
else                 $sel_affil = (int) $lock_affil;

/**
 * แท็บกิจกรรม: ผู้จัด (organizer)
 *   ค่าใน dropdown: "aff:<id>" (คณะในระบบ) | "custom:<ชื่อ>" (อื่นๆ → หน่วยกลาง)
 *   $org_affil = affiliation_id ที่ใช้เก็บ ; $org_name = ชื่อผู้จัดอิสระ (NULL = ใช้คณะ)
 */
$org_affil = $sel_affil; $org_name = null; $org_raw = 'aff:'.$sel_affil;
if ($tab === 'event') {
    if ($is_admin) {
        $org_raw = trim((string)($_GET['org'] ?? ('aff:'.($affils[0]['id'] ?? 1))));
        if (preg_match('/^aff:(\d+)$/', $org_raw, $m)) { $org_affil=(int)$m[1]; $org_name=null; }
        elseif (strpos($org_raw,'custom:')===0)        { $org_name=trim(substr($org_raw,7)); $org_affil=$CENTRAL_AFFIL; }
        else { $org_affil=(int)($affils[0]['id'] ?? 1); $org_name=null; $org_raw='aff:'.$org_affil; }
    } else { $org_affil=(int)$lock_affil; $org_name=null; }
    $sel_affil = $org_affil;
}
$sel_event = isset($_GET['event']) ? (int) $_GET['event'] : 0;
$flash = ''; $flash_t = 'success';
$qs = function($extra = []) use ($selected_year, $tab, $org_raw, $is_admin) {
    $p = ['year' => $selected_year, 'tab' => $tab] + ($is_admin ? ['org' => $org_raw] : []);
    return 'collect.php?' . http_build_query(array_merge($p, $extra));
};

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ptab = (isset($_POST['tab']) && isset($TABS[$_POST['tab']])) ? $_POST['tab'] : $tab;
    $pgroup = trim((string)($_POST['group'] ?? '')) ?: 'นักศึกษา';
    $paffil = $is_admin ? (int)($_POST['affil'] ?? 0) : (int)$lock_affil;
    $pyear = (int)($_POST['year_id'] ?? $selected_year);
    // event: parse ผู้จัด (org) → $porg_affil / $porg_name
    $porg_raw = trim((string)($_POST['org'] ?? ''));
    $porg_affil = (int)$lock_affil; $porg_name = null;
    if ($is_admin) {
        if (preg_match('/^aff:(\d+)$/', $porg_raw, $m)) { $porg_affil=(int)$m[1]; $porg_name=null; }
        elseif (strpos($porg_raw,'custom:')===0) { $porg_name=trim(substr($porg_raw,7)); $porg_affil=$CENTRAL_AFFIL; }
    }
    $redir = "collect.php?year=$pyear&tab=$ptab"
           . ($ptab==='survey' ? "&group=".urlencode($pgroup) : ($is_admin && $ptab==='event' ? "&org=".urlencode($porg_raw) : ''));
    try {
        // ---- survey: เพิ่มหัวข้อ (admin เท่านั้น) ----
        if ($action === 'add_topic' && $is_admin) {
            $label=trim($_POST['label']); $unit=trim($_POST['unit']);
            $scope=(int)$_POST['scope']; $ad=(float)$_POST['ad']; $cat='';
            if ($pgroup==='') throw new Exception('กรุณาระบุกลุ่ม');
            $agid=(int)$pdo->query("SELECT id FROM admin_g WHERE scope=$scope ORDER BY id LIMIT 1")->fetchColumn();
            if (!$agid) throw new Exception("ไม่พบกลุ่มขอบเขต Scope $scope");
            $qid = ensure_questionnaire($pdo,$pyear,$pgroup);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO admin_item (year_id,scope,name_tiem,unit,AD,data_source) VALUES (?,?,?,?,?,'survey')")
                ->execute([$pyear,$agid,$label,$unit,$ad]);
            $aiid=(int)$pdo->lastInsertId();
            $ord=(int)$pdo->query("SELECT COALESCE(MAX(order_num),0)+1 FROM questionnaire_item WHERE questionnaire_id=$qid")->fetchColumn();
            $pdo->prepare("INSERT INTO questionnaire_item (questionnaire_id,admin_item_id,category,label,counts_official,order_num) VALUES (?,?,?,?,1,?)")
                ->execute([$qid,$aiid,$cat,$label,$ord]);
            $pdo->commit();
            header("Location: $redir&msg=".urlencode('เพิ่มหัวข้อแล้ว')); exit;
        }
        if ($action === 'edit_topic' && $is_admin) {
            $qiid=(int)$_POST['qitem_id'];
            $label=trim($_POST['label']); $unit=trim($_POST['unit']);
            $scope=(int)$_POST['scope']; $ad=(float)$_POST['ad'];
            $aiid=(int)$pdo->query("SELECT admin_item_id FROM questionnaire_item WHERE id=$qiid")->fetchColumn();
            $agid=(int)$pdo->query("SELECT id FROM admin_g WHERE scope=$scope ORDER BY id LIMIT 1")->fetchColumn();
            if (!$aiid || !$agid) throw new Exception('ไม่พบหัวข้อ/ขอบเขต');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE admin_item SET scope=?, name_tiem=?, unit=?, AD=? WHERE id=? AND data_source='survey'")
                ->execute([$agid,$label,$unit,$ad,$aiid]);
            $pdo->prepare("UPDATE questionnaire_item SET label=? WHERE id=?")->execute([$label,$qiid]);
            $pdo->commit();
            // EF เปลี่ยน → คำนวณยอดของกลุ่มนี้ใหม่ (ที่หน่วยกลาง)
            reaggregate_survey($pdo,$CENTRAL_AFFIL,$pyear,$pgroup);
            header("Location: $redir&msg=".urlencode('แก้ไขหัวข้อแล้ว')); exit;
        }
        if ($action === 'delete_topic' && $is_admin) {
            $qiid=(int)$_POST['qitem_id'];
            $aiid=(int)$pdo->query("SELECT admin_item_id FROM questionnaire_item WHERE id=$qiid")->fetchColumn();
            $pdo->prepare("DELETE FROM questionnaire_item WHERE id=?")->execute([$qiid]);
            if ($aiid) $pdo->prepare("DELETE FROM admin_item WHERE id=? AND data_source='survey'")->execute([$aiid]);
            reaggregate_survey($pdo,$CENTRAL_AFFIL,$pyear,$pgroup);
            header("Location: $redir&msg=".urlencode('ลบหัวข้อแล้ว')); exit;
        }
        // ---- survey: บันทึกค่าเฉลี่ย ----
        if ($action === 'save_survey' && $is_admin) {
            $paffil = $CENTRAL_AFFIL; // แบบสอบถามผูกหน่วยกลาง (สเกลมหาวิทยาลัย)
            $map=$pdo->prepare("SELECT qi.id, qi.admin_item_id FROM questionnaire_item qi JOIN questionnaire q ON q.id=qi.questionnaire_id WHERE q.year_id=? AND q.audience=?");
            $map->execute([$pyear,$pgroup]); $map=$map->fetchAll(PDO::FETCH_KEY_PAIR);
            $resp=$_POST['resp']??[]; $avg=$_POST['avg']??[];
            $pdo->beginTransaction();
            $up=$pdo->prepare("INSERT INTO survey_summary (affiliation_id,year_id,questionnaire_item_id,admin_item_id,respondents,avg_value,created_by)
                VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE respondents=VALUES(respondents),avg_value=VALUES(avg_value)");
            $del=$pdo->prepare("DELETE FROM survey_summary WHERE affiliation_id=? AND year_id=? AND questionnaire_item_id=?");
            foreach ($map as $qiid=>$aiid) {
                $r=(int)($resp[$qiid]??0); $a=(float)($avg[$qiid]??0);
                if ($r>0 && $a>0) $up->execute([$paffil,$pyear,$qiid,$aiid,$r,$a,$_SESSION['user_id']??null]);
                else $del->execute([$paffil,$pyear,$qiid]);
            }
            reaggregate_survey($pdo,$paffil,$pyear,$pgroup);
            $pdo->commit();
            header("Location: $redir&msg=".urlencode('บันทึกแบบสอบถามแล้ว')); exit;
        }
        // ---- event ----
        if ($action === 'add_event') {
            $name=trim($_POST['name']); $date=$_POST['event_date']?:null;
            $affil=$porg_affil; $oname=$porg_name;   // ผู้จัดจาก selector ด้านบน (คณะ หรือ อื่นๆ→หน่วยกลาง)
            if ($name==='') throw new Exception('กรอกชื่อกิจกรรม');
            if (!$affil && $oname===null) throw new Exception('เลือกผู้จัด');
            $pdo->prepare("INSERT INTO event (name,affiliation_id,organizer_name,year_id,event_date,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$name,$affil,$oname,$pyear,$date,$_SESSION['user_id']??null]);
            $eid=(int)$pdo->lastInsertId();
            header("Location: $redir&event=$eid&msg=".urlencode('เพิ่มกิจกรรมแล้ว')); exit;
        }
        if ($action === 'delete_event') {
            $eid=(int)$_POST['event_id'];
            $row=$pdo->prepare("SELECT affiliation_id,year_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if ($row) {
                if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
                $pdo->prepare("DELETE FROM event WHERE id=?")->execute([$eid]);
                reaggregate_events($pdo,(int)$row['affiliation_id'],(int)$row['year_id']);
            }
            header("Location: $redir&msg=".urlencode('ลบกิจกรรมแล้ว')); exit;
        }
        if ($action === 'add_item') {
            $eid=(int)$_POST['event_id']; $aiid=(int)$_POST['admin_item_id']; $vol=(float)$_POST['vol'];
            $row=$pdo->prepare("SELECT affiliation_id,year_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if (!$row) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $pdo->prepare("INSERT INTO event_item (event_id,admin_item_id,Vol) VALUES (?,?,?)")->execute([$eid,$aiid,$vol]);
            reaggregate_events($pdo,(int)$row['affiliation_id'],(int)$row['year_id']);
            header("Location: $redir&event=$eid&msg=".urlencode('เพิ่มรายการแล้ว')); exit;
        }
        if ($action === 'delete_item') {
            $iid=(int)$_POST['item_id']; $eid=(int)$_POST['event_id'];
            $row=$pdo->prepare("SELECT affiliation_id,year_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if ($row && !$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $pdo->prepare("DELETE FROM event_item WHERE id=?")->execute([$iid]);
            if ($row) reaggregate_events($pdo,(int)$row['affiliation_id'],(int)$row['year_id']);
            header("Location: $redir&event=$eid&msg=".urlencode('ลบรายการแล้ว')); exit;
        }
    } catch (Exception $e) { $flash='ผิดพลาด: '.$e->getMessage(); $flash_t='danger'; }
}
if (isset($_GET['msg'])) $flash = $_GET['msg'];

// ── โหลดข้อมูลตามแท็บ ──
$rows = []; $events = []; $curEvent = null; $curItems = []; $ef_items = []; $year_total = 0; $group_options = [];
if ($is_survey) {
    // รายชื่อกลุ่มสำหรับ dropdown = กลุ่มมาตรฐาน ∪ กลุ่มที่มีอยู่แล้วในปีนี้ ∪ กลุ่มที่เลือกอยู่
    $existing = $pdo->prepare("SELECT DISTINCT audience FROM questionnaire WHERE year_id=?");
    $existing->execute([$selected_year]);
    $group_options = array_values(array_unique(array_merge($DEFAULT_GROUPS, $existing->fetchAll(PDO::FETCH_COLUMN), [$group])));

    $it = $pdo->prepare("
        SELECT qi.id AS qiid, qi.category, qi.label, qi.admin_item_id, ai.unit, ai.AD, ag.scope,
               COALESCE(ss.respondents,0) AS respondents, COALESCE(ss.avg_value,0) AS avg_value,
               (COALESCE(ss.respondents,0)*COALESCE(ss.avg_value,0)*ai.AD)/1000 AS emission
        FROM questionnaire q
        JOIN questionnaire_item qi ON qi.questionnaire_id=q.id
        JOIN admin_item ai ON ai.id=qi.admin_item_id
        JOIN admin_g ag ON ag.id=ai.scope
        LEFT JOIN survey_summary ss ON ss.questionnaire_item_id=qi.id AND ss.affiliation_id=:aff AND ss.year_id=:y
        WHERE q.year_id=:y2 AND q.audience=:grp ORDER BY qi.order_num, qi.id");
    $it->execute([':aff'=>$sel_affil, ':y'=>$selected_year, ':y2'=>$selected_year, ':grp'=>$group]);
    $rows = $it->fetchAll();
    $year_total = array_sum(array_map(fn($r)=>(float)$r['emission'], $rows));
} else { // event
    // รายชื่อผู้จัดสำหรับ dropdown (admin) = คณะในระบบ + ผู้จัดอิสระที่เคยกรอกในปีนี้
    $org_options = [];
    if ($is_admin) {
        foreach ($affils as $a) $org_options[] = ['value'=>'aff:'.$a['id'], 'label'=>$a['affiliation_item']];
        $cst = $pdo->prepare("SELECT DISTINCT organizer_name FROM event WHERE year_id=? AND organizer_name IS NOT NULL AND organizer_name<>''");
        $cst->execute([$selected_year]);
        foreach ($cst->fetchAll(PDO::FETCH_COLUMN) as $cn) $org_options[] = ['value'=>'custom:'.$cn, 'label'=>$cn];
        if ($org_name!==null && !in_array('custom:'.$org_name, array_column($org_options,'value'), true))
            $org_options[] = ['value'=>'custom:'.$org_name, 'label'=>$org_name];
    }
    // filter ตามผู้จัดที่เลือก
    $where = "e.year_id=:y"; $p=[':y'=>$selected_year];
    if ($org_name!==null) { $where .= " AND e.organizer_name=:on"; $p[':on']=$org_name; }
    else { $where .= " AND e.affiliation_id=:aff AND (e.organizer_name IS NULL OR e.organizer_name='')"; $p[':aff']=$org_affil; }
    $evStmt = $pdo->prepare("SELECT e.id, e.name, e.event_date, e.affiliation_id, e.organizer_name,
            COALESCE(e.organizer_name, a.affiliation_item) AS org_label,
            COALESCE(SUM(ei.Vol*ai.AD)/1000,0) AS tco2e, COUNT(ei.id) AS item_count
        FROM event e JOIN affiliation_id a ON a.id=e.affiliation_id
        LEFT JOIN event_item ei ON ei.event_id=e.id LEFT JOIN admin_item ai ON ai.id=ei.admin_item_id
        WHERE $where GROUP BY e.id ORDER BY e.event_date DESC, e.id DESC");
    $evStmt->execute($p);
    $events = $evStmt->fetchAll();
    $year_total = array_sum(array_map(fn($e)=>(float)$e['tco2e'], $events));
    if ($sel_event) {
        foreach ($events as $e) if ((int)$e['id']===$sel_event) { $curEvent=$e; break; }
        if ($curEvent) {
            $it=$pdo->prepare("SELECT ei.id, ei.Vol, ai.name_tiem, ai.unit, ai.AD, ag.scope, (ei.Vol*ai.AD)/1000 AS emission
                FROM event_item ei JOIN admin_item ai ON ai.id=ei.admin_item_id JOIN admin_g ag ON ag.id=ai.scope
                WHERE ei.event_id=? ORDER BY ag.scope, ai.name_tiem");
            $it->execute([$sel_event]); $curItems=$it->fetchAll();
        }
    }
    $ef=$pdo->prepare("SELECT ai.id, ai.name_tiem, ai.unit, ag.scope FROM admin_item ai JOIN admin_g ag ON ag.id=ai.scope
        WHERE ai.year_id=? AND ai.data_source='officer' ORDER BY ag.scope, ai.name_tiem");
    $ef->execute([$selected_year]); $ef_items=$ef->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กรอกข้อมูล — UP Net Zero</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
</head>
<body style="background:#F6F4F9;">
    <?php include $SIDEBAR; ?>
    <main class="main-content">
        <?php include $HEADER; ?>
        <style>
            .co { padding:26px 30px 70px; max-width:1000px; }
            .co h1 { font-size:1.4rem; font-weight:800; color:#2A2233; margin:0 0 12px; }
            .flash { display:flex; align-items:center; gap:10px; border-radius:12px; padding:12px 16px; font-weight:600; margin-bottom:16px; }
            .flash svg { flex-shrink:0; } .flash.success{background:#DCFCE7;color:#166534;} .flash.danger{background:#FEE2E2;color:#B91C1C;}
            .card { background:#fff; border:1px solid #E7E3EC; border-radius:16px; padding:20px 22px; margin-bottom:16px; }
            .card h2 { font-size:1.05rem; font-weight:800; margin:0 0 14px; color:#2A2233; }
            .tabs{display:inline-flex;gap:6px;background:#F1EEF5;border-radius:999px;padding:4px;margin-bottom:16px;}
            .tabs a{padding:8px 20px;border-radius:999px;text-decoration:none;font-weight:700;font-size:.92rem;color:#5B5168;}
            .tabs a.on{background:#62368B;color:#fff;box-shadow:0 6px 14px rgba(98,54,139,.25);}
            .row-top{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
            .grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
            .full{grid-column:1/-1;}
            .fld label{display:block;font-size:.8rem;font-weight:600;color:#4B4155;margin:0 0 5px;}
            .fld input{width:100%;}
            table.t{width:100%;border-collapse:collapse;}
            table.t th{text-align:center;font-size:.75rem;color:#6B7280;text-transform:uppercase;padding:8px 10px;border-bottom:1px solid #E7E3EC;}
            table.t td{padding:9px 10px;border-bottom:1px solid #F1EEF5;font-size:.92rem;text-align:center;vertical-align:middle;}
            /* คอลัมน์แรก (คำถาม/กิจกรรม) ชิดซ้าย ที่เหลือกึ่งกลาง — เหมือนตารางขอบเขต 1 */
            table.t th:first-child, table.t td:first-child{text-align:left;}
            table.t tr.sel td{background:#F5F1FA;}
            .num{text-align:center;font-variant-numeric:tabular-nums;}
            .sdot{font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:6px;}
            .s1{background:#FFF7ED;color:#F97316;}.s2{background:#FDF2F8;color:#EC4899;}.s3{background:#EFF6FF;color:#3B82F6;}
            .inp{width:120px;border:1px solid #E7E3EC;border-radius:8px;padding:7px 10px;font:inherit;text-align:center;background:#FCFBFD;}
            .del{background:none;border:1px solid #E7E3EC;border-radius:8px;color:#EF4444;cursor:pointer;padding:5px 10px;font:inherit;}
            .icobtn{border:none;width:36px;height:36px;border-radius:10px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#fff;transition:all .2s;vertical-align:middle;margin:0 3px;}
            .icobtn.edit{background:#3B82F6;box-shadow:0 4px 10px rgba(59,130,246,.2);}
            .icobtn.edit:hover{background:#2563EB;transform:translateY(-2px);}
            .icobtn.del{background:#EF4444;box-shadow:0 4px 10px rgba(239,68,68,.2);}
            .icobtn.del:hover{background:#DC2626;transform:translateY(-2px);}
            .tot{font-weight:800;color:#62368B;} .muted{color:#6B7280;font-size:.9rem;} .foot{display:flex;justify-content:flex-end;margin-top:14px;}
            .frow{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;} .frow .fld{flex:1;min-width:150px;}
            .linkish{color:#62368B;text-decoration:none;font-weight:600;}
            .co .dd-trigger,.co .ti-input{height:auto;padding:9px 12px;border-radius:10px;font-family:inherit;font-size:1rem;font-weight:400;background:#FCFBFD;border-color:#E7E3EC;}
        </style>

        <div class="co">
            <h1>📝 กรอกข้อมูล</h1>
            <?php if (count($TABS) > 1): ?>
            <div class="tabs">
                <?php foreach ($TABS as $k=>$lbl):
                    $href="collect.php?year=$selected_year&tab=$k";
                    if ($k==='survey') $href.='&group='.urlencode($group);
                    elseif ($k==='event' && $is_admin) $href.='&org='.urlencode($org_raw); ?>
                    <a class="<?= $tab===$k?'on':'' ?>" href="<?= $href ?>"
                        onclick="return !this.classList.contains('on');"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="flash <?= $flash_t==='danger'?'danger':'success' ?>">
                    <?php if ($flash_t==='danger'): ?>
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?php endif; ?>
                    <span><?= htmlspecialchars($flash) ?></span>
                </div>
            <?php endif; ?>

            <div class="row-top">
                <label style="font-weight:600;color:#4B4155;">ปี:</label>
                <?php $dd_id='coYear';$dd_name='year_nav';$dd_options=array_map(fn($y)=>['value'=>$y['year_id'],'label'=>(string)$y['year']],$years);
                    $dd_selected=$selected_year;$dd_required=false;$dd_class='dd-field';$dd_placeholder='เลือกปี';$dd_style='width:110px;';
                    include __DIR__.'/../components/dropdown.php'; ?>
                <?php if ($is_admin && $tab==='event'): ?>
                <label style="font-weight:600;color:#4B4155;margin-left:8px;">ผู้จัด:</label>
                <?php
                    $org_dd = $org_options;
                    $org_dd[] = ['value'=>'__new__','label'=>'อื่นๆ…'];
                    $dd_id='coOrg';$dd_name='org_nav';$dd_options=$org_dd;
                    $dd_selected=$org_raw;$dd_required=false;$dd_class='dd-field';$dd_placeholder='เลือกผู้จัด';$dd_style='width:220px;';
                    include __DIR__.'/../components/dropdown.php'; ?>
                <span id="newOrgWrap" style="display:none;align-items:center;gap:6px;">
                    <input id="newOrgInput" class="ti-input" style="width:200px;" placeholder="พิมพ์ชื่อผู้จัด" onkeydown="if(event.key==='Enter'){event.preventDefault();goNewOrg();}">
                    <button type="button" onclick="goNewOrg()" class="del" style="border-radius:8px;">ตกลง</button>
                </span>
                <?php if ($org_name!==null): ?><span class="muted">· รวมเข้ามหาวิทยาลัย</span><?php endif; ?>
                <?php elseif ($is_survey): ?>
                <label style="font-weight:600;color:#4B4155;margin-left:8px;">กลุ่ม:</label>
                <?php
                    $group_dd = array_map(fn($g)=>['value'=>$g,'label'=>$g],$group_options);
                    $group_dd[] = ['value'=>'__new__','label'=>'อื่นๆ…'];
                    $dd_id='coGroup';$dd_name='group_nav';$dd_options=$group_dd;
                    $dd_selected=$group;$dd_required=false;$dd_class='dd-field';$dd_placeholder='เลือกกลุ่ม';$dd_style='width:180px;';
                    include __DIR__.'/../components/dropdown.php'; ?>
                <span id="newGroupWrap" style="display:none;align-items:center;gap:6px;">
                    <input id="newGroupInput" class="ti-input" style="width:180px;" placeholder="พิมพ์ชื่อกลุ่มใหม่" onkeydown="if(event.key==='Enter'){event.preventDefault();goNewGroup();}">
                    <button type="button" onclick="goNewGroup()" class="del" style="border-radius:8px;">ตกลง</button>
                </span>
                <?php endif; ?>
                <span class="muted" style="margin-left:auto;">รวม (<?= htmlspecialchars($is_survey?$group:$TABS[$tab]) ?>): <span class="tot"><?= number_format($year_total,3) ?></span> tCO₂e</span>
            </div>

            <?php if ($is_survey): /* ===== แท็บ นักศึกษา/บุคลากร ===== */ ?>
                <?php if ($is_admin): ?>
                <div class="card">
                    <h2>＋ เพิ่มหัวข้อ (<?= htmlspecialchars($group) ?>)</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_topic"><input type="hidden" name="tab" value="<?= $tab ?>">
                        <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="affil" value="<?= $sel_affil ?>">
                        <input type="hidden" name="group" value="<?= htmlspecialchars($group,ENT_QUOTES) ?>">
                        <div class="grid">
                            <div class="fld full"><label>คำถาม (ที่ผู้ตอบเห็นในฟอร์ม)</label>
                                <?php $ti_id='coLabel';$ti_name='label';$ti_required=true;$ti_placeholder='เช่น ระยะทางเดินทางมามหาลัย (มอเตอร์ไซค์) กม./ปี';include __DIR__.'/../components/text_input.php'; ?></div>
                            <div class="fld"><label>หน่วย</label>
                                <?php $ti_id='coUnit';$ti_name='unit';$ti_required=true;$ti_placeholder='กม. / มื้อ / kg';include __DIR__.'/../components/text_input.php'; ?></div>
                            <div class="fld"><label>ขอบเขต (Scope)</label>
                                <?php $dd_id='coScope';$dd_name='scope';$dd_options=[['value'=>3,'label'=>'Scope 3'],['value'=>1,'label'=>'Scope 1'],['value'=>2,'label'=>'Scope 2']];
                                    $dd_selected=3;$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือก Scope';$dd_style='';include __DIR__.'/../components/dropdown.php'; ?></div>
                            <div class="fld"><label>ค่า EF (kgCO₂e/หน่วย)</label>
                                <?php $ti_id='coAd';$ti_name='ad';$ti_type='number';$ti_required=true;$ti_step='0.0001';$ti_min=0;$ti_placeholder='0.0000';include __DIR__.'/../components/text_input.php'; ?></div>
                        </div>
                        <div style="text-align:right;margin-top:12px;"><?php $btn_label='＋ เพิ่มหัวข้อ';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                </div>
                <?php endif; ?>

                <div class="card">
                    <h2>กรอกค่าเฉลี่ย (<?= htmlspecialchars($group) ?>)</h2>
                    <?php if (empty($rows)): ?>
                        <p class="muted">ยังไม่มีหัวข้อในกลุ่มนี้ — เพิ่มด้านบน</p>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_survey"><input type="hidden" name="tab" value="<?= $tab ?>">
                        <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="group" value="<?= htmlspecialchars($group,ENT_QUOTES) ?>"><?php if($is_admin):?><input type="hidden" name="affil" value="<?= $sel_affil ?>"><?php endif;?>
                        <table class="t">
                            <thead><tr><th>คำถาม</th><th>Scope</th><th class="num">จำนวนผู้ตอบ</th><th class="num">เฉลี่ย/คน</th><th>หน่วย</th><th class="num">tCO₂e</th><?php if($is_admin):?><th>จัดการ</th><?php endif;?></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['label']) ?></td>
                                    <td><span class="sdot s<?= (int)$r['scope'] ?>">S<?= (int)$r['scope'] ?></span></td>
                                    <td class="num"><input class="inp" type="number" min="0" step="1" name="resp[<?= (int)$r['qiid'] ?>]" value="<?= (int)$r['respondents']?:'' ?>" placeholder="0"></td>
                                    <td class="num"><input class="inp" type="number" min="0" step="0.0001" name="avg[<?= (int)$r['qiid'] ?>]" value="<?= (float)$r['avg_value']!=0?htmlspecialchars(rtrim(rtrim(number_format((float)$r['avg_value'],4,'.',''),'0'),'.')):'' ?>" placeholder="0"></td>
                                    <td><?= htmlspecialchars($r['unit']) ?></td>
                                    <td class="num"><?= number_format((float)$r['emission'],4) ?></td>
                                    <?php if($is_admin):?>
                                    <td class="num" style="white-space:nowrap;">
                                        <button type="button" class="icobtn edit" title="แก้ไขหัวข้อ"
                                            data-qiid="<?= (int)$r['qiid'] ?>"
                                            data-label="<?= htmlspecialchars($r['label'],ENT_QUOTES) ?>"
                                            data-unit="<?= htmlspecialchars($r['unit'],ENT_QUOTES) ?>"
                                            data-scope="<?= (int)$r['scope'] ?>"
                                            data-ad="<?= htmlspecialchars((string)$r['AD'],ENT_QUOTES) ?>"
                                            onclick="topicEdit(this)">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                                        </button>
                                        <button type="button" class="icobtn del" title="ลบหัวข้อ" onclick="topicDelete(<?= (int)$r['qiid'] ?>)">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                    </td>
                                    <?php endif;?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="foot"><?php $btn_label='บันทึก';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                    <?php if ($is_admin): ?>
                        <?php foreach ($rows as $r): ?>
                        <form method="POST" id="delTopic<?= (int)$r['qiid'] ?>" style="display:none;">
                            <input type="hidden" name="action" value="delete_topic"><input type="hidden" name="tab" value="<?= $tab ?>">
                            <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="affil" value="<?= $sel_affil ?>"><input type="hidden" name="group" value="<?= htmlspecialchars($group,ENT_QUOTES) ?>">
                            <input type="hidden" name="qitem_id" value="<?= (int)$r['qiid'] ?>">
                        </form>
                        <?php endforeach; ?>
                        <!-- Modal แก้ไขหัวข้อ -->
                        <div id="topicEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
                            <div style="background:#fff;border-radius:16px;padding:22px;max-width:520px;width:92%;">
                                <h2 style="margin:0 0 14px;font-size:1.1rem;font-weight:800;">✏️ แก้ไขหัวข้อ</h2>
                                <form method="POST">
                                    <input type="hidden" name="action" value="edit_topic"><input type="hidden" name="tab" value="<?= $tab ?>">
                                    <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="affil" value="<?= $sel_affil ?>"><input type="hidden" name="group" value="<?= htmlspecialchars($group,ENT_QUOTES) ?>">
                                    <input type="hidden" name="qitem_id" id="te_qiid">
                                    <div class="fld" style="margin-bottom:10px;"><label>คำถาม</label><input class="ti-input" style="width:100%;" name="label" id="te_label" required></div>
                                    <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                                        <div class="fld" style="flex:1;min-width:120px;"><label>หน่วย</label><input class="ti-input" style="width:100%;" name="unit" id="te_unit" required></div>
                                        <div class="fld" style="width:130px;"><label>Scope</label>
                                            <select class="ti-input" style="width:100%;" name="scope" id="te_scope"><option value="1">Scope 1</option><option value="2">Scope 2</option><option value="3">Scope 3</option></select></div>
                                        <div class="fld" style="width:150px;"><label>ค่า EF</label><input class="ti-input" style="width:100%;" type="number" step="0.0001" min="0" name="ad" id="te_ad" required></div>
                                    </div>
                                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
                                        <button type="button" class="del" onclick="document.getElementById('topicEditModal').style.display='none'">ยกเลิก</button>
                                        <?php $btn_label='บันทึกการแก้ไข';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <script>
                            function topicEdit(b){
                                document.getElementById('te_qiid').value=b.dataset.qiid;
                                document.getElementById('te_label').value=b.dataset.label;
                                document.getElementById('te_unit').value=b.dataset.unit;
                                document.getElementById('te_scope').value=b.dataset.scope;
                                document.getElementById('te_ad').value=b.dataset.ad;
                                var m=document.getElementById('topicEditModal'); m.style.display='flex';
                            }
                            function topicDelete(qiid){ if(confirm('ลบหัวข้อนี้?')) document.getElementById('delTopic'+qiid).submit(); }
                            document.getElementById('topicEditModal')?.addEventListener('click',function(e){ if(e.target===this) this.style.display='none'; });
                        </script>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php else: /* ===== แท็บ กิจกรรม ===== */ ?>
                <div class="card">
                    <h2>＋ เพิ่มกิจกรรม</h2>
                    <form method="POST" class="frow">
                        <input type="hidden" name="action" value="add_event"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                        <?php if($is_admin):?><input type="hidden" name="org" value="<?= htmlspecialchars($org_raw,ENT_QUOTES) ?>"><?php endif;?>
                        <div class="fld" style="flex:2;"><label>ชื่อกิจกรรม (ผู้จัด: <?= htmlspecialchars($is_admin ? ($org_name ?? ($affils ? (array_column($affils,'affiliation_item','id')[$org_affil] ?? '') : '')) : ($_SESSION['affiliation_name'] ?? '')) ?>)</label>
                            <?php $ti_id='evName';$ti_name='name';$ti_required=true;$ti_placeholder='เช่น งานรับน้อง 2569';include __DIR__.'/../components/text_input.php'; ?></div>
                        <div class="fld" style="max-width:170px;"><label>วันที่จัด</label><input type="date" name="event_date" class="ti-input" style="width:100%;"></div>
                        <div><?php $btn_label='เพิ่มกิจกรรม';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                </div>
                <div class="card">
                    <h2>กิจกรรมทั้งหมด (<?= count($events) ?>)</h2>
                    <?php if (empty($events)): ?><p class="muted">ยังไม่มีกิจกรรมในปีนี้</p><?php else: ?>
                    <table class="t">
                        <thead><tr><th>กิจกรรม</th><?php if($is_admin):?><th>ผู้จัด</th><?php endif;?><th>วันที่</th><th class="num">รายการ</th><th class="num">tCO₂e</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $e): ?>
                            <tr class="<?= (int)$e['id']===$sel_event?'sel':'' ?>">
                                <td><a class="linkish" href="<?= $qs(['event'=>$e['id']]) ?>"><?= htmlspecialchars($e['name']) ?></a></td>
                                <?php if($is_admin):?><td><?= htmlspecialchars($e['org_label']) ?></td><?php endif;?>
                                <td><?= htmlspecialchars($e['event_date']?:'—') ?></td>
                                <td class="num"><?= (int)$e['item_count'] ?></td>
                                <td class="num"><?= number_format((float)$e['tco2e'],3) ?></td>
                                <td class="num"><form method="POST" onsubmit="return confirm('ลบกิจกรรมนี้?')" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_event"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><?php if($is_admin):?><input type="hidden" name="org" value="<?= htmlspecialchars($org_raw,ENT_QUOTES) ?>"><?php endif;?><input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                    <button class="del">ลบ</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <?php if ($curEvent): ?>
                <div class="card">
                    <h2>รายการปล่อยคาร์บอน — <?= htmlspecialchars($curEvent['name']) ?></h2>
                    <?php if (empty($ef_items)): ?>
                        <p class="muted">ปีนี้ยังไม่มีรายการ EF — ให้แอดมินเพิ่มที่หน้า UP Net Zero ก่อน</p>
                    <?php else: ?>
                    <form method="POST" class="frow" style="margin-bottom:14px;">
                        <input type="hidden" name="action" value="add_item"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><?php if($is_admin):?><input type="hidden" name="org" value="<?= htmlspecialchars($org_raw,ENT_QUOTES) ?>"><?php endif;?><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>">
                        <div class="fld" style="flex:2;"><label>รายการ (EF)</label>
                            <?php $dd_id='efPick';$dd_name='admin_item_id';$dd_options=array_map(fn($x)=>['value'=>$x['id'],'label'=>'[S'.$x['scope'].'] '.$x['name_tiem'].' ('.$x['unit'].')'],$ef_items);
                                $dd_selected='';$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือกรายการ';$dd_style='';include __DIR__.'/../components/dropdown.php'; ?></div>
                        <div class="fld" style="max-width:160px;"><label>ปริมาณ (Vol)</label>
                            <?php $ti_id='efVol';$ti_name='vol';$ti_type='number';$ti_step='0.0001';$ti_min=0;$ti_required=true;$ti_placeholder='0';include __DIR__.'/../components/text_input.php'; ?></div>
                        <div><?php $btn_label='เพิ่มรายการ';$btn_variant='success';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                    <?php endif; ?>
                    <?php if (!empty($curItems)): ?>
                    <table class="t">
                        <thead><tr><th>Scope</th><th>รายการ</th><th class="num">ปริมาณ</th><th>หน่วย</th><th class="num">tCO₂e</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($curItems as $it): ?>
                            <tr>
                                <td><span class="sdot s<?= (int)$it['scope'] ?>">S<?= (int)$it['scope'] ?></span></td>
                                <td><?= htmlspecialchars($it['name_tiem']) ?></td>
                                <td class="num"><?= number_format((float)$it['Vol'],4) ?></td>
                                <td><?= htmlspecialchars($it['unit']) ?></td>
                                <td class="num"><?= number_format((float)$it['emission'],4) ?></td>
                                <td class="num"><form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_item"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><?php if($is_admin):?><input type="hidden" name="org" value="<?= htmlspecialchars($org_raw,ENT_QUOTES) ?>"><?php endif;?><input type="hidden" name="item_id" value="<?= $it['id'] ?>"><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>">
                                    <button class="del">ลบ</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?><p class="muted">ยังไม่มีรายการในกิจกรรมนี้</p><?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <script>
            // ต่อท้าย URL: survey → &group=..., event(admin) → &org=...
            var navSuffix = <?= $is_survey ? "'&group='+encodeURIComponent(".json_encode($group).")" : ($is_admin && $tab==='event' ? "'&org='+encodeURIComponent(".json_encode($org_raw).")" : "''") ?>;
            document.getElementById('coYear')?.addEventListener('dd:change', function(e){
                if (String(e.detail.value) === '<?= $selected_year ?>') return; // อยู่ปีเดิม ไม่ต้องโหลด
                location.href='collect.php?tab=<?= $tab ?>&year='+e.detail.value+navSuffix;
            });
            <?php if ($is_survey): ?>
            document.getElementById('coGroup')?.addEventListener('dd:change', function(e){
                var w=document.getElementById('newGroupWrap');
                if (e.detail.value === '__new__'){ // เลือก "กลุ่มอื่น" → โชว์ช่องพิมพ์
                    w.style.display='inline-flex';
                    document.getElementById('newGroupInput').focus();
                    return;
                }
                w.style.display='none';
                if (e.detail.value === <?= json_encode($group) ?>) return; // กลุ่มเดิม ไม่ต้องโหลด
                location.href='collect.php?tab=survey&year=<?= $selected_year ?>&group='+encodeURIComponent(e.detail.value);
            });
            window.goNewGroup = function(){
                var g = (document.getElementById('newGroupInput').value||'').trim();
                if (g) location.href='collect.php?tab=survey&year=<?= $selected_year ?>&group='+encodeURIComponent(g);
            };
            <?php endif; ?>
            <?php if ($is_admin && $tab==='event'): ?>
            document.getElementById('coOrg')?.addEventListener('dd:change', function(e){
                var w=document.getElementById('newOrgWrap');
                if (e.detail.value === '__new__'){ w.style.display='inline-flex'; document.getElementById('newOrgInput').focus(); return; }
                w.style.display='none';
                if (e.detail.value === <?= json_encode($org_raw) ?>) return; // ผู้จัดเดิม
                location.href='collect.php?tab=event&year=<?= $selected_year ?>&org='+encodeURIComponent(e.detail.value);
            });
            window.goNewOrg = function(){
                var v=(document.getElementById('newOrgInput').value||'').trim();
                if (v) location.href='collect.php?tab=event&year=<?= $selected_year ?>&org='+encodeURIComponent('custom:'+v);
            };
            <?php endif; ?>
        </script>
    </main>
</body>
</html>
