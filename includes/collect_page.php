<?php
/**
 * SHARED — หน้ากรอกข้อมูล 2 แท็บ: แบบสอบถาม (หลายกลุ่ม) / กิจกรรม
 * ---------------------------------------------------------------
 * แท็บ แบบสอบถาม (survey): เลือกกลุ่ม (นักศึกษา/บุคลากร/อื่นๆ พิมพ์เอง) + กำหนดหัวข้อ (admin)
 *   + กรอก จำนวนผู้ตอบ×ค่าเฉลี่ย → user_item(source='survey', affiliation=ศูนย์กลาง)
 * แท็บ กิจกรรม (event): จัดการกิจกรรมรายอีเวนต์ (ปริมาณจริง) → user_item(source=event) รายคณะ
 * ทุกอย่างคำนวณ Emission = Vol × EF ÷ 1000 แล้วรวมเข้ายอดคณะ
 *
 * หน้าตา: การ์ดสรุป 3 ใบ → แท็บ → การ์ดรายการ (กดเพื่อเปิดรายละเอียดด้านล่าง) → ตารางกรอก (คำนวณสด + แถบบันทึกติดขอบล่าง)
 * ฟอร์ม "เพิ่ม/แก้ไข" ทั้งหมดอยู่ในหน้าต่าง · สไตล์: assets/css/officer-entry.css + assets/css/collect.css
 * ตารางกรอก: assets/js/data-entry.js (ตัวเดียวกับหน้ากรอกข้อมูลการดำเนินงาน)
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

require_once __DIR__ . '/admin_g.php';
require_once __DIR__ . '/collect_entry.php';

// แท็บ: แบบสอบถาม + กิจกรรม — เปิดให้ทั้ง admin และ officer (officer เห็น/แก้เฉพาะคณะตัวเอง)
$TABS = ['survey' => 'แบบสอบถาม', 'event' => 'กิจกรรม'];
$page_title = 'กรอกข้อมูล';
$page_title2 = 'แบบสอบถาม & กิจกรรม';
$CENTRAL_AFFIL = 1; // ศูนย์สิ่งแวดล้อม = ค่าเริ่มต้นของ "ผู้จัดทำ" สำหรับ admin
$years  = $pdo->query("SELECT id AS year_id, year FROM admin_year ORDER BY year DESC")->fetchAll();
// หมวดย่อยทั้งหมด (admin_g) — ใช้เป็นตัวเลือกในฟอร์มเพิ่ม/แก้ไขรายการ แทนการเดาจากเลขขอบเขต
$all_groups = $pdo->query("SELECT id, scope, name_tiem FROM admin_g ORDER BY scope ASC, order_num ASC, id ASC")->fetchAll();
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
$DETAIL = '#co-detail';   // หลังบันทึก/แก้ไขในรายละเอียด กลับมาที่ส่วนรายละเอียด (ไม่เด้งขึ้นบนสุด)

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
            $qname = mb_substr(trim($_POST['q_name'] ?? ''), 0, 100);
            if ($qname === '') throw new Exception('กรุณาระบุชื่อแบบสอบถาม');
            ensure_questionnaire($pdo, $pyear, $qname, $smaker, $_SESSION['user_id'] ?? null);
            header("Location: collect.php?year=$pyear&tab=survey".($is_admin?"&maker=$smaker":'')."&group=" . urlencode($qname) . "&msg=" . urlencode('เพิ่มแบบสอบถามแล้ว') . $DETAIL); exit;
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
            $old = trim($_POST['q_old'] ?? ''); $new = mb_substr(trim($_POST['q_name'] ?? ''), 0, 100);
            if ($new === '') throw new Exception('กรุณาระบุชื่อแบบสอบถาม');
            if ($old === '') throw new Exception('ไม่พบแบบสอบถามเดิม');
            if ($new !== $old) {
                // กันชื่อซ้ำกับแบบสอบถามอื่นในปีเดียวกัน "ของคณะเดียวกัน"
                $dup = $pdo->prepare("SELECT COUNT(*) FROM questionnaire WHERE year_id=? AND audience=? AND affiliation_id=?");
                $dup->execute([$pyear, $new, $smaker]);
                if ($dup->fetchColumn()) throw new Exception('มีแบบสอบถามชื่อนี้อยู่แล้วในปีนี้');
                $pdo->prepare("UPDATE questionnaire SET audience=? WHERE year_id=? AND audience=? AND affiliation_id=?")->execute([$new, $pyear, $old, $smaker]);
            }
            header("Location: collect.php?year=$pyear&tab=survey".($is_admin?"&maker=$smaker":'')."&group=" . urlencode($new) . "&msg=" . urlencode('แก้ไขชื่อแบบสอบถามแล้ว') . $DETAIL); exit;
        }
        // ---- survey: เพิ่มหัวข้อ (หัวข้อผูกคณะเจ้าของ $smaker) ----
        if ($action === 'add_topic') {
            $label=mb_substr(trim($_POST['label']),0,100); $unit=mb_substr(trim($_POST['unit']),0,100);
            $scope=(int)($_POST['scope'] ?? 0); officer_require_valid_vols([$_POST['ad'] ?? ''], 'ค่า EF', true); $ad=officer_clean_vol($_POST['ad'] ?? '');
            if ($pgroup==='') throw new Exception('กรุณาระบุกลุ่ม');
            if ($label==='') throw new Exception('กรุณาระบุคำถาม');
            [$agid, $scope] = resolve_admin_g($pdo, (int)($_POST['group_id'] ?? 0), $scope, INDIRECT_SCOPE);
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
            header("Location: $redir&msg=".urlencode('เพิ่มหัวข้อแล้ว').$DETAIL); exit;
        }
        if ($action === 'edit_topic') {
            $qiid=(int)$_POST['qitem_id'];
            $label=mb_substr(trim($_POST['label']),0,100); $unit=mb_substr(trim($_POST['unit']),0,100);
            $scope=(int)($_POST['scope'] ?? 0); officer_require_valid_vols([$_POST['ad'] ?? ''], 'ค่า EF', true); $ad=officer_clean_vol($_POST['ad'] ?? '');
            if ($label==='') throw new Exception('กรุณาระบุคำถาม');
            // สิทธิ์: แก้ได้เฉพาะหัวข้อของแบบสอบถามคณะเจ้าของ (ตรวจผ่าน qi → q.affiliation_id)
            $chk=$pdo->prepare("SELECT qi.admin_item_id FROM questionnaire_item qi JOIN questionnaire q ON q.id=qi.questionnaire_id WHERE qi.id=? AND q.affiliation_id=?");
            $chk->execute([$qiid,$smaker]); $aiid=(int)$chk->fetchColumn();
            [$agid, $scope] = resolve_admin_g($pdo, (int)($_POST['group_id'] ?? 0), $scope, INDIRECT_SCOPE);
            if (!$aiid) throw new Exception('ไม่พบหัวข้อ หรือไม่มีสิทธิ์');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE admin_item SET scope=?, name_tiem=?, unit=?, AD=? WHERE id=? AND data_source='survey'")
                ->execute([$agid,$label,$unit,$ad,$aiid]);
            $pdo->commit();
            // EF เปลี่ยน → คำนวณยอดของกลุ่มนี้ใหม่ (ของคณะเจ้าของ)
            reaggregate_survey($pdo,$smaker,$pyear,$pgroup);
            header("Location: $redir&msg=".urlencode('แก้ไขหัวข้อแล้ว').$DETAIL); exit;
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
            header("Location: $redir&msg=".urlencode('ลบหัวข้อแล้ว').$DETAIL); exit;
        }
        // ---- survey: บันทึกค่าเฉลี่ย (ของคณะเจ้าของ $smaker) ----
        if ($action === 'save_survey') {
            $paffil = $smaker;
            $map=$pdo->prepare("SELECT qi.id, qi.admin_item_id FROM questionnaire_item qi JOIN questionnaire q ON q.id=qi.questionnaire_id WHERE q.year_id=? AND q.audience=? AND q.affiliation_id=?");
            $map->execute([$pyear,$pgroup,$smaker]); $map=$map->fetchAll(PDO::FETCH_KEY_PAIR);
            $resp=$_POST['resp']??[]; $avg=$_POST['avg']??[];
            // ค่าผิด (ไม่ใช่ตัวเลข / ติดลบ / เกิน 1,000,000) ในหัวข้อของแบบสอบถามนี้ → ไม่บันทึกทั้งฟอร์ม
            officer_require_valid_vols(array_merge(array_map(fn($q)=>$resp[$q]??'', array_keys($map)), array_map(fn($q)=>$avg[$q]??'', array_keys($map))), 'ผู้ตอบ/ค่าเฉลี่ย');
            // ผู้ตอบ × ค่าเฉลี่ย เกินความจุ → ไม่บันทึกทั้งฟอร์ม (ตรวจก่อนเริ่ม transaction · ตัวตรวจค่าชุดเดียวกับตอนบันทึก)
            $over=[];
            foreach ($map as $qiid=>$aiid) {
                if (collect_survey_over_max(collect_clean_count($resp[$qiid]??''), officer_clean_vol($avg[$qiid]??''))) $over[]=(int)$aiid;
            }
            if ($over) {
                $names=$pdo->query("SELECT name_tiem FROM admin_item WHERE id IN (".implode(',',$over).") ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
                throw new Exception('ผู้ตอบ × ค่าเฉลี่ย ของหัวข้อ "'.implode('", "',array_slice($names,0,3)).'"'.(count($names)>3?' ฯลฯ':'')
                    .' เกิน 999,999,999 จึงยังไม่บันทึก กรุณาตรวจค่าที่กรอก');
            }
            $pdo->beginTransaction();
            $up=$pdo->prepare("INSERT INTO survey_summary (affiliation_id,year_id,questionnaire_item_id,admin_item_id,respondents,avg_value,created_by)
                VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE respondents=VALUES(respondents),avg_value=VALUES(avg_value)");
            $del=$pdo->prepare("DELETE FROM survey_summary WHERE affiliation_id=? AND year_id=? AND questionnaire_item_id=?");
            foreach ($map as $qiid=>$aiid) {
                // ค่าเดียวกับที่หน้าเว็บตรวจ: ผู้ตอบเป็นจำนวนเต็ม, ไม่ติดลบ, ไม่เกิน 1,000,000, คั่นหลักพันได้
                $r=collect_clean_count($resp[$qiid]??''); $a=officer_clean_vol($avg[$qiid]??'');
                if ($r>0 && $a>0) $up->execute([$paffil,$pyear,$qiid,$aiid,$r,$a,$_SESSION['user_id']??null]);
                else $del->execute([$paffil,$pyear,$qiid]);
            }
            reaggregate_survey($pdo,$paffil,$pyear,$pgroup);
            $pdo->commit();
            header("Location: $redir&msg=".urlencode('บันทึกแบบสอบถามแล้ว').$DETAIL); exit;
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
            header("Location: $redir&event=$eid&msg=".urlencode('เพิ่มกิจกรรมแล้ว').$DETAIL); exit;
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
            header("Location: $redir&event=$eid&msg=".urlencode('แก้ไขกิจกรรมแล้ว').$DETAIL); exit;
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
        // (เดิมมี add_item / delete_item — ไม่มีปุ่มเรียกใช้แล้ว และไม่ตรวจว่ารายการเป็นของกิจกรรมนั้น → ลบออก
        //  การกรอก/ล้างปริมาณทำผ่าน save_event_items ที่ตรวจรายการของกิจกรรมครบ)
        // ---- event: นิยามรายการ EF เอง (ชื่อ/หน่วย/scope/EF) ของกิจกรรมนี้ ----
        if ($action === 'add_event_topic') {
            $eid=(int)$_POST['event_id'];
            $label=trim($_POST['label']); $unit=trim($_POST['unit']);
            $scope=(int)($_POST['scope'] ?? 0); officer_require_valid_vols([$_POST['ad'] ?? ''], 'ค่า EF', true); $ad=officer_clean_vol($_POST['ad'] ?? '');
            if ($label==='') throw new Exception('กรอกชื่อรายการ');
            [$agid, $scope] = resolve_admin_g($pdo, (int)($_POST['group_id'] ?? 0), $scope, INDIRECT_SCOPE);
            if (!$eid) throw new Exception('ไม่พบกิจกรรม');
            // สิทธิ์: officer เพิ่มรายการได้เฉพาะกิจกรรมของคณะตัวเอง
            $evown=$pdo->prepare("SELECT affiliation_id, year_id FROM event WHERE id=?"); $evown->execute([$eid]); $evown=$evown->fetch();
            if (!$evown) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$evown['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            // ปีของรายการ = ปีของกิจกรรม (ไม่ใช้ year_id จากฟอร์ม — ปีไม่ตรงแล้วรายงานคณะนับ แต่รายงานทั้ง มพ. ไม่นับ)
            $pdo->prepare("INSERT INTO admin_item (year_id,scope,name_tiem,unit,AD,data_source,event_id) VALUES (?,?,?,?,?,'event',?)")
                ->execute([(int)$evown['year_id'],$agid,$label,$unit,$ad,$eid]);
            header("Location: $redir&event=$eid&msg=".urlencode('เพิ่มรายการแล้ว').$DETAIL); exit;
        }
        if ($action === 'edit_event_topic') {
            $aiid=(int)$_POST['admin_item_id']; $eid=(int)$_POST['event_id'];
            $label=trim($_POST['label']); $unit=trim($_POST['unit']);
            $scope=(int)($_POST['scope'] ?? 0); officer_require_valid_vols([$_POST['ad'] ?? ''], 'ค่า EF', true); $ad=officer_clean_vol($_POST['ad'] ?? '');
            if ($label==='') throw new Exception('กรอกชื่อรายการ');
            [$agid, $scope] = resolve_admin_g($pdo, (int)($_POST['group_id'] ?? 0), $scope, INDIRECT_SCOPE);
            // สิทธิ์: officer แก้ไขได้เฉพาะรายการของกิจกรรมคณะตัวเอง (ตรวจจาก admin_item → event จริง)
            $own=$pdo->prepare("SELECT e.affiliation_id FROM admin_item ai JOIN event e ON e.id=ai.event_id WHERE ai.id=? AND ai.data_source='event'");
            $own->execute([$aiid]); $own=$own->fetch();
            if (!$own) throw new Exception('ไม่พบรายการ');
            if (!$is_admin && (int)$own['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            // Vol ใน user_item ไม่เปลี่ยน (AD/scope อ่านสดตอนแสดงผล) จึงไม่ต้อง reaggregate
            $pdo->prepare("UPDATE admin_item SET scope=?, name_tiem=?, unit=?, AD=? WHERE id=? AND data_source='event'")
                ->execute([$agid,$label,$unit,$ad,$aiid]);
            header("Location: $redir&event=$eid&msg=".urlencode('แก้ไขรายการแล้ว').$DETAIL); exit;
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
            header("Location: $redir&event=$eid&msg=".urlencode('ลบรายการแล้ว').$DETAIL); exit;
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
            officer_require_valid_vols(array_intersect_key((array)$vols, array_flip($valid)));   // ค่าผิดในรายการของกิจกรรมนี้ → ไม่บันทึกทั้งตาราง
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM event_item WHERE event_id=?")->execute([$eid]);
            $ins=$pdo->prepare("INSERT INTO event_item (event_id,admin_item_id,Vol) VALUES (?,?,?)");
            foreach ($vols as $aiid=>$v){ $aiid=(int)$aiid; $v=officer_clean_vol($v);
                if ($v>0 && in_array($aiid,$valid,true)) $ins->execute([$eid,$aiid,$v]); }
            reaggregate_events($pdo,(int)$row['affiliation_id'],(int)$row['year_id']);
            $pdo->commit();
            header("Location: $redir&event=$eid&msg=".urlencode('บันทึกรายการแล้ว').$DETAIL); exit;
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
            $ids=$pdo->prepare("SELECT id FROM removal_event_item WHERE event_id=?"); $ids->execute([$eid]); $ids=$ids->fetchAll(PDO::FETCH_COLUMN);
            officer_require_valid_vols(array_map(fn($reid)=>$qty[$reid]??'', $ids));   // ค่าผิด → ไม่บันทึกทั้งตาราง
            $upd=$pdo->prepare("UPDATE removal_event_item SET qty=? WHERE id=? AND event_id=?");
            foreach ($ids as $reid) {
                $upd->execute([officer_clean_vol($qty[$reid] ?? ''), $reid, $eid]);
            }
            header("Location: $redir&event=$eid&msg=".urlencode('บันทึกปริมาณดูดกลับแล้ว').$DETAIL); exit;
        }
        // ---- event: เพิ่มรายการดูดกลับ "ของกิจกรรมนี้" (เก็บ factor ในตัวเอง แยกจากหน้ากลาง) ----
        if ($action === 'add_removal_item') {
            $eid=(int)$_POST['event_id'];
            $row=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if (!$row) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $name=trim($_POST['name'] ?? ''); $unit=trim($_POST['unit'] ?? ''); officer_require_valid_vols([$_POST['factor'] ?? ''], 'ค่าดูดกลับ', true); $factor=officer_clean_vol($_POST['factor'] ?? '');
            if ($name==='') throw new Exception('กรุณาระบุชื่อรายการ');
            $pdo->prepare("INSERT INTO removal_event_item (event_id,name_tiem,unit,factor,qty) VALUES (?,?,?,?,0)")
                ->execute([$eid,$name,$unit,$factor]);
            header("Location: $redir&event=$eid&msg=".urlencode('เพิ่มรายการดูดกลับแล้ว').$DETAIL); exit;
        }
        // ---- event: แก้ไขรายการดูดกลับ (ชื่อ/หน่วย/factor) ของกิจกรรมนี้ ----
        if ($action === 'edit_event_removal') {
            $eid=(int)$_POST['event_id']; $reid=(int)$_POST['rei_id'];
            $row=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if (!$row) throw new Exception('ไม่พบกิจกรรม');
            if (!$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $name=trim($_POST['name'] ?? ''); $unit=trim($_POST['unit'] ?? ''); officer_require_valid_vols([$_POST['factor'] ?? ''], 'ค่าดูดกลับ', true); $factor=officer_clean_vol($_POST['factor'] ?? '');
            if ($name==='') throw new Exception('กรุณาระบุชื่อรายการ');
            $pdo->prepare("UPDATE removal_event_item SET name_tiem=?, unit=?, factor=? WHERE id=? AND event_id=?")
                ->execute([$name,$unit,$factor,$reid,$eid]);
            header("Location: $redir&event=$eid&msg=".urlencode('แก้ไขรายการแล้ว').$DETAIL); exit;
        }
        // ---- event: ลบรายการดูดกลับของกิจกรรมนี้ ----
        if ($action === 'delete_event_removal') {
            $eid=(int)$_POST['event_id']; $reid=(int)$_POST['rei_id'];
            $row=$pdo->prepare("SELECT affiliation_id FROM event WHERE id=?"); $row->execute([$eid]); $row=$row->fetch();
            if ($row && !$is_admin && (int)$row['affiliation_id']!==(int)$lock_affil) throw new Exception('ไม่มีสิทธิ์');
            $pdo->prepare("DELETE FROM removal_event_item WHERE id=? AND event_id=?")->execute([$reid,$eid]);
            header("Location: $redir&event=$eid&msg=".urlencode('ลบรายการแล้ว').$DETAIL); exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash='ผิดพลาด: '.safe_error_message($e); $flash_t='danger';
    }
}
if (isset($_GET['msg'])) $flash = $_GET['msg'];

// ── โหลดข้อมูล ──
// รายการของทั้งสองแท็บโหลดเสมอ → การ์ดสรุป 3 ใบ + ตัวเลขบนแท็บ ตรงกันทุกแท็บ
//   admin: ทุกคณะ · officer: เฉพาะคณะตัวเอง
$questionnaires = collect_survey_list($pdo, $selected_year, $is_admin ? null : (int)$survey_affil);
$events         = collect_event_list($pdo, $selected_year, $is_admin ? null : (int)$lock_affil);
$totals         = collect_totals($questionnaires, $events);

$rows = []; $curEvent = null; $curVol = []; $ef_items = []; $rm_rows = [];
$sel_survey_exists = false; $survey_affil_name = ''; $org_options = [];
if ($is_survey) {
    // แบบสอบถามที่เลือกอยู่มีจริงไหม (ตรงทั้งชื่อ + คณะเจ้าของ เพราะชื่อซ้ำข้ามคณะได้)
    foreach ($questionnaires as $qq) { if ($qq['name'] === $group && (int)$qq['affid'] === (int)$survey_affil) { $sel_survey_exists = true; break; } }

    // ชื่อคณะของ "ผู้จัดทำ" ปัจจุบัน — แสดงใต้ชื่อแบบสอบถามในส่วนรายละเอียด
    $snm = $pdo->prepare("SELECT affiliation_item FROM affiliation_id WHERE id=?");
    $snm->execute([$survey_affil]);
    $survey_affil_name = (string)($snm->fetchColumn() ?: '');

    $it = $pdo->prepare("
        SELECT qi.id AS qiid, ai.name_tiem AS label, qi.admin_item_id, ai.unit, ai.AD, ag.scope,
               ai.scope AS group_id, ag.name_tiem AS group_name,
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
} else { // event
    // ตัวเลือกผู้จัดสำหรับฟอร์มเพิ่มกิจกรรม (admin) = คณะในระบบ + ผู้จัดอิสระที่เคยกรอกในปีนี้
    if ($is_admin) {
        foreach ($affils as $a) $org_options[] = ['value'=>'aff:'.$a['id'], 'label'=>$a['affiliation_item']];
        $cst = $pdo->prepare("SELECT DISTINCT organizer_name FROM event WHERE year_id=? AND organizer_name IS NOT NULL AND organizer_name<>''");
        $cst->execute([$selected_year]);
        foreach ($cst->fetchAll(PDO::FETCH_COLUMN) as $cn) $org_options[] = ['value'=>'custom:'.$cn, 'label'=>$cn];
    }
    if ($sel_event) {
        foreach ($events as $e) if ((int)$e['id']===$sel_event) { $curEvent=$e; break; }
    }
    if ($curEvent) {
        $it=$pdo->prepare("SELECT ei.admin_item_id, ei.Vol FROM event_item ei WHERE ei.event_id=?");
        $it->execute([$sel_event]);
        foreach ($it->fetchAll() as $ci) $curVol[(int)$ci['admin_item_id']] = (float)$ci['Vol']; // map ค่า Vol เดิมไว้ prefill
        // รายการ EF เฉพาะของกิจกรรมที่เลือก (ผูกด้วย event_id) — ไม่แชร์ข้ามกิจกรรม
        $ef=$pdo->prepare("SELECT ai.id, ai.name_tiem, ai.unit, ai.AD, ag.scope, ai.scope AS group_id, ag.name_tiem AS group_name FROM admin_item ai JOIN admin_g ag ON ag.id=ai.scope
            WHERE ai.data_source='event' AND ai.event_id=? ORDER BY ag.scope, ai.name_tiem");
        $ef->execute([$sel_event]); $ef_items=$ef->fetchAll();
        // รายการดูดกลับ "เฉพาะของกิจกรรมนี้" (แยกขาดจากหน้ากลาง — เก็บ factor ในตัวเอง)
        $rme=$pdo->prepare("SELECT id AS rid, name_tiem, unit, factor, qty, qty*factor/1000 AS emission
            FROM removal_event_item WHERE event_id=? ORDER BY name_tiem");
        $rme->execute([$sel_event]); $rm_rows=$rme->fetchAll();
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$arrow = '<span class="oe-arrow"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg></span>';
$svg_edit = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
$svg_del  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
$svg_clip = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
$svg_save = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>';
$i = 0;   // ลำดับแอนิเมชันลอยขึ้น
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กรอกข้อมูล — UP Net Zero</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/collect.css<?= asset_v('assets/css/collect.css') ?>">
</head>
<body style="background:#F6F4F9;">
    <?php include $SIDEBAR; ?>
    <main class="main-content">
        <?php include $HEADER; ?>
        <?php $toast_msg = $flash; $toast_type = $flash_t; include __DIR__ . '/../components/toast.php'; ?>

        <div class="oe-page co-page">
            <!-- หัวหน้า + ปี -->
            <div class="oe-head oe-rise">
                <div>
                    <h1 class="oe-title">แบบสอบถาม &amp; กิจกรรม</h1>
                    <div class="oe-sub"><?= $is_admin ? 'จัดการแบบสอบถามและกิจกรรมของทุกหน่วยงาน' : 'แบบสอบถามและกิจกรรมของหน่วยงานคุณ' ?> · ยอดปล่อยรวมเข้าขอบเขต 3</div>
                </div>
                <div class="oe-actions">
                    <span class="co-year-label">ปีงบประมาณ</span>
                    <?php $dd_id='coYear';$dd_name='year_nav';$dd_options=array_map(fn($y)=>['value'=>$y['year_id'],'label'=>(string)$y['year']],$years);
                        $dd_selected=$selected_year;$dd_required=false;$dd_class='dd-field';$dd_placeholder='เลือกปี';$dd_style='width:120px;';
                        include __DIR__.'/../components/dropdown.php'; ?>
                </div>
            </div>

            <!-- การ์ดสรุป -->
            <div class="co-kpis">
                <div class="co-kpi oe-rise" style="--i:1;">
                    <span class="co-kpi-ic"><?= ic('survey', 22) ?></span>
                    <div><span class="co-kpi-label">แบบสอบถาม</span>
                        <b class="co-kpi-val" data-co-kpi="survey"><?= collect_fmt($totals['survey']) ?> <small>tCO₂e</small></b>
                        <span class="co-kpi-sub"><?= count($questionnaires) ?> แบบสอบถาม</span></div>
                </div>
                <div class="co-kpi oe-rise" style="--i:2;">
                    <span class="co-kpi-ic"><?= ic('factory', 22) ?></span>
                    <div><span class="co-kpi-label">กิจกรรม · ปล่อย</span>
                        <b class="co-kpi-val" data-co-kpi="emit"><?= collect_fmt($totals['emit']) ?> <small>tCO₂e</small></b>
                        <span class="co-kpi-sub"><?= count($events) ?> กิจกรรม</span></div>
                </div>
                <div class="co-kpi co-kpi-green oe-rise" style="--i:3;">
                    <span class="co-kpi-ic"><?= ic('leaf', 22) ?></span>
                    <div><span class="co-kpi-label">กิจกรรม · ดูดกลับ</span>
                        <b class="co-kpi-val" data-co-kpi="removal"><?= collect_fmt($totals['removal']) ?> <small>tCO₂e</small></b>
                        <span class="co-kpi-sub">รวมเข้า GHG Removal ของมหาวิทยาลัย</span></div>
                </div>
            </div>

            <!-- แท็บ (แถบม่วงเลื่อนตามแท็บที่เลือก) -->
            <nav class="co-tabs oe-rise" style="--i:4;" data-tab="<?= $tab ?>" aria-label="เลือกประเภทข้อมูล">
                <span class="co-tab-ind" aria-hidden="true"></span>
                <?php foreach ($TABS as $k=>$lbl):
                    $href="collect.php?year=$selected_year&tab=$k" . ($k==='survey' ? '&group='.urlencode($group) : ''); ?>
                    <a class="co-tab<?= $tab===$k ? ' is-on' : '' ?>" href="<?= $h($href) ?>" data-tab="<?= $k ?>" data-de-guard<?= $tab===$k ? ' aria-current="page"' : '' ?>>
                        <?= $lbl ?> <span class="co-tab-n"><?= $k==='survey' ? count($questionnaires) : count($events) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($is_survey): /* ===== แท็บ แบบสอบถาม ===== */ ?>
            <section class="oe-panel co-list oe-rise" style="--i:5;">
                <div class="co-panel-head">
                    <h2 class="co-h2">แบบสอบถามทั้งหมด <span class="co-count"><?= count($questionnaires) ?></span></h2>
                    <button type="button" class="oe-btn" onclick="coSurveyOpen(null)"><?= ic('add', 16) ?> เพิ่มแบบสอบถาม</button>
                </div>
                <?php if (empty($questionnaires)): ?>
                    <div class="oe-empty co-empty">
                        <h3>ยังไม่มีแบบสอบถามในปีนี้</h3>
                        <p>สร้างแบบสอบถาม เช่น นักศึกษา / บุคลากร แล้วเพิ่มหัวข้อคำถามและกรอกผลสรุป</p>
                        <button type="button" class="oe-btn oe-btn-success" onclick="coSurveyOpen(null)"><?= ic('add', 16) ?> เพิ่มแบบสอบถามแรก</button>
                    </div>
                <?php else: ?>
                <div class="co-cards">
                    <?php foreach ($questionnaires as $k => $qq):
                        $isSelQ = ($qq['name']===$group && (int)$qq['affid']===(int)$survey_affil);
                        $href = $isSelQ ? $qs() : $qs(['group'=>$qq['name'],'maker'=>(int)$qq['affid']]) . $DETAIL; ?>
                    <article class="co-card oe-rise<?= $isSelQ ? ' is-selected' : '' ?>" style="--i:<?= min($k, 10) + 5 ?>;">
                        <a class="co-card-main" href="<?= $h($href) ?>" data-de-guard>
                            <?php if ($is_admin): ?><span class="co-card-kicker" title="<?= $h($qq['maker_name']) ?>">ผู้จัดทำ: <?= $h($qq['maker_name']) ?></span><?php endif; ?>
                            <span class="co-card-name" title="<?= $h($qq['name']) ?>"><?= $h($qq['name']) ?></span>
                            <span class="co-card-stats">
                                <span><small>กรอกแล้ว</small><b><?= (int)$qq['filled_count'] ?> / <?= (int)$qq['item_count'] ?> หัวข้อ</b></span>
                                <span><small>tCO₂e</small><b class="is-purple"><?= collect_fmt((float)$qq['tco2e']) ?></b></span>
                            </span>
                        </a>
                        <div class="co-card-foot">
                            <a class="co-card-go" href="<?= $h($href) ?>" data-de-guard tabindex="-1"><?= $isSelQ ? 'ปิดรายละเอียด' : 'กรอกข้อมูล ' . $arrow ?></a>
                            <div class="co-card-tools">
                                <button type="button" class="ev-open-btn" data-ev="questionnaire:<?= (int)$qq['qid'] ?>" title="แนบหลักฐาน (ไฟล์/ลิงก์)"
                                    onclick="openEvidence({type:'questionnaire', id:<?= (int)$qq['qid'] ?>, title:<?= $h(json_encode($qq['name'], JSON_UNESCAPED_UNICODE)) ?>})">
                                    <?= $svg_clip ?><?php if ((int)$qq['ev_count'] > 0): ?><span class="ev-badge"><?= (int)$qq['ev_count'] ?></span><?php endif; ?>
                                </button>
                                <button type="button" class="oe-icon-btn oe-icon-edit" title="แก้ไขชื่อ" aria-label="แก้ไขชื่อแบบสอบถาม <?= $h($qq['name']) ?>"
                                    data-name="<?= $h($qq['name']) ?>" data-affid="<?= (int)$qq['affid'] ?>" onclick="coSurveyOpen(this)"><?= $svg_edit ?></button>
                                <form method="POST" data-msg="<?= $h('ลบแบบสอบถาม "' . $qq['name'] . '" ทั้งหมด รวมหัวข้อและผลที่กรอกไว้ — ไม่สามารถกู้คืนได้') ?>" onsubmit="return cfmForm(event, this, this.dataset.msg)"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_questionnaire"><input type="hidden" name="tab" value="survey"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="maker" value="<?= (int)$qq['affid'] ?>"><input type="hidden" name="q_name" value="<?= $h($qq['name']) ?>">
                                    <button type="submit" class="oe-icon-btn oe-icon-del" title="ลบแบบสอบถาม" aria-label="ลบแบบสอบถาม <?= $h($qq['name']) ?>"><?= $svg_del ?></button>
                                </form>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <?php if ($sel_survey_exists):
                $s_total = array_sum(array_map(fn($r) => (float)$r['emission'], $rows));
                $s_filled = count(array_filter($rows, fn($r) => (int)$r['respondents'] > 0 && (float)$r['avg_value'] > 0)); ?>
            <section class="co-detail oe-rise" id="co-detail">
                <div class="co-detail-head">
                    <div class="co-detail-title">
                        <span class="co-eyebrow"><?= ic('survey', 14) ?> แบบสอบถาม</span>
                        <h2><?= $h($group) ?></h2>
                        <div class="oe-sub"><?= $survey_affil_name !== '' ? 'ผู้จัดทำ: ' . $h($survey_affil_name) . ' · ' : '' ?>กรอกจำนวนผู้ตอบและค่าเฉลี่ยต่อคนของแต่ละหัวข้อ</div>
                    </div>
                    <div class="oe-actions">
                        <button type="button" class="oe-btn oe-btn-soft" onclick="coTopicOpen(null)"><?= ic('add', 16) ?> เพิ่มหัวข้อ</button>
                        <a class="oe-btn oe-btn-ghost" href="<?= $h($qs()) ?>" data-de-guard>ปิด</a>
                    </div>
                </div>
                <?php if (empty($rows)): ?>
                    <div class="oe-empty co-empty">
                        <h3>ยังไม่มีหัวข้อในแบบสอบถามนี้</h3>
                        <p>เพิ่มหัวข้อคำถาม เช่น ระยะทางเดินทางมามหาวิทยาลัย พร้อมหน่วยและค่า EF</p>
                        <button type="button" class="oe-btn oe-btn-success" onclick="coTopicOpen(null)"><?= ic('add', 16) ?> เพิ่มหัวข้อแรก</button>
                    </div>
                <?php else: ?>
                <div class="co-sec" data-de-scope data-de-digits="3">
                    <form method="POST" id="coSurveyForm"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_survey"><input type="hidden" name="tab" value="survey">
                        <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="group" value="<?= $h($group) ?>"><input type="hidden" name="maker" value="<?= (int)$survey_affil ?>">
                        <table class="oe-table">
                            <colgroup><col><col style="width:128px;"><col style="width:140px;"><col style="width:104px;"><col style="width:108px;"><col style="width:86px;"></colgroup>
                            <thead><tr><th>หัวข้อ</th><th>จำนวนผู้ตอบ (คน)</th><th>ค่าเฉลี่ยต่อคน</th><th>EF<br><span class="oe-muted">kgCO₂e/หน่วย</span></th><th>tCO₂e</th><th>จัดการ</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): $filled = (int)$r['respondents'] > 0 && (float)$r['avg_value'] > 0; ?>
                                <tr class="item-row<?= $filled ? ' is-filled' : '' ?>">
                                    <td><?= $h($r['label']) ?><span class="co-row-sub"><?= $h($r['group_name']) ?> · หน่วย: <?= $h($r['unit'] ?: '-') ?></span></td>
                                    <td data-label="จำนวนผู้ตอบ (คน)"><input type="text" inputmode="numeric" autocomplete="off" class="vol-input oe-input" data-int="1" data-group="survey" data-ef="<?= (float)$r['AD'] ?>"
                                        name="resp[<?= (int)$r['qiid'] ?>]" value="<?= (int)$r['respondents'] ?: '' ?>" placeholder="0" aria-label="จำนวนผู้ตอบ: <?= $h($r['label']) ?>"></td>
                                    <td data-label="ค่าเฉลี่ยต่อคน (<?= $h($r['unit'] ?: 'หน่วย') ?>)"><input type="text" inputmode="decimal" autocomplete="off" class="vol-input oe-input"
                                        name="avg[<?= (int)$r['qiid'] ?>]" value="<?= collect_input_val((float)$r['avg_value']) ?>" placeholder="0" aria-label="ค่าเฉลี่ยต่อคน: <?= $h($r['label']) ?>"><span class="de-err" role="alert"></span></td>
                                    <td class="oe-c oe-ef" data-label="EF (kgCO₂e/หน่วย)"><?= ef_fmt($r['AD']) ?></td>
                                    <td data-label="tCO₂e"><span class="total-pill"><?= collect_fmt((float)$r['emission'], 4) ?></span></td>
                                    <td class="oe-c co-tools-cell">
                                        <button type="button" class="oe-icon-btn oe-icon-edit" title="แก้ไขหัวข้อ"
                                            data-qiid="<?= (int)$r['qiid'] ?>" data-label="<?= $h($r['label']) ?>" data-unit="<?= $h($r['unit']) ?>"
                                            data-group="<?= (int)$r['group_id'] ?>" data-groupname="<?= $h('ขอบเขต '.$r['scope'].' · '.$r['group_name']) ?>" data-ad="<?= ef_input_val($r['AD']) ?>"
                                            onclick="coTopicOpen(this)"><?= $svg_edit ?></button>
                                        <button type="button" class="oe-icon-btn oe-icon-del" title="ลบหัวข้อ" onclick="coDeleteRow('delTopic<?= (int)$r['qiid'] ?>', 'ลบหัวข้อนี้? ผลที่กรอกไว้ของหัวข้อนี้จะถูกลบด้วย')"><?= $svg_del ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="oe-dock">
                            <div class="oe-dock-info">
                                <div class="oe-dock-stat"><span>ยอดรวมแบบสอบถามนี้</span><b class="is-purple"><span data-de-total><?= collect_fmt($s_total) ?></span> tCO₂e</b></div>
                                <div class="oe-dock-stat"><span>กรอกแล้ว</span><b><span data-de-filled><?= $s_filled ?> / <?= count($rows) ?></span> หัวข้อ</b></div>
                                <span class="oe-dirty" data-de-dirty hidden>● ยังไม่บันทึก</span>
                                <span class="oe-msg" data-de-msg role="alert"></span>
                            </div>
                            <button type="button" class="oe-btn oe-btn-success" onclick="deSave(this)"><?= $svg_save ?> บันทึกแบบสอบถาม</button>
                        </div>
                    </form>
                    <?php foreach ($rows as $r): ?>
                    <form method="POST" id="delTopic<?= (int)$r['qiid'] ?>" hidden><?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_topic"><input type="hidden" name="tab" value="survey">
                        <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="maker" value="<?= (int)$survey_affil ?>"><input type="hidden" name="group" value="<?= $h($group) ?>">
                        <input type="hidden" name="qitem_id" value="<?= (int)$r['qiid'] ?>">
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; /* sel_survey_exists */ ?>

            <?php else: /* ===== แท็บ กิจกรรม ===== */ ?>
            <section class="oe-panel co-list oe-rise" style="--i:5;">
                <div class="co-panel-head">
                    <h2 class="co-h2">กิจกรรมทั้งหมด <span class="co-count"><?= count($events) ?></span></h2>
                    <button type="button" class="oe-btn" onclick="coEventOpen(null)"><?= ic('add', 16) ?> เพิ่มกิจกรรม</button>
                </div>
                <?php if (empty($events)): ?>
                    <div class="oe-empty co-empty">
                        <h3>ยังไม่มีกิจกรรมในปีนี้</h3>
                        <p>เพิ่มกิจกรรม เช่น งานรับน้อง แล้วกรอกปริมาณการปล่อยหรือการดูดกลับคาร์บอน</p>
                        <button type="button" class="oe-btn oe-btn-success" onclick="coEventOpen(null)"><?= ic('add', 16) ?> เพิ่มกิจกรรมแรก</button>
                    </div>
                <?php else: ?>
                <div class="co-cards">
                    <?php foreach ($events as $k => $e):
                        $isSel = (int)$e['id'] === $sel_event;
                        $href = $isSel ? $qs() : $qs(['event'=>$e['id']]) . $DETAIL;
                        $topics = (int)$e['emit_topics'] + (int)$e['removal_count']; ?>
                    <article class="co-card oe-rise<?= $isSel ? ' is-selected' : '' ?>" style="--i:<?= min($k, 10) + 5 ?>;">
                        <a class="co-card-main" href="<?= $h($href) ?>" data-de-guard>
                            <span class="co-card-kicker" title="<?= $h($e['org_label']) ?>"><?= $h(collect_date_range($e['event_date'], $e['event_end_date'])) ?><?= $is_admin ? ' · ' . $h($e['org_label']) : '' ?></span>
                            <span class="co-card-name" title="<?= $h($e['name']) ?>"><?= $h($e['name']) ?></span>
                            <span class="co-card-stats">
                                <span><small>รายการ</small><b><?= $topics ?></b></span>
                                <span><small>ปล่อย tCO₂e</small><b class="is-purple"><?= collect_fmt((float)$e['tco2e']) ?></b></span>
                                <?php if ((int)$e['removal_count'] > 0): ?><span><small>ดูดกลับ tCO₂e</small><b class="is-green"><?= collect_fmt((float)$e['removal_tco2e']) ?></b></span><?php endif; ?>
                            </span>
                        </a>
                        <div class="co-card-foot">
                            <a class="co-card-go" href="<?= $h($href) ?>" data-de-guard tabindex="-1"><?= $isSel ? 'ปิดรายละเอียด' : 'กรอกข้อมูล ' . $arrow ?></a>
                            <div class="co-card-tools">
                                <button type="button" class="ev-open-btn" data-ev="event:<?= (int)$e['id'] ?>" title="แนบหลักฐาน (ไฟล์/ลิงก์)"
                                    onclick="openEvidence({type:'event', id:<?= (int)$e['id'] ?>, title:<?= $h(json_encode($e['name'], JSON_UNESCAPED_UNICODE)) ?>})">
                                    <?= $svg_clip ?><?php if ((int)$e['ev_count'] > 0): ?><span class="ev-badge"><?= (int)$e['ev_count'] ?></span><?php endif; ?>
                                </button>
                                <button type="button" class="oe-icon-btn oe-icon-edit" title="แก้ไขกิจกรรม" aria-label="แก้ไขกิจกรรม <?= $h($e['name']) ?>"
                                    data-eid="<?= (int)$e['id'] ?>" data-name="<?= $h($e['name']) ?>" data-date="<?= $h($e['event_date']) ?>" data-end="<?= $h($e['event_end_date']) ?>"
                                    onclick="coEventOpen(this)"><?= $svg_edit ?></button>
                                <form method="POST" data-msg="<?= $h('ลบกิจกรรม "' . $e['name'] . '" พร้อมรายการและปริมาณทั้งหมด — ไม่สามารถกู้คืนได้') ?>" onsubmit="return cfmForm(event, this, this.dataset.msg)"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_event"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                                    <button type="submit" class="oe-icon-btn oe-icon-del" title="ลบกิจกรรม" aria-label="ลบกิจกรรม <?= $h($e['name']) ?>"><?= $svg_del ?></button>
                                </form>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <?php if ($curEvent): ?>
            <section class="co-detail oe-rise" id="co-detail">
                <div class="co-detail-head">
                    <div class="co-detail-title">
                        <span class="co-eyebrow"><?= ic('note', 14) ?> กิจกรรม</span>
                        <h2><?= $h($curEvent['name']) ?></h2>
                        <div class="oe-sub">ผู้จัด: <?= $h($curEvent['org_label']) ?> · <?= $h(collect_date_range($curEvent['event_date'], $curEvent['event_end_date'])) ?></div>
                    </div>
                    <div class="oe-actions">
                        <button type="button" class="oe-btn oe-btn-soft" onclick="coEvTopicOpen(null)"><?= ic('factory', 16) ?> เพิ่มรายการปล่อย</button>
                        <button type="button" class="oe-btn oe-btn-soft co-btn-green" onclick="coRemovalOpen(null)"><?= ic('leaf', 16) ?> เพิ่มรายการดูดกลับ</button>
                        <a class="oe-btn oe-btn-ghost" href="<?= $h($qs()) ?>" data-de-guard>ปิด</a>
                    </div>
                </div>

                <?php if (empty($ef_items) && empty($rm_rows)): ?>
                    <div class="oe-empty co-empty">
                        <h3>ยังไม่มีรายการในกิจกรรมนี้</h3>
                        <p>เพิ่มรายการที่ปล่อยคาร์บอน (เช่น การเดินทางของผู้เข้าร่วม) หรือรายการดูดกลับ (เช่น ปลูกต้นไม้)</p>
                        <div class="oe-actions" style="justify-content:center;">
                            <button type="button" class="oe-btn" onclick="coEvTopicOpen(null)"><?= ic('factory', 16) ?> เพิ่มรายการปล่อย</button>
                            <button type="button" class="oe-btn oe-btn-success" onclick="coRemovalOpen(null)"><?= ic('leaf', 16) ?> เพิ่มรายการดูดกลับ</button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($ef_items)):
                    $e_total = 0; $e_filled = 0;
                    foreach ($ef_items as $x) { $v = $curVol[(int)$x['id']] ?? 0; $e_total += $v * (float)$x['AD'] / 1000; if ($v > 0) $e_filled++; } ?>
                <div class="co-sec" data-de-scope data-de-digits="3">
                    <div class="co-sec-title">ปล่อยคาร์บอน <small>ขอบเขต 3 · กรอกเฉพาะรายการที่เกี่ยวข้อง เว้นว่าง = ไม่นับ</small></div>
                    <form method="POST" id="coEventForm"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_event_items"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= (int)$curEvent['id'] ?>">
                        <table class="oe-table">
                            <colgroup><col><col style="width:118px;"><col style="width:150px;"><col style="width:108px;"><col style="width:86px;"></colgroup>
                            <thead><tr><th>รายการ</th><th>EF<br><span class="oe-muted">kgCO₂e/หน่วย</span></th><th>ปริมาณ</th><th>tCO₂e</th><th>จัดการ</th></tr></thead>
                            <tbody>
                            <?php foreach ($ef_items as $x): $v = $curVol[(int)$x['id']] ?? 0; ?>
                                <tr class="item-row<?= $v > 0 ? ' is-filled' : '' ?>">
                                    <td><?= $h($x['name_tiem']) ?><span class="co-row-sub"><?= $h($x['group_name']) ?> · หน่วย: <?= $h($x['unit'] ?: '-') ?></span></td>
                                    <td class="oe-c oe-ef" data-label="EF (kgCO₂e/หน่วย)"><?= ef_fmt($x['AD']) ?></td>
                                    <td class="oe-vol" data-label="ปริมาณ (<?= $h($x['unit'] ?: 'หน่วย') ?>)"><input type="text" inputmode="decimal" autocomplete="off" class="vol-input oe-input" data-group="emit" data-ef="<?= (float)$x['AD'] ?>"
                                        name="vol[<?= (int)$x['id'] ?>]" value="<?= collect_input_val((float)$v) ?>" placeholder="0" aria-label="ปริมาณ: <?= $h($x['name_tiem']) ?>"><span class="de-err" role="alert"></span></td>
                                    <td data-label="tCO₂e"><span class="total-pill"><?= collect_fmt($v * (float)$x['AD'] / 1000, 4) ?></span></td>
                                    <td class="oe-c co-tools-cell">
                                        <button type="button" class="oe-icon-btn oe-icon-edit" title="แก้ไขรายการ"
                                            data-aiid="<?= (int)$x['id'] ?>" data-label="<?= $h($x['name_tiem']) ?>" data-unit="<?= $h($x['unit']) ?>"
                                            data-group="<?= (int)$x['group_id'] ?>" data-groupname="<?= $h('ขอบเขต '.$x['scope'].' · '.$x['group_name']) ?>" data-ad="<?= ef_input_val($x['AD']) ?>"
                                            onclick="coEvTopicOpen(this)"><?= $svg_edit ?></button>
                                        <button type="button" class="oe-icon-btn oe-icon-del" title="ลบรายการ" onclick="coDeleteRow('delEvTopic<?= (int)$x['id'] ?>', 'ลบรายการนี้ออกจากกิจกรรม พร้อมปริมาณที่กรอกไว้?')"><?= $svg_del ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="oe-dock">
                            <div class="oe-dock-info">
                                <div class="oe-dock-stat"><span>ยอดปล่อยของกิจกรรมนี้</span><b class="is-purple"><span data-de-total><?= collect_fmt($e_total) ?></span> tCO₂e</b></div>
                                <div class="oe-dock-stat"><span>กรอกแล้ว</span><b><span data-de-filled><?= $e_filled ?> / <?= count($ef_items) ?></span> รายการ</b></div>
                                <span class="oe-dirty" data-de-dirty hidden>● ยังไม่บันทึก</span>
                                <span class="oe-msg" data-de-msg role="alert"></span>
                            </div>
                            <button type="button" class="oe-btn oe-btn-success" onclick="deSave(this)"><?= $svg_save ?> บันทึกการปล่อย</button>
                        </div>
                    </form>
                    <?php foreach ($ef_items as $x): ?>
                    <form method="POST" id="delEvTopic<?= (int)$x['id'] ?>" hidden><?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_event_topic"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                        <input type="hidden" name="admin_item_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="event_id" value="<?= (int)$curEvent['id'] ?>">
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($rm_rows)):
                    $r_total = array_sum(array_map(fn($m) => (float)$m['emission'], $rm_rows));
                    $r_filled = count(array_filter($rm_rows, fn($m) => (float)$m['qty'] > 0)); ?>
                <div class="co-sec co-sec-green" data-de-scope data-de-digits="3">
                    <div class="co-sec-title">ดูดกลับคาร์บอน <small>ยอดรวมเข้า GHG Removal ของมหาวิทยาลัยอัตโนมัติ</small></div>
                    <form method="POST" id="coRemovalForm"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_event_removal"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= (int)$curEvent['id'] ?>">
                        <table class="oe-table">
                            <colgroup><col><col style="width:118px;"><col style="width:150px;"><col style="width:108px;"><col style="width:86px;"></colgroup>
                            <thead><tr><th>รายการดูดกลับ</th><th>ค่าดูดกลับ<br><span class="oe-muted">kgCO₂e/หน่วย</span></th><th>ปริมาณ</th><th>tCO₂e</th><th>จัดการ</th></tr></thead>
                            <tbody>
                            <?php foreach ($rm_rows as $m): $q = (float)$m['qty']; ?>
                                <tr class="item-row<?= $q > 0 ? ' is-filled' : '' ?>">
                                    <td><?= $h($m['name_tiem']) ?><span class="co-row-sub">หน่วย: <?= $h($m['unit'] ?: '-') ?></span></td>
                                    <td class="oe-c oe-ef" data-label="ค่าดูดกลับ (kgCO₂e/หน่วย)"><?= ef_fmt($m['factor']) ?></td>
                                    <td class="oe-vol" data-label="ปริมาณ (<?= $h($m['unit'] ?: 'หน่วย') ?>)"><input type="text" inputmode="decimal" autocomplete="off" class="vol-input oe-input" data-group="removal" data-ef="<?= (float)$m['factor'] ?>"
                                        name="qty[<?= (int)$m['rid'] ?>]" value="<?= collect_input_val($q) ?>" placeholder="0" aria-label="ปริมาณ: <?= $h($m['name_tiem']) ?>"><span class="de-err" role="alert"></span></td>
                                    <td data-label="tCO₂e"><span class="total-pill"><?= collect_fmt((float)$m['emission'], 4) ?></span></td>
                                    <td class="oe-c co-tools-cell">
                                        <button type="button" class="oe-icon-btn oe-icon-edit" title="แก้ไขรายการ"
                                            data-rid="<?= (int)$m['rid'] ?>" data-name="<?= $h($m['name_tiem']) ?>" data-unit="<?= $h($m['unit']) ?>" data-factor="<?= ef_input_val($m['factor']) ?>"
                                            onclick="coRemovalOpen(this)"><?= $svg_edit ?></button>
                                        <button type="button" class="oe-icon-btn oe-icon-del" title="เอาออกจากกิจกรรม" onclick="coDeleteRow('delEvRm<?= (int)$m['rid'] ?>', 'เอารายการดูดกลับนี้ออกจากกิจกรรม?')"><?= $svg_del ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="oe-dock">
                            <div class="oe-dock-info">
                                <div class="oe-dock-stat"><span>ยอดดูดกลับของกิจกรรมนี้</span><b class="is-purple"><span data-de-total><?= collect_fmt($r_total) ?></span> tCO₂e</b></div>
                                <div class="oe-dock-stat"><span>กรอกแล้ว</span><b><span data-de-filled><?= $r_filled ?> / <?= count($rm_rows) ?></span> รายการ</b></div>
                                <span class="oe-dirty" data-de-dirty hidden>● ยังไม่บันทึก</span>
                                <span class="oe-msg" data-de-msg role="alert"></span>
                            </div>
                            <button type="button" class="oe-btn oe-btn-success" onclick="deSave(this)"><?= $svg_save ?> บันทึกการดูดกลับ</button>
                        </div>
                    </form>
                    <?php foreach ($rm_rows as $m): ?>
                    <form method="POST" id="delEvRm<?= (int)$m['rid'] ?>" hidden><?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_event_removal"><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= (int)$curEvent['id'] ?>"><input type="hidden" name="rei_id" value="<?= (int)$m['rid'] ?>">
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; /* curEvent */ ?>
            <?php endif; /* tab */ ?>
        </div>

        <!-- ═════ หน้าต่าง (เพิ่ม/แก้ไข) ═════ -->
        <?php if ($is_survey): ?>
        <!-- แบบสอบถาม: เพิ่ม / แก้ไขชื่อ -->
        <div class="modal-overlay" id="coSurveyModal">
            <div class="modal-box co-modal">
                <div class="modal-title"><span data-co-icon></span><span data-co-title>เพิ่มแบบสอบถาม</span></div>
                <form method="POST" data-co-form><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_questionnaire" data-co-action><input type="hidden" name="tab" value="survey"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                    <input type="hidden" name="maker" value="<?= (int)$survey_affil ?>" id="coSurveyMakerH"><input type="hidden" name="q_old" id="coSurveyOld">
                    <?php if ($is_admin): ?>
                    <div class="form-group-dark" id="coSurveyMakerField"><label class="form-label-dark">ผู้จัดทำ (หน่วยงานเจ้าของ) *</label>
                        <?php $dd_id='coSurveyMaker';$dd_name='maker';$dd_options=array_map(fn($a)=>['value'=>$a['id'],'label'=>$a['affiliation_item']],$affils);
                            $dd_selected=$survey_affil;$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือกผู้จัดทำ';$dd_style='width:100%;';
                            include __DIR__.'/../components/dropdown.php'; ?>
                    </div>
                    <?php endif; ?>
                    <div class="form-group-dark"><label class="form-label-dark" for="coSurveyName">ชื่อแบบสอบถาม *</label>
                        <input class="form-control-dark" id="coSurveyName" name="q_name" required maxlength="100" placeholder="เช่น นักศึกษา / บุคลากร / แบบสอบถามการเดินทาง" autocomplete="off"></div>
                    <p class="co-modal-msg" data-co-msg role="alert"></p>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('coSurveyModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary" data-co-submit>เพิ่มแบบสอบถาม</button>
                    </div>
                </form>
            </div>
        </div>
        <?php if ($sel_survey_exists): ?>
        <!-- หัวข้อแบบสอบถาม: เพิ่ม / แก้ไข -->
        <div class="modal-overlay" id="coTopicModal">
            <div class="modal-box co-modal co-modal-wide">
                <div class="modal-title"><span data-co-icon></span><span data-co-title>เพิ่มหัวข้อ</span></div>
                <form method="POST" data-co-form data-co-dd="coTopicGroup"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_topic" data-co-action><input type="hidden" name="tab" value="survey">
                    <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="maker" value="<?= (int)$survey_affil ?>"><input type="hidden" name="group" value="<?= $h($group) ?>">
                    <input type="hidden" name="qitem_id" id="coTopicId">
                    <div class="co-form-grid">
                        <div class="form-group-dark co-span2"><label class="form-label-dark" for="coTopicLabel">คำถาม (ที่ผู้ตอบเห็นในฟอร์ม) *</label>
                            <input class="form-control-dark" id="coTopicLabel" name="label" required maxlength="100" placeholder="เช่น ระยะทางเดินทางมามหาวิทยาลัย (มอเตอร์ไซค์)" autocomplete="off"></div>
                        <div class="form-group-dark co-span2"><label class="form-label-dark">หมวดย่อย (ขอบเขต 3) *</label>
                            <?php $dd_id='coTopicGroup';$dd_name='group_id';$dd_options=admin_g_options($all_groups, INDIRECT_SCOPE);
                                $dd_selected='';$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือกหมวดย่อย';$dd_style='width:100%;';include __DIR__.'/../components/dropdown.php'; ?></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="coTopicUnit">หน่วย *</label>
                            <input class="form-control-dark" id="coTopicUnit" name="unit" required maxlength="100" placeholder="กม. / มื้อ / kg" autocomplete="off"></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="coTopicAd">ค่า EF (kgCO₂e/หน่วย) *</label>
                            <input class="form-control-dark" id="coTopicAd" name="ad" type="number" step="any" min="0" max="1000000" required placeholder="0.0000"></div>
                    </div>
                    <p class="co-modal-msg" data-co-msg role="alert"></p>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('coTopicModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary" data-co-submit>เพิ่มหัวข้อ</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <!-- กิจกรรม: เพิ่ม / แก้ไข -->
        <div class="modal-overlay" id="coEventModal">
            <div class="modal-box co-modal co-modal-wide">
                <div class="modal-title"><span data-co-icon></span><span data-co-title>เพิ่มกิจกรรม</span></div>
                <form method="POST" data-co-form data-co-dates><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_event" data-co-action><input type="hidden" name="tab" value="event"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                    <input type="hidden" name="event_id" id="coEventId">
                    <div class="form-group-dark"><label class="form-label-dark" for="coEventName">ชื่อกิจกรรม *</label>
                        <input class="form-control-dark" id="coEventName" name="name" required maxlength="255" placeholder="เช่น งานรับน้อง 2569" autocomplete="off"></div>
                    <?php if ($is_admin): ?>
                    <div class="form-group-dark" id="coEventOrgField"><label class="form-label-dark">ผู้จัด *</label>
                        <?php $org_dd=$org_options; $org_dd[]=['value'=>'__custom__','label'=>'อื่นๆ…'];
                            $dd_id='addOrg';$dd_name='org';$dd_options=$org_dd;$dd_selected=($org_options[0]['value']??'');$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือกผู้จัด';$dd_style='width:100%;';include __DIR__.'/../components/dropdown.php'; ?>
                        <input type="text" id="addOrgCustom" class="form-control-dark" style="display:none;margin-top:10px;" placeholder="พิมพ์ชื่อผู้จัด (อื่นๆ)" maxlength="255">
                    </div>
                    <?php endif; ?>
                    <div class="co-form-grid">
                        <div class="form-group-dark"><label class="form-label-dark" for="coEventStart">วันที่เริ่ม</label>
                            <input type="text" id="coEventStart" class="form-control-dark co-date" data-hidden="coEventStartH" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off">
                            <input type="hidden" name="event_date" id="coEventStartH"></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="coEventEnd">วันสิ้นสุด <span class="oe-muted">(ถ้ามี)</span></label>
                            <input type="text" id="coEventEnd" class="form-control-dark co-date" data-hidden="coEventEndH" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off">
                            <input type="hidden" name="event_end_date" id="coEventEndH"></div>
                    </div>
                    <p class="co-modal-msg" data-co-msg role="alert"></p>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('coEventModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary" data-co-submit>เพิ่มกิจกรรม</button>
                    </div>
                </form>
            </div>
        </div>
        <?php if ($curEvent): ?>
        <!-- รายการปล่อยของกิจกรรม: เพิ่ม / แก้ไข -->
        <div class="modal-overlay" id="coEvTopicModal">
            <div class="modal-box co-modal co-modal-wide">
                <div class="modal-title"><span data-co-icon></span><span data-co-title>เพิ่มรายการปล่อย</span></div>
                <form method="POST" data-co-form data-co-dd="coEvTopicGroup"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_event_topic" data-co-action><input type="hidden" name="tab" value="event">
                    <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= (int)$curEvent['id'] ?>"><input type="hidden" name="admin_item_id" id="coEvTopicId">
                    <div class="co-form-grid">
                        <div class="form-group-dark co-span2"><label class="form-label-dark" for="coEvTopicLabel">ชื่อรายการ *</label>
                            <input class="form-control-dark" id="coEvTopicLabel" name="label" required maxlength="100" placeholder="เช่น การเดินทางของผู้เข้าร่วม (มอเตอร์ไซค์)" autocomplete="off"></div>
                        <div class="form-group-dark co-span2"><label class="form-label-dark">หมวดย่อย (ขอบเขต 3) *</label>
                            <?php $dd_id='coEvTopicGroup';$dd_name='group_id';$dd_options=admin_g_options($all_groups, INDIRECT_SCOPE);
                                $dd_selected='';$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือกหมวดย่อย';$dd_style='width:100%;';include __DIR__.'/../components/dropdown.php'; ?></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="coEvTopicUnit">หน่วย *</label>
                            <input class="form-control-dark" id="coEvTopicUnit" name="unit" required maxlength="100" placeholder="กม. / คน / kg" autocomplete="off"></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="coEvTopicAd">ค่า EF (kgCO₂e/หน่วย) *</label>
                            <input class="form-control-dark" id="coEvTopicAd" name="ad" type="number" step="any" min="0" max="1000000" required placeholder="0.0000"></div>
                    </div>
                    <p class="co-modal-msg" data-co-msg role="alert"></p>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('coEvTopicModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary" data-co-submit>เพิ่มรายการ</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- รายการดูดกลับของกิจกรรม: เพิ่ม / แก้ไข -->
        <div class="modal-overlay" id="coRemovalModal">
            <div class="modal-box co-modal co-modal-wide">
                <div class="modal-title"><span data-co-icon></span><span data-co-title>เพิ่มรายการดูดกลับ</span></div>
                <form method="POST" data-co-form><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_removal_item" data-co-action><input type="hidden" name="tab" value="event">
                    <input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="event_id" value="<?= (int)$curEvent['id'] ?>"><input type="hidden" name="rei_id" id="coRemovalId">
                    <div class="form-group-dark"><label class="form-label-dark" for="coRemovalName">ชื่อรายการ *</label>
                        <input class="form-control-dark" id="coRemovalName" name="name" required maxlength="255" placeholder="เช่น ต้นไม้ยืนต้น / พื้นที่ป่า" autocomplete="off"></div>
                    <div class="co-form-grid">
                        <div class="form-group-dark"><label class="form-label-dark" for="coRemovalUnit">หน่วย</label>
                            <input class="form-control-dark" id="coRemovalUnit" name="unit" maxlength="100" placeholder="ต้น / ไร่" autocomplete="off"></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="coRemovalFactor">ค่าดูดกลับ (kgCO₂e/หน่วย/ปี) *</label>
                            <input class="form-control-dark" id="coRemovalFactor" name="factor" type="number" step="any" min="0" max="1000000" required placeholder="0.0000"></div>
                    </div>
                    <div class="oe-note">ค่าดูดกลับควรอ้างอิงค่ามาตรฐาน (เช่น TGO) — ยอดจะรวมเข้า GHG Removal ของมหาวิทยาลัยอัตโนมัติ</div>
                    <p class="co-modal-msg" data-co-msg role="alert"></p>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('coRemovalModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary" data-co-submit>เพิ่มรายการดูดกลับ</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <script src="<?= $root ?>assets/js/data-entry.js<?= asset_v('assets/js/data-entry.js') ?>"></script>
        <script>
        // ประกาศผ่าน window / var / IIFE — SPA รันสคริปต์ซ้ำทุกครั้งที่เข้าหน้านี้ (let/const ระดับบนสุดจะ error ประกาศซ้ำ)
        (function () {
            var d = document;
            var ICON_ADD = <?= json_encode(ic('add', 22)) ?>, ICON_EDIT = <?= json_encode(ic('edit', 22)) ?>;
            function $(id) { return d.getElementById(id); }

            window.openModal = function (id) { var m = $(id); if (m) { m.classList.add('open'); m.style.display = 'flex'; var box = m.querySelector('.modal-box'); if (box) { box.style.animation = 'none'; void box.offsetWidth; box.style.animation = ''; } } };
            window.closeModal = function (id) { var m = $(id); if (m) { m.classList.remove('open'); m.style.display = 'none'; } };
            d.querySelectorAll('.co-page ~ .modal-overlay').forEach(function (el) {
                el.addEventListener('click', function (e) { if (e.target === el) closeModal(el.id); });
            });
            if (!window.__coEsc) {
                window.__coEsc = true;
                d.addEventListener('keydown', function (e) {
                    if (e.key !== 'Escape') return;
                    d.querySelectorAll('.co-page ~ .modal-overlay.open').forEach(function (m) { closeModal(m.id); });
                });
            }

            // ตารางกรอก (แต่ละส่วนมีฟอร์ม + แถบบันทึกของตัวเอง)
            d.querySelectorAll('.co-page [data-de-scope] > form:not([hidden])').forEach(function (f) { deInit(f); });

            /** ตั้งหน้าต่างเป็นโหมด เพิ่ม / แก้ไข: action, หัวข้อ, ไอคอน, ปุ่ม */
            function setMode(id, edit, cfg) {
                var m = $(id), f = m.querySelector('[data-co-form]');
                f.reset();
                f.querySelector('[data-co-action]').value = edit ? cfg.editAction : cfg.addAction;
                m.querySelector('[data-co-title]').textContent = edit ? cfg.editTitle : cfg.addTitle;
                m.querySelector('[data-co-icon]').innerHTML = edit ? ICON_EDIT : ICON_ADD;
                m.querySelector('[data-co-submit]').textContent = edit ? 'บันทึกการแก้ไข' : cfg.addTitle;
                m.querySelector('[data-co-msg]').textContent = '';
                return f;
            }
            /** dropdown หมวดย่อย: ว่าง (เพิ่ม) หรือค่าเดิม (แก้ไข) */
            function setGroup(ddId, value, label) {
                if (value) { ddSetValue(ddId, value, label); return; }
                var wrap = $(ddId), input = $(ddId + '_input'), lab = $(ddId + '_label');
                if (input) input.value = '';
                if (lab) { lab.textContent = wrap.dataset.emptyLabel || 'เลือกหมวดย่อย'; lab.style.color = '#9CA3AF'; }
                wrap.querySelectorAll('.dd-option').forEach(function (o) { o.classList.remove('active'); });
            }
            function focusFirst(id) { setTimeout(function () { var el = $(id).querySelector('input.form-control-dark:not([type=hidden])'); if (el) el.focus(); }, 60); }

            window.coSurveyOpen = function (b) {
                setMode('coSurveyModal', !!b, { addAction: 'add_questionnaire', editAction: 'edit_questionnaire', addTitle: 'เพิ่มแบบสอบถาม', editTitle: 'แก้ไขชื่อแบบสอบถาม' });
                var makerField = $('coSurveyMakerField'), makerH = $('coSurveyMakerH');
                $('coSurveyOld').value = b ? b.dataset.name : '';
                $('coSurveyName').value = b ? b.dataset.name : '';
                if (b) makerH.value = b.dataset.affid; else makerH.value = <?= (int)$survey_affil ?>;
                // admin เพิ่มใหม่: เลือกผู้จัดทำจาก dropdown (ปิด hidden ไม่ให้ส่งซ้ำ) · แก้ไข: ใช้เจ้าของเดิม
                if (makerField) { makerField.hidden = !!b; makerH.disabled = !b; $('coSurveyMaker_input').disabled = !!b; }
                openModal('coSurveyModal'); focusFirst('coSurveyModal');
            };
            window.coTopicOpen = function (b) {
                setMode('coTopicModal', !!b, { addAction: 'add_topic', editAction: 'edit_topic', addTitle: 'เพิ่มหัวข้อ', editTitle: 'แก้ไขหัวข้อ' });
                $('coTopicId').value = b ? b.dataset.qiid : '';
                $('coTopicLabel').value = b ? b.dataset.label : '';
                $('coTopicUnit').value = b ? b.dataset.unit : '';
                $('coTopicAd').value = b ? b.dataset.ad : '';
                setGroup('coTopicGroup', b && b.dataset.group, b && b.dataset.groupname);
                openModal('coTopicModal'); focusFirst('coTopicModal');
            };
            window.coEventOpen = function (b) {
                setMode('coEventModal', !!b, { addAction: 'add_event', editAction: 'edit_event', addTitle: 'เพิ่มกิจกรรม', editTitle: 'แก้ไขกิจกรรม' });
                $('coEventId').value = b ? b.dataset.eid : '';
                $('coEventName').value = b ? b.dataset.name : '';
                var toDMY = function (iso) { var m = (iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; };
                $('coEventStart').value = b ? toDMY(b.dataset.date) : ''; $('coEventStartH').value = b ? (b.dataset.date || '') : '';
                $('coEventEnd').value = b ? toDMY(b.dataset.end) : '';    $('coEventEndH').value = b ? (b.dataset.end || '') : '';
                var org = $('coEventOrgField'); if (org) org.hidden = !!b;   // ผู้จัดเลือกได้ตอนเพิ่มเท่านั้น
                openModal('coEventModal'); focusFirst('coEventModal');
            };
            window.coEvTopicOpen = function (b) {
                setMode('coEvTopicModal', !!b, { addAction: 'add_event_topic', editAction: 'edit_event_topic', addTitle: 'เพิ่มรายการปล่อย', editTitle: 'แก้ไขรายการปล่อย' });
                $('coEvTopicId').value = b ? b.dataset.aiid : '';
                $('coEvTopicLabel').value = b ? b.dataset.label : '';
                $('coEvTopicUnit').value = b ? b.dataset.unit : '';
                $('coEvTopicAd').value = b ? b.dataset.ad : '';
                setGroup('coEvTopicGroup', b && b.dataset.group, b && b.dataset.groupname);
                openModal('coEvTopicModal'); focusFirst('coEvTopicModal');
            };
            window.coRemovalOpen = function (b) {
                setMode('coRemovalModal', !!b, { addAction: 'add_removal_item', editAction: 'edit_event_removal', addTitle: 'เพิ่มรายการดูดกลับ', editTitle: 'แก้ไขรายการดูดกลับ' });
                $('coRemovalId').value = b ? b.dataset.rid : '';
                $('coRemovalName').value = b ? b.dataset.name : '';
                $('coRemovalUnit').value = b ? b.dataset.unit : '';
                $('coRemovalFactor').value = b ? b.dataset.factor : '';
                openModal('coRemovalModal'); focusFirst('coRemovalModal');
            };
            /** ลบแถว (ฟอร์มลบซ่อนอยู่นอกฟอร์มบันทึก) — ยืนยันก่อน */
            window.coDeleteRow = function (formId, message) {
                confirmDelete({ message: message }).then(function (ok) { if (ok && $(formId)) $(formId).submit(); });
            };

            // ── ช่องวันที่ dd/mm/yyyy → ส่ง yyyy-mm-dd ผ่าน hidden ──
            function toISO(v) {
                var m = v.match(/^(\d{2})\/(\d{2})\/(\d{4})$/); if (!m) return '';
                if (+m[2] < 1 || +m[2] > 12 || +m[1] < 1 || +m[1] > 31 || +m[3] < 1000) return '';
                return m[3] + '-' + m[2] + '-' + m[1];
            }
            function mask(v) {
                var x = v.replace(/\D/g, '').slice(0, 8), out = x.slice(0, 2);
                if (x.length >= 3) out += '/' + x.slice(2, 4);
                if (x.length >= 5) out += '/' + x.slice(4, 8);
                return out;
            }
            d.querySelectorAll('.co-date').forEach(function (inp) {
                inp.addEventListener('input', function () { inp.value = mask(inp.value); var hd = $(inp.dataset.hidden); if (hd) hd.value = toISO(inp.value); });
            });

            // ── ตรวจก่อนส่งหน้าต่าง: หมวดย่อยต้องเลือก, วันที่ต้องถูกต้อง, ผู้จัด "อื่นๆ" ต้องพิมพ์ชื่อ ──
            d.querySelectorAll('.co-page ~ .modal-overlay [data-co-form]').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    var msg = f.querySelector('[data-co-msg]'), err = '';
                    if (f.dataset.coDd && !$(f.dataset.coDd + '_input').value) err = 'กรุณาเลือกหมวดย่อย';
                    if (f.hasAttribute('data-co-dates')) {
                        var s = $('coEventStart'), en = $('coEventEnd'), sh = $('coEventStartH').value, eh = $('coEventEndH').value;
                        if (s.value && !sh) err = 'วันที่เริ่มไม่ถูกต้อง (dd/mm/yyyy)';
                        else if (en.value && !eh) err = 'วันสิ้นสุดไม่ถูกต้อง (dd/mm/yyyy)';
                        else if (eh && !sh) err = 'กรุณากรอกวันที่เริ่มก่อนวันสิ้นสุด';
                        else if (sh && eh && eh < sh) err = 'วันสิ้นสุดต้องไม่น้อยกว่าวันที่เริ่ม';
                        var orgH = $('addOrg_input'), cust = $('addOrgCustom'), orgField = $('coEventOrgField');
                        if (!err && orgH && orgField && !orgField.hidden && orgH.value === '__custom__') {
                            if (!cust.value.trim()) err = 'กรุณาพิมพ์ชื่อผู้จัด';
                            else orgH.value = 'custom:' + cust.value.trim();
                        }
                    }
                    if (err) {
                        e.preventDefault(); msg.textContent = err;
                        var box = f.closest('.modal-box'); box.classList.remove('oe-shake'); void box.offsetWidth; box.classList.add('oe-shake');
                    }
                });
            });
            // ผู้จัด "อื่นๆ" → โชว์ช่องพิมพ์ชื่อ
            var org = $('addOrg');
            if (org) org.addEventListener('dd:change', function (e) {
                var custom = e.detail.value === '__custom__', c = $('addOrgCustom');
                c.style.display = custom ? 'block' : 'none'; if (custom) c.focus();
            });

            // ── แท็บ: เลื่อนแถบไปแท็บใหม่ก่อน แล้วค่อยเปลี่ยนหน้า ──
            d.querySelectorAll('.co-tab').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey) return;
                    e.preventDefault();
                    if (a.classList.contains('is-on')) return;
                    var nav = a.closest('.co-tabs');
                    nav.dataset.tab = a.dataset.tab;
                    nav.querySelectorAll('.co-tab').forEach(function (t) { t.classList.toggle('is-on', t === a); });
                    setTimeout(function () { location.href = a.href; }, 220);
                });
            });

            // ── เปลี่ยนปี (คงแบบสอบถามที่เลือกไว้) ──
            var navSuffix = <?= $is_survey
                ? json_encode(($is_admin ? '&maker=' . (int)$survey_affil : '') . '&group=' . rawurlencode($group))
                : "''" ?>;
            var yearDd = $('coYear');
            if (yearDd) yearDd.addEventListener('dd:change', function (e) {
                if (String(e.detail.value) === '<?= (int)$selected_year ?>') return;   // อยู่ปีเดิม ไม่ต้องโหลด
                location.href = 'collect.php?tab=<?= $tab ?>&year=' + encodeURIComponent(e.detail.value) + navSuffix;
            });
        })();
        </script>

        <?php include __DIR__ . '/../components/evidence_modal.php'; ?>
        <?php include __DIR__ . '/../components/confirm_modal.php'; ?>
    </main>
</body>
</html>
