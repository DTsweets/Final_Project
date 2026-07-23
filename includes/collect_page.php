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
    // ลบเฉพาะแถว survey ของกลุ่มนี้ ของคณะนี้ (ระบุกลุ่มผ่าน questionnaire.audience + affiliation)
    $pdo->prepare("DELETE ui FROM user_item ui
        JOIN questionnaire_item qi ON qi.admin_item_id = ui.admin_item_id
        JOIN questionnaire q ON q.id = qi.questionnaire_id
        WHERE ui.source='survey' AND ui.affiliation_id=? AND ui.year_id=? AND q.audience=? AND q.affiliation_id=?")
        ->execute([$affil,$year,$group,$affil]);
    $pdo->prepare("INSERT INTO user_item (admin_item_id,affiliation_id,year_id,Vol,create_year,source)
        SELECT ss.admin_item_id, ss.affiliation_id, ss.year_id, SUM(ss.respondents*ss.avg_value), CURDATE(), 'survey'
        FROM survey_summary ss JOIN questionnaire_item qi ON qi.id=ss.questionnaire_item_id JOIN questionnaire q ON q.id=qi.questionnaire_id
        WHERE ss.affiliation_id=? AND ss.year_id=? AND q.audience=? AND q.affiliation_id=? GROUP BY ss.admin_item_id")
        ->execute([$affil,$year,$group,$affil]);
}
/** event: รวม SUM(Vol) ของทุกกิจกรรมเข้า user_item(source=event) รายคณะ */
function reaggregate_events(PDO $pdo, int $affil, int $year): void {
    $pdo->prepare("DELETE FROM user_item WHERE source='event' AND affiliation_id=? AND year_id=?")->execute([$affil,$year]);
    $pdo->prepare("INSERT INTO user_item (admin_item_id,affiliation_id,year_id,Vol,create_year,source)
        SELECT ei.admin_item_id, e.affiliation_id, e.year_id, SUM(ei.Vol), CURDATE(), 'event'
        FROM event e JOIN event_item ei ON ei.event_id=e.id
        WHERE e.affiliation_id=? AND e.year_id=? GROUP BY ei.admin_item_id")->execute([$affil,$year]);
}
/** ensure 1 questionnaire ต่อ (ปี, กลุ่ม, คณะเจ้าของ) — คืน id */
function ensure_questionnaire(PDO $pdo, int $year, string $group, int $affil, ?int $created_by = null): int {
    $s = $pdo->prepare("SELECT id FROM questionnaire WHERE year_id=? AND audience=? AND affiliation_id=?"); $s->execute([$year,$group,$affil]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO questionnaire (year_id,affiliation_id,audience,created_by) VALUES (?,?,?,?)")
        ->execute([$year,$affil,$group,$created_by]);
    return (int)$pdo->lastInsertId();
}

// แท็บ: แบบสอบถาม + กิจกรรม — เปิดให้ทั้ง admin และ officer (officer เห็น/แก้เฉพาะคณะตัวเอง)
$TABS = ['survey' => 'แบบสอบถาม', 'event' => 'กิจกรรม'];
$DEFAULT_GROUPS = ['นักศึกษา', 'บุคลากร'];   // กลุ่มมาตรฐาน (เพิ่ม "อื่นๆ" พิมพ์เองได้)
$page_title = 'กรอกข้อมูล';
$page_title2 = 'แบบสอบถาม & กิจกรรม';
$CENTRAL_AFFIL = 1; // ศูนย์สิ่งแวดล้อม = ค่าเริ่มต้นของ "ผู้จัดทำ" สำหรับ admin
$years  = $pdo->query("SELECT id AS year_id, year FROM admin_year ORDER BY year DESC")->fetchAll();
$affils = $is_admin ? $pdo->query("SELECT id, affiliation_item FROM affiliation_id ORDER BY id")->fetchAll() : [];
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : ($years[0]['year_id'] ?? 0);
$tab = (isset($_GET['tab']) && isset($TABS[$_GET['tab']])) ? $_GET['tab'] : array_key_first($TABS);
$is_survey = $tab === 'survey';
$group = trim((string)($_GET['group'] ?? ''));   // แบบสอบถามที่เลือก ('' = ยังไม่เลือก แสดงเฉพาะลิสต์)
// ผู้จัดทำแบบสอบถาม (เจ้าของคณะ): officer = คณะตัวเอง; admin = เลือกผ่าน ?maker= (ค่าเริ่มต้น = ศูนย์ฯ)
$survey_affil = $is_admin ? (int)($_GET['maker'] ?? $CENTRAL_AFFIL) : (int)$lock_affil;
if ($is_survey)      $sel_affil = $survey_affil;                       // แบบสอบถาม = ตามคณะเจ้าของ
elseif ($is_admin)   $sel_affil = isset($_GET['affil']) ? (int) $_GET['affil'] : ($affils[0]['id'] ?? 0);
else                 $sel_affil = (int) $lock_affil;

/**
 * แท็บกิจกรรม: ผู้จัด (organizer) — เลือกตอน "เพิ่มกิจกรรม" รายกิจกรรม (ไม่กรองในลิสต์)
 *   ค่าที่ส่ง: "aff:<id>" (คณะในระบบ) | "custom:<ชื่อ>" (อื่นๆ → หน่วยกลาง)
 *   ลิสต์ "กิจกรรมทั้งหมด" แสดงทุกผู้จัดของปีนั้น
 */
if ($tab === 'event' && !$is_admin) $sel_affil = (int)$lock_affil;
$sel_event = isset($_GET['event']) ? (int) $_GET['event'] : 0;
$flash = ''; $flash_t = 'success';
$qs = function($extra = []) use ($selected_year, $tab, $is_admin, $survey_affil) {
    $p = ['year' => $selected_year, 'tab' => $tab];
    if ($is_admin && $tab === 'survey') $p['maker'] = $survey_affil;   // admin: คงผู้จัดทำที่เลือก
    return 'collect.php?' . http_build_query(array_merge($p, $extra));
};

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ptab = (isset($_POST['tab']) && isset($TABS[$_POST['tab']])) ? $_POST['tab'] : $tab;
    $pgroup = trim((string)($_POST['group'] ?? '')) ?: 'นักศึกษา';
    $paffil = $is_admin ? (int)($_POST['affil'] ?? 0) : (int)$lock_affil;
    // ผู้จัดทำแบบสอบถาม (คณะเจ้าของ) สำหรับ action: officer = คณะตัวเอง; admin = จาก maker
    $smaker = $is_admin ? (int)($_POST['maker'] ?? $CENTRAL_AFFIL) : (int)$lock_affil;
    $pyear = (int)($_POST['year_id'] ?? $selected_year);
    // event: parse ผู้จัด (org) → $porg_affil / $porg_name
    $porg_raw = trim((string)($_POST['org'] ?? ''));
    $porg_affil = (int)$lock_affil; $porg_name = null;
    if ($is_admin) {
        if (preg_match('/^aff:(\d+)$/', $porg_raw, $m)) { $porg_affil=(int)$m[1]; $porg_name=null; }
        elseif (strpos($porg_raw,'custom:')===0) { $porg_name=trim(substr($porg_raw,7)); $porg_affil=$CENTRAL_AFFIL; }
    }
    $redir = "collect.php?year=$pyear&tab=$ptab"
           . ($ptab==='survey' ? ($is_admin ? "&maker=$smaker" : '') . "&group=".urlencode($pgroup) : '');
    try {
        // ---- survey: เพิ่มแบบสอบถาม (สร้าง questionnaire ใหม่ ของคณะเจ้าของ = $smaker) ----
        if ($action === 'add_questionnaire') {
            $qname = trim($_POST['q_name'] ?? '');
            if ($qname === '') throw new Exception('กรุณาระบุชื่อแบบสอบถาม');
            ensure_questionnaire($pdo, $pyear, $qname, $smaker, $_SESSION['user_id'] ?? null);
            header("Location: collect.php?year=$pyear&tab=survey".($is_admin?"&maker=$smaker":'')."&group=" . urlencode($qname) . "&msg=" . urlencode('เพิ่มแบบสอบถามแล้ว')); exit;
        }
        // ---- survey: ลบแบบสอบถาม (ลบทั้ง questionnaire + รายการ + ค่าเฉลี่ย) ของคณะเจ้าของ ----
        if ($action === 'delete_questionnaire') {
            $qname = trim($_POST['q_name'] ?? '');
            if ($qname === '') throw new Exception('ไม่พบแบบสอบถาม');
            $qsel = $pdo->prepare("SELECT id FROM questionnaire WHERE year_id=? AND audience=? AND affiliation_id=?");
            $qsel->execute([$pyear, $qname, $smaker]);
            $qid = (int) $qsel->fetchColumn();
            if ($qid) {
                $aiids = $pdo->query("SELECT admin_item_id FROM questionnaire_item WHERE questionnaire_id=$qid")->fetchAll(PDO::FETCH_COLUMN);
                $pdo->beginTransaction();
                $pdo->prepare("DELETE ss FROM survey_summary ss JOIN questionnaire_item qi ON qi.id=ss.questionnaire_item_id WHERE qi.questionnaire_id=?")->execute([$qid]);
                $pdo->prepare("DELETE FROM questionnaire_item WHERE questionnaire_id=?")->execute([$qid]);
                foreach ($aiids as $ai) $pdo->prepare("DELETE FROM admin_item WHERE id=? AND data_source='survey'")->execute([$ai]);
                $pdo->prepare("DELETE FROM questionnaire WHERE id=?")->execute([$qid]);
                $pdo->commit();
                reaggregate_survey($pdo, $smaker, $pyear, $qname);
            }
            header("Location: collect.php?year=$pyear&tab=survey".($is_admin?"&maker=$smaker":'')."&msg=" . urlencode('ลบแบบสอบถามแล้ว')); exit;
        }
        // ---- survey: แก้ไขชื่อแบบสอบถาม (rename audience) ของคณะเจ้าของ ----
        if ($action === 'edit_questionnaire') {
            $old = trim($_POST['q_old'] ?? ''); $new = trim($_POST['q_name'] ?? '');
            if ($new === '') throw new Exception('กรุณาระบุชื่อแบบสอบถาม');
            if ($old === '') throw new Exception('ไม่พบแบบสอบถามเดิม');
            if ($new !== $old) {
                // กันชื่อซ้ำกับแบบสอบถามอื่นในปีเดียวกัน "ของคณะเดียวกัน"
                $dup = $pdo->prepare("SELECT COUNT(*) FROM questionnaire WHERE year_id=? AND audience=? AND affiliation_id=?");
                $dup->execute([$pyear, $new, $smaker]);
                if ($dup->fetchColumn()) throw new Exception('มีแบบสอบถามชื่อนี้อยู่แล้วในปีนี้');
                $pdo->prepare("UPDATE questionnaire SET audience=? WHERE year_id=? AND audience=? AND affiliation_id=?")->execute([$new, $pyear, $old, $smaker]);
            }
            header("Location: collect.php?year=$pyear&tab=survey".($is_admin?"&maker=$smaker":'')."&group=" . urlencode($new) . "&msg=" . urlencode('แก้ไขชื่อแบบสอบถามแล้ว')); exit;
        }
        // ---- survey: เพิ่มหัวข้อ (หัวข้อผูกคณะเจ้าของ $smaker) ----
        if ($action === 'add_topic') {
            $label=trim($_POST['label']); $unit=trim($_POST['unit']);
            $scope=(int)$_POST['scope']; $ad=(float)$_POST['ad'];
            if ($pgroup==='') throw new Exception('กรุณาระบุกลุ่ม');
            $agid=(int)$pdo->query("SELECT id FROM admin_g WHERE scope=$scope ORDER BY id LIMIT 1")->fetchColumn();
            if (!$agid) throw new Exception("ไม่พบกลุ่มขอบเขต Scope $scope");
            $qid = ensure_questionnaire($pdo,$pyear,$pgroup,$smaker,$_SESSION['user_id']??null);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO admin_item (year_id,scope,name_tiem,unit,AD,data_source,affiliation_id) VALUES (?,?,?,?,?,'survey',?)")
                ->execute([$pyear,$agid,$label,$unit,$ad,$smaker]);
            $aiid=(int)$pdo->lastInsertId();
            $ord=(int)$pdo->query("SELECT COALESCE(MAX(order_num),0)+1 FROM questionnaire_item WHERE questionnaire_id=$qid")->fetchColumn();
            // ชื่อหัวข้อเก็บที่ admin_item.name_tiem อย่างเดียว (questionnaire_item เป็น link table)
            $pdo->prepare("INSERT INTO questionnaire_item (questionnaire_id,admin_item_id,order_num) VALUES (?,?,?)")
                ->execute([$qid,$aiid,$ord]);
            $pdo->commit();
            header("Location: $redir&msg=".urlencode('เพิ่มหัวข้อแล้ว')); exit;
        }
        if ($action === 'edit_topic') {
            $qiid=(int)$_POST['qitem_id'];
            $label=trim($_POST['label']); $unit=trim($_POST['unit']);
            $scope=(int)$_POST['scope']; $ad=(float)$_POST['ad'];
            // สิทธิ์: แก้ได้เฉพาะหัวข้อของแบบสอบถามคณะเจ้าของ (ตรวจผ่าน qi → q.affiliation_id)
            $chk=$pdo->prepare("SELECT qi.admin_item_id FROM questionnaire_item qi JOIN questionnaire q ON q.id=qi.questionnaire_id WHERE qi.id=? AND q.affiliation_id=?");
            $chk->execute([$qiid,$smaker]); $aiid=(int)$chk->fetchColumn();
            $agid=(int)$pdo->query("SELECT id FROM admin_g WHERE scope=$scope ORDER BY id LIMIT 1")->fetchColumn();
            if (!$aiid || !$agid) throw new Exception('ไม่พบหัวข้อ/ขอบเขต หรือไม่มีสิทธิ์');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE admin_item SET scope=?, name_tiem=?, unit=?, AD=? WHERE id=? AND data_source='survey'")
                ->execute([$agid,$label,$unit,$ad,$aiid]);
            $pdo->commit();
            // EF เปลี่ยน → คำนวณยอดของกลุ่มนี้ใหม่ (ของคณะเจ้าของ)
            reaggregate_survey($pdo,$smaker,$pyear,$pgroup);
            header("Location: $redir&msg=".urlencode('แก้ไขหัวข้อแล้ว')); exit;
        }
        if ($action === 'delete_topic') {
            $qiid=(int)$_POST['qitem_id'];
            // สิทธิ์: ลบได้เฉพาะหัวข้อของแบบสอบถามคณะเจ้าของ
            $chk=$pdo->prepare("SELECT qi.admin_item_id FROM questionnaire_item qi JOIN questionnaire q ON q.id=qi.questionnaire_id WHERE qi.id=? AND q.affiliation_id=?");
            $chk->execute([$qiid,$smaker]); $aiid=(int)$chk->fetchColumn();
            if (!$aiid) throw new Exception('ไม่พบหัวข้อ หรือไม่มีสิทธิ์');
            $pdo->prepare("DELETE FROM questionnaire_item WHERE id=?")->execute([$qiid]);
            $pdo->prepare("DELETE FROM admin_item WHERE id=? AND data_source='survey'")->execute([$aiid]);
            reaggregate_survey($pdo,$smaker,$pyear,$pgroup);
            header("Location: $redir&msg=".urlencode('ลบหัวข้อแล้ว')); exit;
        }
        // ---- survey: บันทึกค่าเฉลี่ย (ของคณะเจ้าของ $smaker) ----
        if ($action === 'save_survey') {
            $paffil = $smaker;
            $map=$pdo->prepare("SELECT qi.id, qi.admin_item_id FROM questionnaire_item qi JOIN questionnaire q ON q.id=qi.questionnaire_id WHERE q.year_id=? AND q.audience=? AND q.affiliation_id=?");
            $map->execute([$pyear,$pgroup,$smaker]); $map=$map->fetchAll(PDO::FETCH_KEY_PAIR);
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
            $name=trim($_POST['name']); $date=$_POST['event_date']?:null; $end=$_POST['event_end_date']?:null;
            $kind=($_POST['kind'] ?? 'emission')==='removal' ? 'removal' : 'emission'; // ประเภทกิจกรรม
            $affil=$porg_affil; $oname=$porg_name;   // ผู้จัดจาก selector ด้านบน (คณะ หรือ อื่นๆ→หน่วยกลาง)
            if ($name==='') throw new Exception('กรอกชื่อกิจกรรม');
            if (!$affil && $oname===null) throw new Exception('เลือกผู้จัด');
            if ($end!==null && $date!==null && $end < $date) throw new Exception('วันสิ้นสุดต้องไม่น้อยกว่าวันที่เริ่ม');
            if ($end!==null && $date===null) throw new Exception('กรุณากรอกวันที่เริ่มก่อนวันสิ้นสุด');
            $pdo->prepare("INSERT INTO event (name,kind,affiliation_id,organizer_name,year_id,event_date,event_end_date,created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$name,$kind,$affil,$oname,$pyear,$date,$end,$_SESSION['user_id']??null]);
            $eid=(int)$pdo->lastInsertId();
            header("Location: $redir&event=$eid&msg=".urlencode('เพิ่มกิจกรรมแล้ว')); exit;
        }
        if ($action === 'edit_event') {
            $eid=(int)$_POST['event_id'];
            $name=trim($_POST['name']); $date=$_POST['event_date']?:null; $end=$_POST['event_end_date']?:null;
            $row=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if (!$row) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            if ($name==='') throw new Exception('กรอกชื่อกิจกรรม');
            if ($end!==null && $date!==null && $end < $date) throw new Exception('วันสิ้นสุดต้องไม่น้อยกว่าวันที่เริ่ม');
            if ($end!==null && $date===null) throw new Exception('กรุณากรอกวันที่เริ่มก่อนวันสิ้นสุด');
            $pdo->prepare("UPDATE event SET name=?, event_date=?, event_end_date=? WHERE id=?")
                ->execute([$name,$date,$end,$eid]);
            header("Location: $redir&event=$eid&msg=".urlencode('แก้ไขกิจกรรมแล้ว')); exit;
        }
        if ($action === 'delete_event') {
            $eid=(int)$_POST['event_id'];
            $row=$pdo->prepare("SELECT affiliation_id,year_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if ($row) {
                if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM event_item WHERE event_id=?")->execute([$eid]);   // ค่าปริมาณของกิจกรรม
                $pdo->prepare("DELETE FROM event WHERE id=?")->execute([$eid]);
                reaggregate_events($pdo,(int)$row['affiliation_id'],(int)$row['year_id']);     // เคลียร์ยอดใน user_item ก่อน
                $pdo->prepare("DELETE FROM admin_item WHERE data_source='event' AND event_id=?")->execute([$eid]); // รายการ EF ของกิจกรรม
                $pdo->commit();
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
        // ---- event: นิยามรายการ EF เอง (ชื่อ/หน่วย/scope/EF) ราย "ปี" ใช้ร่วมทุกกิจกรรม ----
        if ($action === 'add_event_topic') {
            $eid=(int)$_POST['event_id'];
            $label=trim($_POST['label']); $unit=trim($_POST['unit']);
            $scope=(int)$_POST['scope']; $ad=(float)$_POST['ad'];
            if ($label==='') throw new Exception('กรอกชื่อรายการ');
            $agid=(int)$pdo->query("SELECT id FROM admin_g WHERE scope=$scope ORDER BY id LIMIT 1")->fetchColumn();
            if (!$agid) throw new Exception("ไม่พบขอบเขต Scope $scope");
            if (!$eid) throw new Exception('ไม่พบกิจกรรม');
            // สิทธิ์: officer เพิ่มรายการได้เฉพาะกิจกรรมของคณะตัวเอง
            $evown=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $evown->execute([$eid]); $evown=$evown->fetch();
            if (!$evown) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$evown['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $pdo->prepare("INSERT INTO admin_item (year_id,scope,name_tiem,unit,AD,data_source,event_id) VALUES (?,?,?,?,?,'event',?)")
                ->execute([$pyear,$agid,$label,$unit,$ad,$eid]);
            header("Location: $redir&event=$eid&msg=".urlencode('เพิ่มรายการแล้ว')); exit;
        }
        if ($action === 'edit_event_topic') {
            $aiid=(int)$_POST['admin_item_id']; $eid=(int)$_POST['event_id'];
            $label=trim($_POST['label']); $unit=trim($_POST['unit']);
            $scope=(int)$_POST['scope']; $ad=(float)$_POST['ad'];
            if ($label==='') throw new Exception('กรอกชื่อรายการ');
            $agid=(int)$pdo->query("SELECT id FROM admin_g WHERE scope=$scope ORDER BY id LIMIT 1")->fetchColumn();
            if (!$agid) throw new Exception("ไม่พบขอบเขต Scope $scope");
            // สิทธิ์: officer แก้ไขได้เฉพาะรายการของกิจกรรมคณะตัวเอง (ตรวจจาก admin_item → event จริง)
            $own=$pdo->prepare("SELECT e.affiliation_id FROM admin_item ai JOIN event e ON e.id=ai.event_id WHERE ai.id=? AND ai.data_source='event'");
            $own->execute([$aiid]); $own=$own->fetch();
            if (!$own) throw new Exception('ไม่พบรายการ');
            if (!$is_admin && (int)$own['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            // Vol ใน user_item ไม่เปลี่ยน (AD/scope อ่านสดตอนแสดงผล) จึงไม่ต้อง reaggregate
            $pdo->prepare("UPDATE admin_item SET scope=?, name_tiem=?, unit=?, AD=? WHERE id=? AND data_source='event'")
                ->execute([$agid,$label,$unit,$ad,$aiid]);
            header("Location: $redir&event=$eid&msg=".urlencode('แก้ไขรายการแล้ว')); exit;
        }
        if ($action === 'delete_event_topic') {
            $aiid=(int)$_POST['admin_item_id']; $eid=(int)$_POST['event_id'];
            // สิทธิ์: officer ลบได้เฉพาะรายการของกิจกรรมคณะตัวเอง (ตรวจจาก admin_item → event จริง)
            $own=$pdo->prepare("SELECT e.affiliation_id FROM admin_item ai JOIN event e ON e.id=ai.event_id WHERE ai.id=? AND ai.data_source='event'");
            $own->execute([$aiid]); $own=$own->fetch();
            if (!$own) throw new Exception('ไม่พบรายการ');
            if (!$is_admin && (int)$own['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            // เก็บ (affil,year) ที่กระทบก่อนลบ เพื่อ reaggregate ให้ครบ
            $aff=$pdo->prepare("SELECT DISTINCT e.affiliation_id, e.year_id FROM event e JOIN event_item ei ON ei.event_id=e.id WHERE ei.admin_item_id=?");
            $aff->execute([$aiid]); $affs=$aff->fetchAll();
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM event_item WHERE admin_item_id=?")->execute([$aiid]);
            $pdo->prepare("DELETE FROM admin_item WHERE id=? AND data_source='event'")->execute([$aiid]);
            foreach ($affs as $a) reaggregate_events($pdo,(int)$a['affiliation_id'],(int)$a['year_id']);
            $pdo->commit();
            header("Location: $redir&event=$eid&msg=".urlencode('ลบรายการแล้ว')); exit;
        }
        // ---- event: บันทึกทั้งตาราง (กรอก Vol ราย EF แล้วบันทึกทีเดียว เหมือนแบบสอบถาม) ----
        if ($action === 'save_event_items') {
            $eid=(int)$_POST['event_id'];
            $row=$pdo->prepare("SELECT affiliation_id,year_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if (!$row) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            // รับเฉพาะ EF ที่เป็นของ "กิจกรรมนี้" จริง (กัน admin_item_id ปลอม/ข้ามกิจกรรม)
            $valid=$pdo->prepare("SELECT id FROM admin_item WHERE data_source='event' AND event_id=?");
            $valid->execute([$eid]); $valid=array_map('intval',$valid->fetchAll(PDO::FETCH_COLUMN));
            $vols=$_POST['vol'] ?? [];
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM event_item WHERE event_id=?")->execute([$eid]);
            $ins=$pdo->prepare("INSERT INTO event_item (event_id,admin_item_id,Vol) VALUES (?,?,?)");
            foreach ($vols as $aiid=>$v){ $aiid=(int)$aiid; $v=(float)$v;
                if ($v>0 && in_array($aiid,$valid,true)) $ins->execute([$eid,$aiid,$v]); }
            reaggregate_events($pdo,(int)$row['affiliation_id'],(int)$row['year_id']);
            $pdo->commit();
            header("Location: $redir&event=$eid&msg=".urlencode('บันทึกรายการแล้ว')); exit;
        }
        if ($action === 'delete_item') {
            $iid=(int)$_POST['item_id']; $eid=(int)$_POST['event_id'];
            $row=$pdo->prepare("SELECT affiliation_id,year_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if ($row && !$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $pdo->prepare("DELETE FROM event_item WHERE id=?")->execute([$iid]);
            if ($row) reaggregate_events($pdo,(int)$row['affiliation_id'],(int)$row['year_id']);
            header("Location: $redir&event=$eid&msg=".urlencode('ลบรายการแล้ว')); exit;
        }
        // ---- event: บันทึกปริมาณดูดกลับทั้งตาราง (เหมือนหน้า GHG Removal) → ไหลเข้ายอด Removal ----
        // factor มาจาก removal_item (กำหนดโดยศูนย์ฯ) คณะกรอกปริมาณต่อรายการแล้วบันทึกทีเดียว
        if ($action === 'save_event_removal') {
            $eid=(int)$_POST['event_id'];
            $row=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if (!$row) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $qty=$_POST['qty'] ?? [];
            // อัปเดตปริมาณเฉพาะรายการดูดกลับ "ของกิจกรรมนี้" (คีย์ด้วย removal_event_item.id)
            $upd=$pdo->prepare("UPDATE removal_event_item SET qty=? WHERE id=? AND event_id=?");
            $ids=$pdo->prepare("SELECT id FROM removal_event_item WHERE event_id=?"); $ids->execute([$eid]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $reid) {
                $upd->execute([max(0,(float)($qty[$reid] ?? 0)), $reid, $eid]);
            }
            header("Location: $redir&event=$eid&msg=".urlencode('บันทึกปริมาณดูดกลับแล้ว')); exit;
        }
        // ---- event: เพิ่มรายการดูดกลับ "ของกิจกรรมนี้" (เก็บ factor ในตัวเอง แยกจากหน้ากลาง) ----
        if ($action === 'add_removal_item') {
            $eid=(int)$_POST['event_id'];
            $row=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if (!$row) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $name=trim($_POST['name'] ?? ''); $unit=trim($_POST['unit'] ?? ''); $factor=max(0,(float)($_POST['factor'] ?? 0));
            if ($name==='') throw new Exception('กรุณาระบุชื่อรายการ');
            $pdo->prepare("INSERT INTO removal_event_item (event_id,name_tiem,unit,factor,qty) VALUES (?,?,?,?,0)")
                ->execute([$eid,$name,$unit,$factor]);
            header("Location: $redir&event=$eid&msg=".urlencode('เพิ่มรายการดูดกลับแล้ว')); exit;
        }
        // ---- event: แก้ไขรายการดูดกลับ (ชื่อ/หน่วย/factor) ของกิจกรรมนี้ ----
        if ($action === 'edit_event_removal') {
            $eid=(int)$_POST['event_id']; $reid=(int)$_POST['rei_id'];
            $row=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if (!$row) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $name=trim($_POST['name'] ?? ''); $unit=trim($_POST['unit'] ?? ''); $factor=max(0,(float)($_POST['factor'] ?? 0));
            if ($name==='') throw new Exception('กรุณาระบุชื่อรายการ');
            $pdo->prepare("UPDATE removal_event_item SET name_tiem=?, unit=?, factor=? WHERE id=? AND event_id=?")
                ->execute([$name,$unit,$factor,$reid,$eid]);
            header("Location: $redir&event=$eid&msg=".urlencode('แก้ไขรายการแล้ว')); exit;
        }
        // ---- event: ลบรายการดูดกลับของกิจกรรมนี้ ----
        if ($action === 'delete_event_removal') {
            $eid=(int)$_POST['event_id']; $reid=(int)$_POST['rei_id'];
            $row=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if ($row && !$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $pdo->prepare("DELETE FROM removal_event_item WHERE id=? AND event_id=?")->execute([$reid,$eid]);
            header("Location: $redir&event=$eid&msg=".urlencode('ลบรายการแล้ว')); exit;
        }
    } catch (Exception $e) { $flash='ผิดพลาด: '.$e->getMessage(); $flash_t='danger'; }
}
if (isset($_GET['msg'])) $flash = $_GET['msg'];

// ── โหลดข้อมูลตามแท็บ ──
$rows = []; $events = []; $curEvent = null; $curItems = []; $curVol = []; $ef_items = []; $year_total = 0; $group_options = [];
if ($is_survey) {
    // รายชื่อกลุ่มสำหรับ dropdown = กลุ่มมาตรฐาน ∪ กลุ่มที่มีอยู่แล้วในปีนี้ "ของคณะเจ้าของ" ∪ กลุ่มที่เลือกอยู่
    $existing = $pdo->prepare("SELECT DISTINCT audience FROM questionnaire WHERE year_id=? AND affiliation_id=?");
    $existing->execute([$selected_year, $survey_affil]);
    $group_options = array_values(array_unique(array_merge($DEFAULT_GROUPS, $existing->fetchAll(PDO::FETCH_COLUMN), [$group])));

    // รายการ "แบบสอบถามทั้งหมด" ของปีนี้ + จำนวนรายการ + ยอดรวม (tCO₂e ตามคณะเจ้าของแต่ละแถว)
    //   admin: เห็นทุกคณะ (มีคอลัมน์ผู้จัดทำ) · officer: เฉพาะคณะตัวเอง
    $qlist = $pdo->prepare("
        SELECT q.id AS qid, q.audience AS name, q.affiliation_id AS affid, a.affiliation_item AS maker_name,
               COUNT(DISTINCT qi.id) AS item_count,
               (SELECT COUNT(*) FROM evidence ev WHERE ev.entity_type='questionnaire' AND ev.entity_id=q.id) AS ev_count,
               COALESCE(SUM(COALESCE(ss.respondents,0)*COALESCE(ss.avg_value,0)*ai.AD)/1000, 0) AS tco2e
        FROM questionnaire q
        JOIN affiliation_id a ON a.id = q.affiliation_id
        LEFT JOIN questionnaire_item qi ON qi.questionnaire_id = q.id
        LEFT JOIN admin_item ai ON ai.id = qi.admin_item_id
        LEFT JOIN survey_summary ss ON ss.questionnaire_item_id = qi.id AND ss.affiliation_id = q.affiliation_id AND ss.year_id = :y
        WHERE q.year_id = :y2" . ($is_admin ? '' : " AND q.affiliation_id = :aff2") . "
        GROUP BY q.id, q.audience, q.affiliation_id, a.affiliation_item
        ORDER BY " . ($is_admin ? "a.affiliation_item, " : "") . "q.audience");
    $qp = [':y'=>$selected_year, ':y2'=>$selected_year];
    if (!$is_admin) $qp[':aff2'] = $survey_affil;
    $qlist->execute($qp);
    $questionnaires = $qlist->fetchAll();
    // แบบสอบถามที่เลือกอยู่มีจริงไหม (ตรงทั้งชื่อ + คณะเจ้าของ เพราะชื่อซ้ำข้ามคณะได้)
    $sel_survey_exists = false;
    foreach ($questionnaires as $qq) { if ($qq['name'] === $group && (int)$qq['affid'] === (int)$survey_affil) { $sel_survey_exists = true; break; } }

    $it = $pdo->prepare("
        SELECT qi.id AS qiid, ai.name_tiem AS label, qi.admin_item_id, ai.unit, ai.AD, ag.scope,
               COALESCE(ss.respondents,0) AS respondents, COALESCE(ss.avg_value,0) AS avg_value,
               (COALESCE(ss.respondents,0)*COALESCE(ss.avg_value,0)*ai.AD)/1000 AS emission
        FROM questionnaire q
        JOIN questionnaire_item qi ON qi.questionnaire_id=q.id
        JOIN admin_item ai ON ai.id=qi.admin_item_id
        JOIN admin_g ag ON ag.id=ai.scope
        LEFT JOIN survey_summary ss ON ss.questionnaire_item_id=qi.id AND ss.affiliation_id=:aff AND ss.year_id=:y
        WHERE q.year_id=:y2 AND q.audience=:grp AND q.affiliation_id=:aff2 ORDER BY qi.order_num, qi.id");
    $it->execute([':aff'=>$sel_affil, ':y'=>$selected_year, ':y2'=>$selected_year, ':grp'=>$group, ':aff2'=>$survey_affil]);
    $rows = $it->fetchAll();
    // เลือกแบบสอบถามอยู่ → รวมเฉพาะแบบสอบถามนั้น; ยังไม่เลือก → รวมทุกแบบสอบถามของปีนี้
    $year_total = $sel_survey_exists
        ? array_sum(array_map(fn($r)=>(float)$r['emission'], $rows))
        : array_sum(array_map(fn($q)=>(float)$q['tco2e'], $questionnaires));
} else { // event
    // รายชื่อผู้จัดสำหรับ dropdown (admin) = คณะในระบบ + ผู้จัดอิสระที่เคยกรอกในปีนี้
    // ตัวเลือกผู้จัดสำหรับฟอร์มเพิ่มกิจกรรม (admin) = คณะในระบบ + ผู้จัดอิสระที่เคยกรอกในปีนี้
    $org_options = [];
    if ($is_admin) {
        foreach ($affils as $a) $org_options[] = ['value'=>'aff:'.$a['id'], 'label'=>$a['affiliation_item']];
        $cst = $pdo->prepare("SELECT DISTINCT organizer_name FROM event WHERE year_id=? AND organizer_name IS NOT NULL AND organizer_name<>''");
        $cst->execute([$selected_year]);
        foreach ($cst->fetchAll(PDO::FETCH_COLUMN) as $cn) $org_options[] = ['value'=>'custom:'.$cn, 'label'=>$cn];
    }
    // admin เห็นทุกกิจกรรมของปีนั้น; officer เห็นเฉพาะกิจกรรมของคณะตัวเอง
    $evStmt = $pdo->prepare("SELECT e.id, e.name, e.kind, e.event_date, e.event_end_date, e.affiliation_id, e.organizer_name,
            COALESCE(e.organizer_name, a.affiliation_item) AS org_label,
            COALESCE(SUM(ei.Vol*ai.AD)/1000,0) AS tco2e, COUNT(ei.id) AS item_count,
            (SELECT COALESCE(SUM(rei.qty*rei.factor)/1000,0) FROM removal_event_item rei WHERE rei.event_id=e.id) AS removal_tco2e,
            (SELECT COUNT(*) FROM removal_event_item rei WHERE rei.event_id=e.id) AS removal_count,
            (SELECT COUNT(*) FROM evidence ev WHERE ev.entity_type='event' AND ev.entity_id=e.id) AS ev_count
        FROM event e JOIN affiliation_id a ON a.id=e.affiliation_id
        LEFT JOIN event_item ei ON ei.event_id=e.id LEFT JOIN admin_item ai ON ai.id=ei.admin_item_id
        WHERE e.year_id=:y" . ($is_admin ? '' : " AND e.affiliation_id=:lock") . " GROUP BY e.id ORDER BY e.event_date DESC, e.id DESC");
    $p=[':y'=>$selected_year];
    if (!$is_admin) $p[':lock']=(int)$lock_affil;
    $evStmt->execute($p);
    $events = $evStmt->fetchAll();
    // แยกยอดตามประเภท: ปล่อย (emission) กับ ดูดกลับ (removal) — ไม่รวมกัน (คนละหมวดตามหลัก)
    // กิจกรรมมีได้ทั้งปล่อย+ดูดกลับ → รวมแยกตามชนิดรายการ ไม่ผูกกับ kind ของกิจกรรม
    $ev_emit_total = array_sum(array_map(fn($e)=>(float)$e['tco2e'], $events));
    $ev_rmv_total  = array_sum(array_map(fn($e)=>(float)$e['removal_tco2e'], $events));
    $year_total = $ev_emit_total;
    if ($sel_event) {
        foreach ($events as $e) if ((int)$e['id']===$sel_event) { $curEvent=$e; break; }
        if ($curEvent) {
            $it=$pdo->prepare("SELECT ei.id, ei.admin_item_id, ei.Vol, ai.name_tiem, ai.unit, ai.AD, ag.scope, (ei.Vol*ai.AD)/1000 AS emission
                FROM event_item ei JOIN admin_item ai ON ai.id=ei.admin_item_id JOIN admin_g ag ON ag.id=ai.scope
                WHERE ei.event_id=? ORDER BY ag.scope, ai.name_tiem");
            $it->execute([$sel_event]); $curItems=$it->fetchAll();
            foreach ($curItems as $ci) $curVol[(int)$ci['admin_item_id']] = (float)$ci['Vol']; // map ค่า Vol เดิมไว้ prefill
        }
    }
    // รายการ EF เฉพาะของกิจกรรมที่เลือก (ผูกด้วย event_id) — ไม่แชร์ข้ามกิจกรรม
    $rm_rows = [];
    if ($sel_event) {
        $ef=$pdo->prepare("SELECT ai.id, ai.name_tiem, ai.unit, ai.AD, ag.scope FROM admin_item ai JOIN admin_g ag ON ag.id=ai.scope
            WHERE ai.data_source='event' AND ai.event_id=? ORDER BY ag.scope, ai.name_tiem");
        $ef->execute([$sel_event]); $ef_items=$ef->fetchAll();
        // รายการดูดกลับ "เฉพาะของกิจกรรมนี้" (แยกขาดจากหน้ากลาง — เก็บ factor ในตัวเอง)
        $rme=$pdo->prepare("SELECT id AS rid, name_tiem, unit, factor, qty, qty*factor/1000 AS emission
            FROM removal_event_item WHERE event_id=? ORDER BY name_tiem");
        $rme->execute([$sel_event]); $rm_rows=$rme->fetchAll();
    }
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
            .co { padding:26px 30px 70px; max-width:none; }
            .co h1 { font-size:1.4rem; font-weight:800; color:#2A2233; margin:0 0 12px; }
            .flash { display:flex; align-items:center; gap:10px; border-radius:12px; padding:12px 16px; font-weight:600; margin-bottom:16px; }
            .flash svg { flex-shrink:0; } .flash.success{background:#DCFCE7;color:#166534;} .flash.danger{background:#FEE2E2;color:#B91C1C;}
            .card { background:#fff; border:1px solid #E7E3EC; border-radius:16px; padding:20px 22px; margin-bottom:16px; }
            .card h2 { font-size:1.05rem; font-weight:800; margin:0 0 14px; color:#2A2233; overflow-wrap:anywhere; word-break:break-word; }
            .tabs{display:inline-flex;gap:6px;background:#F1EEF5;border-radius:999px;padding:4px;margin-bottom:16px;}
            .tabs a{padding:8px 20px;border-radius:999px;text-decoration:none;font-weight:700;font-size:.92rem;color:#5B5168;}
            .tabs a.on{background:#62368B;color:#fff;box-shadow:0 6px 14px rgba(98,54,139,.25);}
            .row-top{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
            .grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
            .full{grid-column:1/-1;}
            .fld label{display:block;font-size:.8rem;font-weight:600;color:#4B4155;margin:0 0 5px;}
            .fld input{width:100%;}
            table.t{width:100%;border-collapse:collapse;}
            table.t th{text-align:center;font-size:.75rem;color:#6B7280;padding:8px 10px;border-bottom:1px solid #E7E3EC;}
            table.t td{padding:9px 10px;border-bottom:1px solid #F1EEF5;font-size:.92rem;text-align:center;vertical-align:middle;overflow-wrap:anywhere;}
            /* ชื่อกิจกรรม/แบบสอบถามในลิสต์: ยาวเกินให้ตัดด้วย ... (hover เห็นชื่อเต็ม) */
            .evt-name{ flex:1 1 auto; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            /* คอลัมน์แรก (คำถาม/กิจกรรม) ชิดซ้าย ที่เหลือกึ่งกลาง — เหมือนตารางขอบเขต 1 */
            table.t th:first-child, table.t td:first-child{text-align:left;}
            /* แถวกิจกรรม: hover จาง → active (เลือกอยู่) เข้ม + แถบม่วงซ้าย, กดชื่อซ้ำ = ปิด */
            table.t tr.evt td{transition:background .15s ease;}
            table.t tr.evt:hover td{background:#F5F1FA;}
            table.t tr.sel td{background:#EDE9FE;}
            table.t tr.sel td:first-child{box-shadow:inset 3px 0 0 #62368B;}
            /* มุมโค้งปลายแถบไฮไลต์ทั้งตอน hover และ active */
            table.t tr.evt:hover td:first-child, table.t tr.sel td:first-child{border-top-left-radius:12px;border-bottom-left-radius:12px;}
            table.t tr.evt:hover td:last-child, table.t tr.sel td:last-child{border-top-right-radius:12px;border-bottom-right-radius:12px;}
            /* เว้นที่หายใจปลายแถว → ปุ่ม/ข้อความไม่ชิดขอบแถบไฮไลต์ */
            table.t td:first-child{padding-left:16px;}
            table.t td:last-child{padding-right:16px;}
            .linkish:hover{text-decoration:underline;}
            .num{text-align:center;font-variant-numeric:tabular-nums;}
            .sdot{font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:6px;}
            .s1{background:#FFF7ED;color:#F97316;}.s2{background:#FDF2F8;color:#EC4899;}.s3{background:#EFF6FF;color:#3B82F6;}
            .inp{width:120px;border:1px solid #E7E3EC;border-radius:8px;padding:7px 10px;font:inherit;text-align:center;background:#fff;}
            .del{background:none;border:1px solid #E7E3EC;border-radius:8px;color:#EF4444;cursor:pointer;padding:5px 10px;font:inherit;}
            .icobtn{border:none;width:36px;height:36px;border-radius:10px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#fff;transition:all .2s;vertical-align:middle;margin:0 3px;}
            .icobtn.edit{background:#3B82F6;box-shadow:0 4px 10px rgba(59,130,246,.2);}
            .icobtn.edit:hover{background:#2563EB;transform:translateY(-2px);}
            .icobtn.del{background:#EF4444;box-shadow:0 4px 10px rgba(239,68,68,.2);}
            .icobtn.del:hover{background:#DC2626;transform:translateY(-2px);}
            .tot{font-weight:800;color:#62368B;} .muted{color:#6B7280;font-size:.9rem;} .foot{display:flex;justify-content:flex-end;margin-top:14px;}
            .frow{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;} .frow .fld{flex:1;min-width:150px;}
            .linkish{color:#62368B;text-decoration:none;font-weight:600;}
            .co .dd-trigger,.co .ti-input{height:auto;padding:9px 12px;border-radius:10px;font-family:inherit;font-size:1rem;font-weight:400;background:#fff;border-color:#E7E3EC;transition:border-color .2s ease,box-shadow .2s ease;}
            /* effect ตอน focus: ขอบม่วง + glow (specificity 0,3,0 ชนะ .co .ti-input) ให้เหมือน dropdown/modal */
            .co .ti-input:focus{outline:none;border-color:#62368B;box-shadow:0 0 0 1px #62368B,0 0 0 4px rgba(98,54,139,.15);}
            /* ล้างพื้นหลัง autofill (โทนฟ้า/ม่วง) ให้เป็นขาวในหน้านี้ */
            .co .ti-input:-webkit-autofill,.inp:-webkit-autofill,
            .co .ti-input:-webkit-autofill:hover,.inp:-webkit-autofill:hover,
            .co .ti-input:-webkit-autofill:focus,.inp:-webkit-autofill:focus{
                -webkit-box-shadow:0 0 0 1000px #fff inset;
                -webkit-text-fill-color:#374151;
                transition:background-color 9999s ease-in-out 0s;
            }
        </style>

        <div class="co">
            <h1>📝 แบบสอบถาม & กิจกรรม</h1>
            <?php if (count($TABS) > 1): ?>
            <div class="tabs">
                <?php foreach ($TABS as $k=>$lbl):
                    $href="collect.php?year=$selected_year&tab=$k";
                    if ($k==='survey') $href.='&group='.urlencode($group); ?>
                    <a class="<?= $tab===$k?'on':'' ?>" href="<?= $href ?>"
                        onclick="return !this.classList.contains('on');"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php $toast_msg = $flash; $toast_type = $flash_t; include __DIR__ . '/../components/toast.php'; ?>

            <div class="row-top">
                <label style="font-weight:600;color:#4B4155;">ปี:</label>
                <?php $dd_id='coYear';$dd_name='year_nav';$dd_options=array_map(fn($y)=>['value'=>$y['year_id'],'label'=>(string)$y['year']],$years);
                    $dd_selected=$selected_year;$dd_required=false;$dd_class='dd-field';$dd_placeholder='เลือกปี';$dd_style='width:110px;';
                    include __DIR__.'/../components/dropdown.php'; ?>
                <?php if (!$is_survey): /* แท็บกิจกรรม: แยกยอดปล่อย/ดูดกลับ (คนละหมวด) */ ?>
                <span class="muted" style="margin-left:auto;text-align:right;line-height:1.5;">
                    กิจกรรมปล่อย: <span class="tot"><?= number_format($ev_emit_total,3) ?></span> tCO₂e<br>
                    กิจกรรมดูดกลับ: <span style="color:#166534;font-weight:800;"><?= number_format($ev_rmv_total,3) ?></span> tCO₂e
                </span>
                <?php else: ?>
                <span class="muted" style="margin-left:auto;">รวม (<?= htmlspecialchars($sel_survey_exists ? $group : 'แบบสอบถาม') ?>): <span class="tot"><?= number_format($year_total,3) ?></span> tCO₂e</span>
                <?php endif; ?>
            </div>

            <?php if ($is_survey): /* ===== แท็บ แบบสอบถาม ===== */ ?>
                <!-- เพิ่มแบบสอบถาม (+ ผู้จัดทำ สำหรับ admin) -->
                <div class="card">
                    <h2>＋ เพิ่มแบบสอบถาม</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_questionnaire"><input type="hidden" name="tab" value="survey"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                        <?php if (!$is_admin): ?><input type="hidden" name="maker" value="<?= (int)$survey_affil ?>"><?php endif; ?>
                        <?php if ($is_admin): ?>
                        <!-- ผู้จัดทำ = field ในฟอร์ม (สร้างให้คณะไหน) — บรรทัดแยก, dropdown กว้างพอชื่อยาว -->
                        <div class="fld" style="max-width:360px;margin-bottom:14px;"><label>ผู้จัดทำ (คณะเจ้าของ)</label>
                            <?php $dd_id='surveyMaker';$dd_name='maker';
                                $dd_options=array_map(fn($a)=>['value'=>$a['id'],'label'=>$a['affiliation_item']],$affils);
                                $dd_selected=$survey_affil;$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือกผู้จัดทำ';$dd_style='width:100%;';
                                include __DIR__.'/../components/dropdown.php'; ?>
                        </div>
                        <?php endif; ?>
                        <div class="frow">
                            <div class="fld" style="flex:1;min-width:240px;"><label>ชื่อแบบสอบถาม</label>
                                <?php $ti_id='qName';$ti_name='q_name';$ti_required=true;$ti_placeholder='เช่น นักศึกษา / บุคลากร / แบบสอบถามการเดินทาง';$ti_wrap_style='width:100%;';include __DIR__.'/../components/text_input.php'; ?></div>
                            <div style="align-self:flex-end;"><?php $btn_label='เพิ่มแบบสอบถาม';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                        </div>
                    </form>
                    <?php if ($is_admin): ?><p class="muted" style="margin-top:10px;">เลือกคณะที่จะสร้างแบบสอบถามให้ · ส่วนหัวข้อ/ค่าเฉลี่ยด้านล่างจะตามแบบสอบถามที่คลิกเลือกในตาราง</p><?php endif; ?>
                </div>

                <!-- แบบสอบถามทั้งหมด -->
                <div class="card">
                    <h2>แบบสอบถามทั้งหมด (<?= count($questionnaires) ?>)</h2>
                    <?php if (empty($questionnaires)): ?>
                        <p class="muted">ยังไม่มีแบบสอบถามในปีนี้ — เพิ่มด้านบน</p>
                    <?php else: ?>
                    <table class="t" style="table-layout:fixed;">
                        <colgroup>
                            <col>
                            <?php if($is_admin):?><col style="width:210px;"><?php endif;?>
                            <col style="width:80px;">
                            <col style="width:96px;">
                            <col style="width:86px;">
                            <col style="width:100px;">
                        </colgroup>
                        <thead><tr><th style="text-align:left;">แบบสอบถาม</th><?php if($is_admin):?><th style="text-align:left;">ผู้จัดทำ</th><?php endif;?><th class="num">รายการ</th><th class="num">tCO₂e</th><th style="text-align:center;">แนบไฟล์</th><th>จัดการ</th></tr></thead>
                        <tbody>
                        <?php foreach ($questionnaires as $qq): $isSelQ = ($qq['name']===$group && (int)$qq['affid']===(int)$survey_affil); ?>
                            <tr class="evt <?= $isSelQ?'sel':'' ?>">
                                <td style="text-align:left;"><div style="display:flex;align-items:center;"><a class="linkish evt-name" title="<?= htmlspecialchars($qq['name'],ENT_QUOTES) ?>" href="<?= $isSelQ ? $qs() : $qs(['group'=>$qq['name'],'maker'=>(int)$qq['affid']]) ?>"><?= htmlspecialchars($qq['name']) ?></a></div></td>
                                <?php if($is_admin):?><td style="text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($qq['maker_name'],ENT_QUOTES) ?>"><?= htmlspecialchars($qq['maker_name']) ?></td><?php endif;?>
                                <td class="num"><?= (int)$qq['item_count'] ?></td>
                                <td class="num"><?= number_format((float)$qq['tco2e'],3) ?></td>
                                <td style="text-align:center;">
                                    <button type="button" class="ev-open-btn" data-ev="questionnaire:<?= (int)$qq['qid'] ?>" title="แนบ (ไฟล์/ลิงก์)"
                                        onclick="openEvidence({type:'questionnaire', id:<?= (int)$qq['qid'] ?>, title:<?= htmlspecialchars(json_encode($qq['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>})">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                        <?php if ((int)$qq['ev_count'] > 0): ?><span class="ev-badge"><?= (int)$qq['ev_count'] ?></span><?php endif; ?>
                                    </button>
                                </td>
                                <td class="num" style="white-space:nowrap;">
                                    <button type="button" class="icobtn edit" title="แก้ไขชื่อ" data-name="<?= htmlspecialchars($qq['name'],ENT_QUOTES) ?>" data-affid="<?= (int)$qq['affid'] ?>" onclick="qEdit(this)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg></button>
                                    <form method="POST" onsubmit="return confirm('ลบแบบสอบถามนี้ทั้งหมด (รวมรายการและค่าเฉลี่ย)?')" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_questionnaire"><input type="hidden" name="tab" value="survey"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="maker" value="<?= (int)$qq['affid'] ?>"><input type="hidden" name="q_name" value="<?= htmlspecialchars($qq['name'],ENT_QUOTES) ?>">
                                        <button class="icobtn del" title="ลบแบบสอบถาม" type="submit"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <!-- Modal แก้ไขชื่อแบบสอบถาม -->
                    <div id="qEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
                        <div style="background:#fff;border-radius:16px;padding:22px;max-width:480px;width:92%;">
                            <h2 style="margin:0 0 14px;font-size:1.1rem;font-weight:800;">✏️ แก้ไขชื่อแบบสอบถาม</h2>
                            <form method="POST">
                                <input type="hidden" name="action" value="edit_questionnaire"><input type="hidden" name="tab" value="survey"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="maker" id="qe_maker" value="<?= (int)$survey_affil ?>"><input type="hidden" name="q_old" id="qe_old">
                                <div class="fld" style="margin-bottom:12px;"><label>ชื่อแบบสอบถาม</label><input class="ti-input" style="width:100%;" name="q_name" id="qe_name" required maxlength="50"></div>
                                <div style="display:flex;justify-content:flex-end;gap:10px;">
                                    <button type="button" class="del" onclick="document.getElementById('qEditModal').style.display='none'">ยกเลิก</button>
                                    <?php $btn_label='บันทึกการแก้ไข';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                    <script>
                        function qEdit(b){
                            document.getElementById('qe_old').value=b.dataset.name;
                            document.getElementById('qe_name').value=b.dataset.name;
                            if(b.dataset.affid) document.getElementById('qe_maker').value=b.dataset.affid;
                            document.getElementById('qEditModal').style.display='flex';
                        }
                        document.getElementById('qEditModal')?.addEventListener('click',function(e){ if(e.target===this) this.style.display='none'; });
                    </script>
                    <?php endif; ?>
                </div>

                <?php if ($sel_survey_exists): ?>
                <div class="card">
                    <h2>＋ เพิ่มรายการสอบถาม (<?= htmlspecialchars($group) ?>)</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_topic"><input type="hidden" name="tab" value="<?= $tab ?>">
                        <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="maker" value="<?= (int)$survey_affil ?>">
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
                        <div style="text-align:right;margin-top:12px;"><?php $btn_label='เพิ่มรายการสอบถาม';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                </div>

                <div class="card">
                    <h2>กรอกค่าเฉลี่ย (<?= htmlspecialchars($group) ?>)</h2>
                    <?php if (empty($rows)): ?>
                        <p class="muted">ยังไม่มีหัวข้อในกลุ่มนี้ — เพิ่มด้านบน</p>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_survey"><input type="hidden" name="tab" value="<?= $tab ?>">
                        <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="group" value="<?= htmlspecialchars($group,ENT_QUOTES) ?>"><input type="hidden" name="maker" value="<?= (int)$survey_affil ?>">
                        <table class="t">
                            <thead><tr><th>คำถาม</th><th>Scope</th><th class="num">จำนวนผู้ตอบ</th><th class="num">เฉลี่ย/คน</th><th>หน่วย</th><th class="num">tCO₂e</th><th>จัดการ</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['label']) ?></td>
                                    <td><span class="sdot s<?= (int)$r['scope'] ?>">S<?= (int)$r['scope'] ?></span></td>
                                    <td class="num"><input class="ti-input" style="width:120px;text-align:center;" type="number" min="0" step="1" name="resp[<?= (int)$r['qiid'] ?>]" value="<?= (int)$r['respondents']?:'' ?>" placeholder="0"></td>
                                    <td class="num"><input class="ti-input" style="width:120px;text-align:center;" type="number" min="0" step="0.0001" name="avg[<?= (int)$r['qiid'] ?>]" value="<?= (float)$r['avg_value']!=0?htmlspecialchars(rtrim(rtrim(number_format((float)$r['avg_value'],4,'.',''),'0'),'.')):'' ?>" placeholder="0"></td>
                                    <td><?= htmlspecialchars($r['unit']) ?></td>
                                    <td class="num" style="color:var(--clr-primary);font-weight:700;"><?= number_format((float)$r['emission'],4) ?></td>
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
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="foot"><?php $btn_label='บันทึก';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                        <?php foreach ($rows as $r): ?>
                        <form method="POST" id="delTopic<?= (int)$r['qiid'] ?>" style="display:none;">
                            <input type="hidden" name="action" value="delete_topic"><input type="hidden" name="tab" value="<?= $tab ?>">
                            <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="maker" value="<?= (int)$survey_affil ?>"><input type="hidden" name="group" value="<?= htmlspecialchars($group,ENT_QUOTES) ?>">
                            <input type="hidden" name="qitem_id" value="<?= (int)$r['qiid'] ?>">
                        </form>
                        <?php endforeach; ?>
                        <!-- Modal แก้ไขหัวข้อ -->
                        <div id="topicEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
                            <div style="background:#fff;border-radius:16px;padding:22px;max-width:520px;width:92%;">
                                <h2 style="margin:0 0 14px;font-size:1.1rem;font-weight:800;">✏️ แก้ไขหัวข้อ</h2>
                                <form method="POST">
                                    <input type="hidden" name="action" value="edit_topic"><input type="hidden" name="tab" value="<?= $tab ?>">
                                    <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="maker" value="<?= (int)$survey_affil ?>"><input type="hidden" name="group" value="<?= htmlspecialchars($group,ENT_QUOTES) ?>">
                                    <input type="hidden" name="qitem_id" id="te_qiid">
                                    <div class="fld" style="margin-bottom:10px;"><label>คำถาม</label><input class="ti-input" style="width:100%;" name="label" id="te_label" required></div>
                                    <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                                        <div class="fld" style="flex:1;min-width:120px;"><label>หน่วย</label><input class="ti-input" style="width:100%;" name="unit" id="te_unit" required></div>
                                        <div class="fld" style="width:130px;"><label>Scope</label>
                                            <?php $dd_id='teScopeEdit';$dd_name='scope';$dd_options=[['value'=>1,'label'=>'Scope 1'],['value'=>2,'label'=>'Scope 2'],['value'=>3,'label'=>'Scope 3']];
                                                $dd_selected=1;$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือก Scope';$dd_style='';include __DIR__.'/../components/dropdown.php'; ?></div>
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
                                ddSetValue('teScopeEdit', b.dataset.scope, 'Scope '+b.dataset.scope);
                                document.getElementById('te_ad').value=b.dataset.ad;
                                var m=document.getElementById('topicEditModal'); m.style.display='flex';
                            }
                            function topicDelete(qiid){ if(confirm('ลบหัวข้อนี้?')) document.getElementById('delTopic'+qiid).submit(); }
                            document.getElementById('topicEditModal')?.addEventListener('click',function(e){ if(e.target===this) this.style.display='none'; });
                        </script>
                    <?php endif; ?>
                </div>
                <?php endif; /* sel_survey_exists */ ?>

            <?php else: /* ===== แท็บ กิจกรรม ===== */ ?>
                <div class="card">
                    <h2>＋ เพิ่มกิจกรรม</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_event"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                        <!-- บรรทัด 1: ชื่อกิจกรรม เต็มความกว้าง (ประเภทปล่อย/ดูดกลับ เลือกทีหลังต่อรายการ) -->
                        <div class="fld full" style="margin-bottom:12px;"><label>ชื่อกิจกรรม</label>
                            <?php $ti_id='evName';$ti_name='name';$ti_required=true;$ti_placeholder='เช่น งานรับน้อง 2569';$ti_wrap_style='width:100%;';include __DIR__.'/../components/text_input.php'; ?></div>
                        <!-- บรรทัด 2: ผู้จัด / วันที่ / ปุ่ม -->
                        <div class="frow">
                            <?php if ($is_admin): ?>
                            <div class="fld" id="addOrgField" style="flex:0 0 auto;"><label>ผู้จัด</label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <?php $org_dd=$org_options; $org_dd[]=['value'=>'__custom__','label'=>'อื่นๆ…'];
                                        $dd_id='addOrg';$dd_name='org';$dd_options=$org_dd;$dd_selected=($org_options[0]['value']??'');$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือกผู้จัด';$dd_style='width:360px;flex:none;';include __DIR__.'/../components/dropdown.php'; ?>
                                    <input type="text" id="addOrgCustom" class="ti-input" style="width:320px;display:none;" placeholder="พิมพ์ชื่อผู้จัด (อื่นๆ)">
                                </div></div>
                            <?php endif; ?>
                            <div class="fld" style="flex:0 0 140px;min-width:0;max-width:140px;"><label>วันที่เริ่ม</label>
                                <input type="text" id="evDate" class="ti-input" style="width:100%;" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off">
                                <input type="hidden" name="event_date" id="evDateHidden"></div>
                            <div class="fld" style="flex:0 0 140px;min-width:0;max-width:140px;"><label>วันสิ้นสุด <span class="muted">(ถ้ามี)</span></label>
                                <input type="text" id="evEndDate" class="ti-input" style="width:100%;" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off">
                                <input type="hidden" name="event_end_date" id="evEndDateHidden"></div>
                        </div>
                        <!-- ปุ่มบันทึกไว้มุมขวาล่างของการ์ด (บรรทัดของตัวเอง) -->
                        <div style="text-align:right;margin-top:12px;"><?php $btn_label='เพิ่มกิจกรรม';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                </div>
                <div class="card">
                    <h2>กิจกรรมทั้งหมด (<?= count($events) ?>)</h2>
                    <?php if (empty($events)): ?><p class="muted">ยังไม่มีกิจกรรมในปีนี้</p><?php else: ?>
                    <table class="t" style="table-layout:fixed;">
                        <colgroup>
                            <col>
                            <?php if($is_admin):?><col style="width:146px;"><?php endif;?>
                            <col style="width:210px;">
                            <col style="width:60px;">
                            <col style="width:88px;">
                            <col style="width:80px;">
                            <col style="width:96px;">
                        </colgroup>
                        <thead><tr><th style="text-align:left;">กิจกรรม</th><?php if($is_admin):?><th>ผู้จัด</th><?php endif;?><th>วันที่</th><th class="num">รายการ</th><th class="num">tCO₂e</th><th style="text-align:center;">ไฟล์</th><th>จัดการ</th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $e): ?>
                            <?php $isSel = (int)$e['id']===$sel_event; ?>
                            <tr class="evt <?= $isSel?'sel':'' ?>">
                                <?php $hasEmit=(int)$e['item_count']>0; $hasRmv=(int)$e['removal_count']>0; ?>
                                <td style="text-align:left;">
                                    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        <a class="linkish evt-name" title="<?= htmlspecialchars($e['name'],ENT_QUOTES) ?>" href="<?= $isSel ? $qs() : $qs(['event'=>$e['id']]) ?>"><?= htmlspecialchars($e['name']) ?></a>
                                    </div>
                                </td>
                                <?php if($is_admin):?><td style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($e['org_label'],ENT_QUOTES) ?>"><?= htmlspecialchars($e['org_label']) ?></td><?php endif;?>
                                <td style="white-space:nowrap;"><?php
                                    // แสดงวันที่เป็น วัน/เดือน/ปี (dd/mm/yyyy); ใน DB เก็บเป็น yyyy-mm-dd
                                    $fmtDMY = fn($iso) => $iso ? implode('/', array_reverse(explode('-', $iso))) : '—';
                                    echo htmlspecialchars($fmtDMY($e['event_date']));
                                    if (!empty($e['event_end_date'])) echo ' - ' . htmlspecialchars($fmtDMY($e['event_end_date']));
                                ?></td>
                                <td class="num"><?= (int)$e['item_count'] + (int)$e['removal_count'] ?></td>
                                <td class="num">🏭 <?= number_format((float)$e['tco2e'],3) ?><?php if ($hasRmv): ?> <span style="color:#166534;font-weight:700;white-space:nowrap;">🌱 <?= number_format((float)$e['removal_tco2e'],3) ?></span><?php endif; ?></td>
                                <td style="text-align:center;">
                                    <button type="button" class="ev-open-btn" data-ev="event:<?= (int)$e['id'] ?>" title="แนบ (ไฟล์/ลิงก์)"
                                        onclick="openEvidence({type:'event', id:<?= (int)$e['id'] ?>, title:<?= htmlspecialchars(json_encode($e['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>})">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                        <?php if ((int)$e['ev_count'] > 0): ?><span class="ev-badge"><?= (int)$e['ev_count'] ?></span><?php endif; ?>
                                    </button>
                                </td>
                                <td class="num" style="white-space:nowrap;">
                                    <button type="button" class="icobtn edit" title="แก้ไขกิจกรรม"
                                        data-eid="<?= (int)$e['id'] ?>"
                                        data-name="<?= htmlspecialchars($e['name'],ENT_QUOTES) ?>"
                                        data-date="<?= htmlspecialchars((string)$e['event_date'],ENT_QUOTES) ?>"
                                        data-end="<?= htmlspecialchars((string)($e['event_end_date']??''),ENT_QUOTES) ?>"
                                        onclick="evEdit(this)">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('ลบกิจกรรมนี้?')" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_event"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                                    <button class="icobtn del" title="ลบกิจกรรม" type="submit">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                    </button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="evEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
                        <div style="background:#fff;border-radius:16px;padding:22px;max-width:520px;width:92%;">
                            <h2 style="margin:0 0 14px;font-size:1.1rem;font-weight:800;">✏️ แก้ไขกิจกรรม</h2>
                            <form method="POST">
                                <input type="hidden" name="action" value="edit_event"><input type="hidden" name="tab" value="event">
                                <input type="hidden" name="year_id" value="<?= $selected_year ?>">                                <input type="hidden" name="event_id" id="ev_ed_eid">
                                <div class="fld" style="margin-bottom:10px;"><label>ชื่อกิจกรรม</label><input class="ti-input" style="width:100%;" name="name" id="ev_ed_name" required></div>
                                <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                                    <div class="fld" style="flex:1;min-width:140px;"><label>วันที่เริ่ม</label>
                                        <input type="text" id="ev_ed_date" class="ti-input evMaskDate" style="width:100%;" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off" data-hidden="ev_ed_dateH">
                                        <input type="hidden" name="event_date" id="ev_ed_dateH"></div>
                                    <div class="fld" style="flex:1;min-width:140px;"><label>วันสิ้นสุด <span class="muted">(ถ้ามี)</span></label>
                                        <input type="text" id="ev_ed_end" class="ti-input evMaskDate" style="width:100%;" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off" data-hidden="ev_ed_endH">
                                        <input type="hidden" name="event_end_date" id="ev_ed_endH"></div>
                                </div>
                                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
                                    <button type="button" class="del" onclick="document.getElementById('evEditModal').style.display='none'">ยกเลิก</button>
                                    <?php $btn_label='บันทึกการแก้ไข';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($curEvent): ?>
                <!-- ＋ เพิ่มรายการ (เลือกประเภท: ปล่อย / ดูดกลับ ต่อรายการ) -->
                <div class="card">
                    <h2>＋ เพิ่มรายการ</h2>
                    <div class="fld" style="max-width:200px;margin-bottom:14px;"><label>ประเภทรายการ</label>
                        <?php $dd_id='evItemType';$dd_name='__itype';$dd_options=[['value'=>'emit','label'=>'🏭 ปล่อยคาร์บอน'],['value'=>'rmv','label'=>'🌱 ดูดกลับคาร์บอน']];
                            $dd_selected='emit';$dd_required=false;$dd_class='dd-field';$dd_placeholder='เลือกประเภท';$dd_style='width:100%;';include __DIR__.'/../components/dropdown.php'; ?>
                    </div>
                    <!-- ── ปล่อยคาร์บอน ── -->
                    <form method="POST" id="evAddEmit">
                        <input type="hidden" name="action" value="add_event_topic"><input type="hidden" name="tab" value="event">
                        <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>">
                        <div class="grid">
                            <div class="fld full"><label>ชื่อรายการ</label>
                                <?php $ti_id='evLabel';$ti_name='label';$ti_required=false;$ti_placeholder='เช่น น้ำมันดีเซล (รถบัสรับส่ง)';include __DIR__.'/../components/text_input.php'; ?></div>
                            <div class="fld"><label>หน่วย</label>
                                <?php $ti_id='evUnit';$ti_name='unit';$ti_required=false;$ti_placeholder='L / kg / kWh';include __DIR__.'/../components/text_input.php'; ?></div>
                            <div class="fld"><label>ขอบเขต (Scope)</label>
                                <?php $dd_id='evScope';$dd_name='scope';$dd_options=[['value'=>3,'label'=>'Scope 3'],['value'=>1,'label'=>'Scope 1'],['value'=>2,'label'=>'Scope 2']];
                                    $dd_selected=3;$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือก Scope';$dd_style='';include __DIR__.'/../components/dropdown.php'; ?></div>
                            <div class="fld"><label>ค่า EF (kgCO₂e/หน่วย)</label>
                                <?php $ti_id='evAd';$ti_name='ad';$ti_type='number';$ti_required=false;$ti_step='0.0001';$ti_min=0;$ti_placeholder='0.0000';include __DIR__.'/../components/text_input.php'; ?></div>
                        </div>
                        <div style="text-align:right;margin-top:12px;"><?php $btn_label='＋ เพิ่มรายการปล่อย';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                    <!-- ── ดูดกลับคาร์บอน ── -->
                    <form method="POST" id="evAddRmv" style="display:none;">
                        <input type="hidden" name="action" value="add_removal_item"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>">
                        <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                            <div class="fld" style="flex:1;min-width:200px;"><label>ชื่อรายการ</label>
                                <?php $ti_id='rmiName';$ti_name='name';$ti_required=false;$ti_placeholder='เช่น ต้นไม้ยืนต้น / พื้นที่ป่า';$ti_wrap_style='width:100%;';include __DIR__.'/../components/text_input.php'; ?></div>
                            <div class="fld" style="width:140px;"><label>หน่วย</label>
                                <?php $ti_id='rmiUnit';$ti_name='unit';$ti_required=false;$ti_placeholder='ต้น / ไร่';include __DIR__.'/../components/text_input.php'; ?></div>
                            <div class="fld" style="width:190px;"><label>ค่าดูดกลับ (kgCO₂e/หน่วย/ปี)</label>
                                <input class="ti-input" style="width:100%;" type="number" step="0.0001" min="0" name="factor" placeholder="0.0000"></div>
                            <div><?php $btn_label='＋ เพิ่มรายการดูดกลับ';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                        </div>
                        <p class="muted" style="margin-top:8px;">ค่าดูดกลับ (factor) ควรอ้างอิงค่ามาตรฐาน (เช่น TGO) — ยอดจะรวมเข้า GHG Removal ของมหาวิทยาลัยอัตโนมัติ</p>
                    </form>
                </div>

                <?php if (!empty($ef_items)): ?>
                <div class="card">
                    <h2>กรอกปริมาณ (ปล่อยคาร์บอน) — <?= htmlspecialchars($curEvent['name']) ?> <span class="muted" style="font-weight:500;font-size:.9rem;">· ผู้จัด: <?= htmlspecialchars($curEvent['org_label']) ?></span></h2>
                    <?php if (empty($ef_items)): ?>
                        <p class="muted">ยังไม่มีรายการในปีนี้ — เพิ่มด้านบน</p>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_event_items"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>">
                        <table class="t">
                            <thead><tr><th>รายการ</th><th>SCOPE</th><th>หน่วย</th><th class="num">ค่าคาร์บอน (kgCO₂e/หน่วย)</th><th class="num">ปริมาณ</th><th class="num">tCO₂e</th><th>จัดการ</th></tr></thead>
                            <tbody>
                            <?php foreach ($ef_items as $x): $v=$curVol[(int)$x['id']]??0; ?>
                                <tr>
                                    <td><?= htmlspecialchars($x['name_tiem']) ?></td>
                                    <td><span class="sdot s<?= (int)$x['scope'] ?>">S<?= (int)$x['scope'] ?></span></td>
                                    <td><?= htmlspecialchars($x['unit']) ?></td>
                                    <td class="num"><?= number_format((float)$x['AD'],4) ?></td>
                                    <td class="num"><input class="ti-input evVol" style="width:120px;text-align:center;" type="number" min="0" step="0.0001" data-ad="<?= (float)$x['AD'] ?>" name="vol[<?= (int)$x['id'] ?>]" value="<?= $v!=0?htmlspecialchars(rtrim(rtrim(number_format($v,4,'.',''),'0'),'.')):'' ?>" placeholder="0"></td>
                                    <td class="num evEmit" style="color:var(--clr-primary);font-weight:700;"><?= number_format($v*(float)$x['AD']/1000,4) ?></td>
                                    <td class="num" style="white-space:nowrap;">
                                        <button type="button" class="icobtn edit" title="แก้ไขรายการ"
                                            data-aiid="<?= (int)$x['id'] ?>"
                                            data-label="<?= htmlspecialchars($x['name_tiem'],ENT_QUOTES) ?>"
                                            data-unit="<?= htmlspecialchars($x['unit'],ENT_QUOTES) ?>"
                                            data-scope="<?= (int)$x['scope'] ?>"
                                            data-ad="<?= htmlspecialchars((string)$x['AD'],ENT_QUOTES) ?>"
                                            onclick="evTopicEdit(this)">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                                        </button>
                                        <button type="button" class="icobtn del" title="ลบรายการ" onclick="evTopicDelete(<?= (int)$x['id'] ?>)">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="foot"><?php $btn_label='บันทึก';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                    <?php foreach ($ef_items as $x): ?>
                    <form method="POST" id="delEvTopic<?= (int)$x['id'] ?>" style="display:none;">
                        <input type="hidden" name="action" value="delete_event_topic"><input type="hidden" name="tab" value="event">
                        <input type="hidden" name="year_id" value="<?= $selected_year ?>">                        <input type="hidden" name="admin_item_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>">
                    </form>
                    <?php endforeach; ?>
                    <p class="muted" style="margin-top:6px;">กรอกปริมาณเฉพาะรายการที่เกี่ยวข้อง (เว้นว่าง/0 = ไม่นับ) แล้วกดบันทึกทีเดียว</p>
                    <div id="evTopicEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
                        <div style="background:#fff;border-radius:16px;padding:22px;max-width:520px;width:92%;">
                            <h2 style="margin:0 0 14px;font-size:1.1rem;font-weight:800;">✏️ แก้ไขรายการ</h2>
                            <form method="POST">
                                <input type="hidden" name="action" value="edit_event_topic"><input type="hidden" name="tab" value="event">
                                <input type="hidden" name="year_id" value="<?= $selected_year ?>">                                <input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>"><input type="hidden" name="admin_item_id" id="ev_te_aiid">
                                <div class="fld" style="margin-bottom:10px;"><label>ชื่อรายการ</label><input class="ti-input" style="width:100%;" name="label" id="ev_te_label" required></div>
                                <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                                    <div class="fld" style="flex:1;min-width:120px;"><label>หน่วย</label><input class="ti-input" style="width:100%;" name="unit" id="ev_te_unit" required></div>
                                    <div class="fld" style="width:130px;"><label>Scope</label>
                                        <?php $dd_id='evScopeEdit';$dd_name='scope';$dd_options=[['value'=>1,'label'=>'Scope 1'],['value'=>2,'label'=>'Scope 2'],['value'=>3,'label'=>'Scope 3']];
                                            $dd_selected=1;$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือก Scope';$dd_style='';include __DIR__.'/../components/dropdown.php'; ?></div>
                                    <div class="fld" style="width:150px;"><label>ค่า EF</label><input class="ti-input" style="width:100%;" type="number" step="0.0001" min="0" name="ad" id="ev_te_ad" required></div>
                                </div>
                                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
                                    <button type="button" class="del" onclick="document.getElementById('evTopicEditModal').style.display='none'">ยกเลิก</button>
                                    <?php $btn_label='บันทึกการแก้ไข';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php endif; /* end ตารางปล่อย (ef_items) */ ?>

                <?php if (!empty($rm_rows)): ?>
                <!-- ตารางกรอกปริมาณดูดกลับ -->
                <div class="card">
                    <h2>กรอกปริมาณดูดกลับ —<?= htmlspecialchars($curEvent['name']) ?> <span class="muted" style="font-weight:500;font-size:.9rem;">· ผู้จัด: <?= htmlspecialchars($curEvent['org_label']) ?></span></h2>
                    <?php if (empty($rm_rows)): ?>
                        <p class="muted">ยังไม่มีรายการดูดกลับในกิจกรรมนี้ — เพิ่มด้านบน (เช่น ปลูกต้นไม้ยืนต้น)</p>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_event_removal"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>">
                        <table class="t">
                            <thead><tr><th>รายการดูดกลับ</th><th>หน่วย</th><th class="num">ค่าดูดกลับ (kgCO₂e/หน่วย)</th><th class="num">ปริมาณ</th><th class="num">tCO₂e</th><th style="text-align:center;">จัดการ</th></tr></thead>
                            <tbody>
                            <?php foreach ($rm_rows as $m): $q=(float)$m['qty']; ?>
                                <tr>
                                    <td style="font-weight:600;"><?= htmlspecialchars($m['name_tiem']) ?></td>
                                    <td><?= htmlspecialchars($m['unit'] ?: '-') ?></td>
                                    <td class="num"><?= number_format((float)$m['factor'],4) ?></td>
                                    <td class="num"><input class="ti-input evRmQty" style="width:120px;text-align:center;" type="number" min="0" step="0.0001" data-factor="<?= (float)$m['factor'] ?>" name="qty[<?= (int)$m['rid'] ?>]" value="<?= $q!=0?htmlspecialchars(rtrim(rtrim(number_format($q,4,'.',''),'0'),'.')):'' ?>" placeholder="0"></td>
                                    <td class="num evRmEmit" style="color:#166534;font-weight:700;"><?= number_format($q*(float)$m['factor']/1000,4) ?></td>
                                    <td style="text-align:center;white-space:nowrap;">
                                        <button type="button" class="icobtn edit" title="แก้ไขรายการ"
                                            data-rid="<?= (int)$m['rid'] ?>"
                                            data-name="<?= htmlspecialchars($m['name_tiem'],ENT_QUOTES) ?>"
                                            data-unit="<?= htmlspecialchars((string)$m['unit'],ENT_QUOTES) ?>"
                                            data-factor="<?= htmlspecialchars((string)$m['factor'],ENT_QUOTES) ?>"
                                            onclick="evRmEdit(this)">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                                        </button>
                                        <button type="button" class="icobtn del" title="เอาออกจากกิจกรรม" onclick="evRmDel(<?= (int)$m['rid'] ?>)">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="foot"><?php $btn_label='บันทึก';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </form>
                    <?php /* ฟอร์มลบแยกไว้นอกฟอร์มบันทึก (กัน nested form) */ ?>
                    <?php foreach ($rm_rows as $m): ?>
                    <form method="POST" id="delEvRm<?= (int)$m['rid'] ?>" style="display:none;">
                        <input type="hidden" name="action" value="delete_event_removal"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>"><input type="hidden" name="rei_id" value="<?= (int)$m['rid'] ?>">
                    </form>
                    <?php endforeach; ?>
                    <!-- Modal แก้ไขรายการดูดกลับ -->
                    <div id="rmEvEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
                        <div style="background:#fff;border-radius:16px;padding:22px;max-width:520px;width:92%;">
                            <h2 style="margin:0 0 14px;font-size:1.1rem;font-weight:800;">✏️ แก้ไขรายการดูดกลับ</h2>
                            <form method="POST">
                                <input type="hidden" name="action" value="edit_event_removal"><input type="hidden" name="tab" value="event">
                                <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= $curEvent['id'] ?>"><input type="hidden" name="rei_id" id="rmev_id">
                                <div class="fld" style="margin-bottom:10px;"><label>ชื่อรายการ</label><input class="ti-input" style="width:100%;" name="name" id="rmev_name" required></div>
                                <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                                    <div class="fld" style="flex:1;min-width:120px;"><label>หน่วย</label><input class="ti-input" style="width:100%;" name="unit" id="rmev_unit"></div>
                                    <div class="fld" style="width:180px;"><label>ค่าดูดกลับ (kgCO₂e/หน่วย)</label><input class="ti-input" style="width:100%;" type="number" step="0.0001" min="0" name="factor" id="rmev_factor" required></div>
                                </div>
                                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
                                    <button type="button" class="del" onclick="document.getElementById('rmEvEditModal').style.display='none'">ยกเลิก</button>
                                    <?php $btn_label='บันทึกการแก้ไข';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; /* end removal section */ ?>
                <?php endif; /* end curEvent */ ?>
            <?php endif; ?>
        </div>
        <script>
            function evRmDel(rid){ if(confirm('เอารายการนี้ออกจากกิจกรรม?')) document.getElementById('delEvRm'+rid).submit(); }
            function evRmEdit(b){
                document.getElementById('rmev_id').value=b.dataset.rid;
                document.getElementById('rmev_name').value=b.dataset.name;
                document.getElementById('rmev_unit').value=b.dataset.unit;
                document.getElementById('rmev_factor').value=b.dataset.factor;
                document.getElementById('rmEvEditModal').style.display='flex';
            }
            document.getElementById('rmEvEditModal')?.addEventListener('click',function(e){ if(e.target===this) this.style.display='none'; });
            // ── สลับฟอร์มเพิ่มรายการ: ปล่อย (emit) / ดูดกลับ (rmv) ──
            document.getElementById('evItemType')?.addEventListener('dd:change', function(e){
                var emit = e.detail.value === 'emit';
                var fe = document.getElementById('evAddEmit'), fr = document.getElementById('evAddRmv');
                if (fe) fe.style.display = emit ? 'block' : 'none';
                if (fr) fr.style.display = emit ? 'none' : 'block';
            });
            // คำนวณ tCO₂e สดตอนพิมพ์ปริมาณดูดกลับ (qty × factor / 1000)
            document.addEventListener('input', function(e){
                var el=e.target;
                if(el.classList && el.classList.contains('evRmQty')){
                    var f=parseFloat(el.dataset.factor)||0, v=parseFloat(el.value)||0;
                    var cell=el.closest('tr').querySelector('.evRmEmit');
                    if(cell) cell.textContent=(v*f/1000).toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:4});
                }
            });
            // ── คงตำแหน่ง scroll เมื่อกดเลือกกิจกรรม (ลิงก์รีหน้า) → ไม่ให้เด้งขึ้นบนสุด ──
            (function(){
                var KEY='collectScrollY';
                if('scrollRestoration' in history) history.scrollRestoration='manual';
                document.querySelectorAll('a.linkish').forEach(function(a){
                    a.addEventListener('click', function(){
                        try{ sessionStorage.setItem(KEY, String(window.scrollY)); }catch(e){}
                    });
                });
                window.addEventListener('load', function(){
                    var y=null;
                    try{ y=sessionStorage.getItem(KEY); }catch(e){}
                    if(y!==null){
                        window.scrollTo(0, parseInt(y,10)||0);
                        try{ sessionStorage.removeItem(KEY); }catch(e){}
                    }
                });
            })();
            // ── ช่องวันที่แบบ masked dd/mm/yyyy → ส่ง yyyy-mm-dd ผ่าน hidden + validate end ≥ start ──
            (function(){
                var sd=document.getElementById('evDate'), sh=document.getElementById('evDateHidden');
                var ed=document.getElementById('evEndDate'), eh=document.getElementById('evEndDateHidden');
                if(!sd) return;
                function toISO(v){
                    var m=v.match(/^(\d{2})\/(\d{2})\/(\d{4})$/); if(!m) return '';
                    var dd=+m[1], mm=+m[2], yy=+m[3];
                    if(mm<1||mm>12||dd<1||dd>31||yy<1000) return '';
                    return m[3]+'-'+m[2]+'-'+m[1];   // yyyy-mm-dd
                }
                function mask(v){
                    var x=v.replace(/\D/g,'').slice(0,8);            // ตัวเลขล้วน สูงสุด 8 หลัก (ปี 4 หลัก)
                    var out=x.slice(0,2);
                    if(x.length>=3) out+='/'+x.slice(2,4);
                    if(x.length>=5) out+='/'+x.slice(4,8);
                    return out;
                }
                function validateRange(){
                    if(!ed) return;
                    // วันสิ้นสุดต้องไม่น้อยกว่าวันเริ่ม (เทียบ ISO string เรียงตามลำดับได้)
                    var bad = sh.value && eh.value && eh.value < sh.value;
                    ed.setCustomValidity(bad ? 'วันสิ้นสุดต้องไม่น้อยกว่าวันที่เริ่ม' : '');
                    ed.style.borderColor = bad ? '#EF4444' : '';
                    ed.style.boxShadow   = bad ? '0 0 0 1px #EF4444,0 0 0 4px rgba(239,68,68,.15)' : '';
                }
                sd.addEventListener('input', function(){ sd.value=mask(sd.value); sh.value=toISO(sd.value); validateRange(); });
                if(ed) ed.addEventListener('input', function(){ ed.value=mask(ed.value); eh.value=toISO(ed.value); validateRange(); });
            })();
            // ── แก้ไขกิจกรรม (ชื่อ/วันที่เริ่ม/วันสิ้นสุด) ──
            (function(){
                function toISO(v){
                    var m=v.match(/^(\d{2})\/(\d{2})\/(\d{4})$/); if(!m) return '';
                    if(+m[2]<1||+m[2]>12||+m[1]<1||+m[1]>31||+m[3]<1000) return '';
                    return m[3]+'-'+m[2]+'-'+m[1];
                }
                function toDMY(iso){ var m=(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})$/); return m?m[3]+'/'+m[2]+'/'+m[1]:''; }
                function mask(v){
                    var x=v.replace(/\D/g,'').slice(0,8), out=x.slice(0,2);
                    if(x.length>=3) out+='/'+x.slice(2,4);
                    if(x.length>=5) out+='/'+x.slice(4,8);
                    return out;
                }
                document.querySelectorAll('.evMaskDate').forEach(function(inp){
                    inp.addEventListener('input', function(){
                        inp.value=mask(inp.value);
                        var h=document.getElementById(inp.dataset.hidden); if(h) h.value=toISO(inp.value);
                    });
                });
                window.evEdit = function(b){
                    document.getElementById('ev_ed_eid').value=b.dataset.eid;
                    document.getElementById('ev_ed_name').value=b.dataset.name;
                    document.getElementById('ev_ed_date').value=toDMY(b.dataset.date);
                    document.getElementById('ev_ed_dateH').value=b.dataset.date||'';
                    document.getElementById('ev_ed_end').value=toDMY(b.dataset.end);
                    document.getElementById('ev_ed_endH').value=b.dataset.end||'';
                    document.getElementById('evEditModal').style.display='flex';
                };
                document.getElementById('evEditModal')?.addEventListener('click',function(e){ if(e.target===this) this.style.display='none'; });
            })();
            // ── ลบ/แก้ไขรายการ EF ของกิจกรรม (นอก form บันทึก) ──
            window.evTopicDelete = function(id){
                if(confirm('ลบรายการนี้? (จะลบปริมาณของทุกกิจกรรมที่ใช้รายการนี้ด้วย)'))
                    document.getElementById('delEvTopic'+id)?.submit();
            };
            window.evTopicEdit = function(b){
                document.getElementById('ev_te_aiid').value=b.dataset.aiid;
                document.getElementById('ev_te_label').value=b.dataset.label;
                document.getElementById('ev_te_unit').value=b.dataset.unit;
                ddSetValue('evScopeEdit', b.dataset.scope, 'Scope '+b.dataset.scope);
                document.getElementById('ev_te_ad').value=b.dataset.ad;
                document.getElementById('evTopicEditModal').style.display='flex';
            };
            document.getElementById('evTopicEditModal')?.addEventListener('click',function(e){ if(e.target===this) this.style.display='none'; });
            // ── ตารางกิจกรรม: คำนวณ tCO₂e ต่อแถวสด ๆ ตอนพิมพ์ Vol (Vol×EF÷1000) ──
            document.querySelectorAll('.evVol').forEach(function(inp){
                inp.addEventListener('input', function(){
                    var ad=parseFloat(inp.dataset.ad)||0, v=parseFloat(inp.value)||0;
                    var cell=inp.closest('tr').querySelector('.evEmit');
                    if(cell) cell.textContent=(v*ad/1000).toLocaleString('en-US',{minimumFractionDigits:4,maximumFractionDigits:4});
                });
            });
            // ต่อท้าย URL: survey → (&maker admin) &group=..., event → ''
            var navSuffix = <?= $is_survey
                ? "'".($is_admin ? "&maker=".(int)$survey_affil : "")."&group='+encodeURIComponent(".json_encode($group).")"
                : "''" ?>;
            document.getElementById('coYear')?.addEventListener('dd:change', function(e){
                if (String(e.detail.value) === '<?= $selected_year ?>') return; // อยู่ปีเดิม ไม่ต้องโหลด
                location.href='collect.php?tab=<?= $tab ?>&year='+e.detail.value+navSuffix;
            });
            <?php if ($is_admin && $tab==='event'): ?>
            // ผู้จัดในฟอร์มเพิ่มกิจกรรม: เลือก "อื่นๆ" → โชว์ช่องพิมพ์ + ส่งค่าเป็น custom:<ชื่อ>
            (function(){
                var dd=document.getElementById('addOrg'), hidden=document.getElementById('addOrg_input');
                var cust=document.getElementById('addOrgCustom');
                var field=document.getElementById('addOrgField');
                if(!dd) return;
                // toggle การแสดงผลตามตัวเลือกผู้จัด
                // ช่องผู้จัดหดพอดีตัวเสมอ (flex:0 0 auto) → วันที่ตามหลังเนื้อหาแบบชิด ไม่ห่าง
                // คณะปกติ = dropdown อย่างเดียว, อื่นๆ = dropdown + ช่องพิมพ์
                function applyOrgLayout(isCustom){
                    cust.style.display = isCustom ? 'block' : 'none';
                }
                dd.addEventListener('dd:change', function(e){
                    var isCustom = e.detail.value==='__custom__';
                    applyOrgLayout(isCustom);
                    if(isCustom) cust.focus();
                });
                // ตั้งสถานะเริ่มต้นตามค่าที่เลือกไว้ตอนโหลด
                applyOrgLayout((hidden && hidden.value==='__custom__'));
                // ก่อน submit: ถ้าเลือก "อื่นๆ" ให้แทนค่า org ด้วย custom:<ชื่อที่พิมพ์>
                dd.closest('form')?.addEventListener('submit', function(){
                    if(hidden && hidden.value==='__custom__')
                        hidden.value='custom:'+((cust.value||'').trim());
                });
            })();
            <?php endif; ?>

        </script>

        <?php include __DIR__ . '/../components/evidence_modal.php'; ?>
    </main>
</body>
</html>
