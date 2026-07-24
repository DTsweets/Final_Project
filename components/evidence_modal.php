<?php
/**
 * COMPONENT: Evidence Modal (components/evidence_modal.php)
 * --------------------------------------------------------
 * popup จัดการหลักฐานกลาง — รูปภาพ / เอกสาร / ลิงก์ (หลายอัน)
 * ใช้ร่วมได้ทุกหน้า ผ่าน API กลาง (officer/api/manage_evidence.php)
 *
 * วิธีใช้:
 *   1) include ไฟล์นี้ 1 ครั้งต่อหน้า (วางท้าย main)
 *   2) ปุ่มในตารางเรียก openEvidence({type:'questionnaire'|'event', id:<id>, title:'...'})
 *      หรือ (scope entry) openEvidence({adminItemId:<id>, yearId:<id>, title:'...'})
 *
 * ตัวแปร optional ก่อน include:
 *   $ev_api  = URL ของ API (default: <root>officer/api/manage_evidence.php)
 *   $ev_base = base path ของไฟล์หลักฐาน (default: <root>assets/images/evidence/)
 */
$ev_api  = $ev_api  ?? (($root ?? '../') . 'officer/api/manage_evidence.php');
$ev_base = $ev_base ?? (($root ?? '../') . 'assets/images/evidence/');
$ev_show_link = $ev_show_link ?? true;   // false = ซ่อนแท็บ "ลิงก์" (เหลือ รูปภาพ/เอกสาร)
?>
<style>
    /* ── ปุ่มเปิด popup ในตาราง (ไอคอน + badge) ── */
    .ev-open-btn{position:relative;border:none;border-radius:9px;padding:7px;cursor:pointer;color:#fff;background:#6366F1;box-shadow:0 4px 10px rgba(99,102,241,.2);transition:all .2s;}
    .ev-open-btn:hover{background:#4F46E5;transform:translateY(-2px);}
    .ev-badge{position:absolute;top:-6px;right:-6px;background:#EF4444;color:#fff;font-size:.65rem;font-weight:800;min-width:18px;height:18px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid #fff;line-height:1;}
    /* ── modal ── */
    #uevModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:300;align-items:center;justify-content:center;}
    #uevModal .uev-box{background:#fff;border-radius:24px;width:94%;max-width:720px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;position:relative;padding:2rem 2.5rem;animation:uevPop .5s cubic-bezier(0.34,1.56,0.64,1);}
    @keyframes uevPop{from{opacity:0;transform:translateY(50px) scale(.9);}to{opacity:1;transform:translateY(0) scale(1);}}
    #uevModal .uev-close{position:absolute;top:12px;right:12px;background:#FF4747;color:#fff;border:none;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1.2rem;line-height:1;z-index:10;transition:all .3s ease;}
    #uevModal .uev-close:hover{transform:scale(1.2);filter:brightness(1.1);}
    #uevModal .uev-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.1rem;padding-right:30px;flex-wrap:wrap;gap:10px;}
    #uevModal .uev-title{font-size:1.2rem;font-weight:800;color:#1F2937;margin:0;overflow-wrap:anywhere;word-break:break-word;}
    #uevModal .uev-cnt{font-size:.7rem;font-weight:700;background:#EDE9FE;color:#7C3AED;border-radius:999px;padding:3px 12px;margin-left:8px;white-space:nowrap;vertical-align:middle;}
    #uevModal .uev-cnt.empty{background:#F3F4F6;color:#9CA3AF;}
    #uevModal .uev-hbtn{display:none;align-items:center;gap:6px;border:none;padding:8px 16px;border-radius:999px;font-size:.85rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;}
    #uevModal .uev-hbtn:hover{transform:translateY(-2px);}
    #uevModal .uev-hbtn svg{width:16px;height:16px;flex-shrink:0;}
    #uevModal .uev-edit{background:#6366F1;color:#fff;}
    #uevModal .uev-edit.on{background:#10B981;}
    #uevModal .uev-delall{background:#FEF2F2;color:#EF4444;border:1px solid #FCA5A5;}
    #uevModal .uev-tabs{display:flex;border-bottom:2px solid #F3F4F6;margin-bottom:1.1rem;}
    #uevModal .uev-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 22px;border:none;background:none;font-weight:600;font-size:.9rem;color:#9CA3AF;border-bottom:3px solid transparent;margin-bottom:-2px;cursor:pointer;font-family:inherit;transition:all .2s;}
    #uevModal .uev-tab svg{width:15px;height:15px;flex-shrink:0;}
    #uevModal .uev-tab.on{font-weight:700;color:#3B82F6;border-bottom-color:#3B82F6;}
    #uevModal .uev-tabcnt{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;background:#E5E7EB;color:#6B7280;border-radius:999px;padding:0 6px;font-size:.72rem;font-weight:800;margin-left:2px;vertical-align:middle;}
    #uevModal .uev-tab.on .uev-tabcnt{background:#3B82F6;color:#fff;}
    #uevModal .uev-body{overflow-y:auto;padding-bottom:6px;}
    #uevModal .uev-drop{border:2px dashed #C7CDD6;border-radius:18px;padding:40px 20px;text-align:center;color:#6B7280;cursor:pointer;transition:all .2s;margin-bottom:14px;display:block;}
    #uevModal .uev-drop:hover{border-color:#8B5CF6;color:#6D28D9;background:#FAF9FF;}
    #uevModal .uev-drop:hover .uev-drop-ic{background:#EDE9FE;transform:translateY(-2px);}
    #uevModal .uev-drop-ic{width:56px;height:56px;margin:0 auto 14px;border-radius:50%;background:#F5F3FF;color:#7C3AED;display:flex;align-items:center;justify-content:center;transition:all .2s;}
    #uevModal .uev-drop-ic svg{width:26px;height:26px;}
    #uevModal .uev-drop .t{font-weight:700;color:#4B5563;font-size:1rem;}
    #uevModal .uev-drop .s{font-size:.82rem;color:#9CA3AF;margin-top:6px;}
    #uevModal .uev-staged{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;}
    /* staged image thumbnail */
    #uevModal .uev-sthumb{position:relative;width:70px;height:70px;border-radius:10px;overflow:hidden;border:2px solid #A78BFA;}
    #uevModal .uev-sthumb img{width:100%;height:100%;object-fit:cover;display:block;}
    #uevModal .uev-sthumb .x{position:absolute;top:-6px;right:-6px;background:#EF4444;color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:13px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,.2);}
    /* staged document chip (red-outline) */
    #uevModal .uev-chip{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #FCA5A5;border-radius:8px;padding:6px 10px;font-size:.8rem;font-weight:600;color:#374151;max-width:240px;}
    #uevModal .uev-chip .ext{color:#EF4444;font-weight:800;text-transform:uppercase;font-size:.7rem;}
    #uevModal .uev-chip .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    #uevModal .uev-chip .x{background:none;border:none;color:#EF4444;cursor:pointer;font-size:1.1rem;line-height:1;padding:0 2px;}
    #uevModal .uev-save{display:none;justify-content:flex-end;margin-bottom:12px;}
    #uevModal .uev-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;}
    #uevModal .uev-item{position:relative;border-radius:12px;overflow:hidden;aspect-ratio:1;background:#F3F4F6;}
    #uevModal .uev-item img{width:100%;height:100%;object-fit:cover;cursor:zoom-in;display:block;}
    /* image card overlay actions (แสดงเฉพาะโหมดแก้ไข) — เหมือน officer */
    #uevModal .uev-ovl{position:absolute;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;gap:8px;opacity:0;pointer-events:none;transition:opacity .2s;}
    #uevModal.editing .uev-item .uev-ovl{opacity:1;pointer-events:auto;}
    #uevModal .uev-ovl button{border:none;width:32px;height:32px;border-radius:8px;cursor:pointer;color:#fff;display:flex;align-items:center;justify-content:center;}
    #uevModal .uev-ovl .zoom{background:#6366F1;}
    #uevModal .uev-ovl .del{background:#EF4444;}
    #uevModal .uev-ovl svg{width:15px;height:15px;}
    #uevModal .uev-row{display:flex;align-items:center;gap:10px;padding:8px;border:1px solid #EEF0F4;border-radius:12px;margin-bottom:8px;}
    #uevModal .uev-doc{width:48px;height:48px;border-radius:10px;background:#FEE2E2;color:#DC2626;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.7rem;flex-shrink:0;}
    #uevModal .uev-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#374151;text-decoration:none;font-size:.9rem;}
    #uevModal .uev-name:hover{color:#6D28D9;text-decoration:underline;}
    #uevModal .uev-dl{background:#3B82F6;color:#fff;border:none;width:34px;height:34px;border-radius:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;text-decoration:none;}
    #uevModal .uev-del{background:#FEE2E2;color:#DC2626;border:none;width:34px;height:34px;border-radius:9px;cursor:pointer;flex-shrink:0;font-weight:800;}
    #uevModal .uev-del:hover{background:#FCA5A5;color:#fff;}
    #uevModal .uev-empty{text-align:center;color:#9CA3AF;padding:26px 0;font-size:.92rem;}
    #uevModal .uev-empty .ic{width:52px;height:52px;margin:0 auto 10px;color:#D1D5DB;}
    #uevModal .uev-empty .ic svg{width:100%;height:100%;}
    /* document row (ext-tinted) */
    #uevModal .uev-drow{display:flex;align-items:center;gap:12px;background:#F9FAFB;border:1px solid rgba(0,0,0,.04);border-radius:12px;padding:12px 16px;margin-bottom:8px;}
    #uevModal .uev-dbadge{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.65rem;font-weight:900;text-transform:uppercase;}
    #uevModal .uev-dmeta{flex:1;min-width:0;}
    #uevModal .uev-dname{font-weight:600;color:#1F2937;font-size:.875rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    #uevModal .uev-ddate{font-size:.75rem;color:#9CA3AF;margin-top:2px;}
    #uevModal .uev-dact{display:flex;gap:8px;flex-shrink:0;}
    #uevModal .uev-dact .dl{width:34px;height:34px;border-radius:8px;background:#3B82F6;color:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none;}
    #uevModal .uev-dact .rm{width:34px;height:34px;border-radius:8px;background:#FEF2F2;color:#EF4444;border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;}
    @keyframes uevspin{to{transform:rotate(360deg);}}
    #uevModal .uev-loading{display:none;text-align:center;margin:18px 0;}
    #uevModal .uev-loading .sp{display:inline-block;width:30px;height:30px;border:3px solid #EDE9FE;border-top:3px solid #7C3AED;border-radius:50%;animation:uevspin .8s linear infinite;}
    #uevModal .uev-loading .tx{color:#9CA3AF;font-size:.85rem;margin-top:8px;}
    #uevModal .uev-addlink{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;padding:3px;}
    /* คุมช่องลิงก์ตอน focus ไม่ให้ขอบเรืองแสงล้นเกิน (ชนะ .co .ti-input:focus) */
    #uevModal .uev-addlink .ti-input:focus{border-color:#62368B;box-shadow:0 0 0 2px rgba(98,54,139,.22);outline:none;}
    /* ปุ่มเพิ่มลิงก์ + บันทึกรูปภาพ/เอกสาร: ตัดเงาออก */
    #uevModal .uev-addlink .btn-c,
    #uevModal .uev-addlink .btn-c:hover,
    #uevModal .uev-save .btn-c,
    #uevModal .uev-save .btn-c:hover{box-shadow:none;}
    #uevLightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:320;align-items:center;justify-content:center;cursor:zoom-out;}
    #uevLightbox img{max-width:92%;max-height:92%;border-radius:10px;}
</style>

<div id="uevModal">
    <div class="uev-box">
        <button type="button" class="uev-close" onclick="closeEvidence()">&times;</button>
        <div class="uev-head">
            <h3 class="uev-title"><span id="uevTitle">จัดการหลักฐาน</span><span id="uevCount" class="uev-cnt" style="display:none;">0</span></h3>
            <div style="display:flex;gap:8px;align-items:center;">
                <button id="uevEdit" type="button" class="uev-hbtn uev-edit" onclick="uevToggleEdit()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> แก้ไข</button>
                <button id="uevDelAll" type="button" class="uev-hbtn uev-delall" onclick="uevDeleteAll()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> ลบทั้งหมด</button>
            </div>
        </div>
        <div class="uev-tabs">
            <button type="button" id="uevTabImg"  class="uev-tab on" onclick="uevTab('img')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="3"/></svg>รูปภาพ <span class="uev-tabcnt" id="uevImgCount">0</span></button>
            <button type="button" id="uevTabDoc"  class="uev-tab"    onclick="uevTab('doc')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>เอกสาร <span class="uev-tabcnt" id="uevDocCount">0</span></button>
            <button type="button" id="uevTabLink" class="uev-tab"    onclick="uevTab('link')" <?= $ev_show_link ? '' : 'style="display:none;"' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>ลิงก์ <span class="uev-tabcnt" id="uevLinkCount">0</span></button>
        </div>
        <div class="uev-body">
            <div class="uev-loading" id="uevLoading"><div class="sp"></div><div class="tx">กำลังบันทึก...</div></div>
            <!-- รูปภาพ -->
            <div id="uevPanelImg">
                <label class="uev-drop">
                    <div class="uev-drop-ic">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    </div>
                    <div class="t">คลิกเพื่อเลือกรูปภาพหลักฐาน</div>
                    <div class="s">JPG, PNG, WebP (เลือกได้หลายไฟล์ — เลือกแล้วกด "บันทึกรูปภาพ")</div>
                    <input type="file" id="uevImgInput" multiple accept="image/*" style="display:none;" onchange="uevStage(this,'images')">
                </label>
                <div class="uev-staged" id="uevImgStaged"></div>
                <div class="uev-save" id="uevImgSave"><?php $btn_label='บันทึกรูปภาพ';$btn_icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>';$btn_variant='primary';$btn_type='button';$btn_onclick="uevCommit('images')";include __DIR__.'/button.php'; ?></div>
                <div class="uev-grid" id="uevGrid"></div>
                <div class="uev-empty" id="uevImgEmpty" style="display:none;"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>ยังไม่มีรูปภาพ</div>
            </div>
            <!-- เอกสาร -->
            <div id="uevPanelDoc" style="display:none;">
                <label class="uev-drop">
                    <div class="uev-drop-ic">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 12 15 15"/></svg>
                    </div>
                    <div class="t">คลิกเพื่อแนบไฟล์เอกสารหลักฐาน</div>
                    <div class="s">PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX (เลือกได้หลายไฟล์)</div>
                    <input type="file" id="uevDocInput" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" style="display:none;" onchange="uevStage(this,'documents')">
                </label>
                <div class="uev-staged" id="uevDocStaged"></div>
                <div class="uev-save" id="uevDocSave"><?php $btn_label='บันทึกเอกสาร';$btn_icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>';$btn_variant='primary';$btn_type='button';$btn_onclick="uevCommit('documents')";include __DIR__.'/button.php'; ?></div>
                <div id="uevDocList"></div>
                <div class="uev-empty" id="uevDocEmpty" style="display:none;"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>ยังไม่มีเอกสารแนบ</div>
            </div>
            <!-- ลิงก์ -->
            <div id="uevPanelLink" style="display:none;">
                <div class="uev-addlink">
                    <input type="url" id="uevLinkUrl" class="ti-input" style="flex:1;min-width:200px;" placeholder="วางลิงก์ เช่น https://forms.gle/...">
                    <?php $btn_label='เพิ่มลิงก์';$btn_variant='primary';$btn_type='button';$btn_onclick='uevAddLink()';include __DIR__.'/button.php'; ?>
                </div>
                <div id="uevLinkList"></div>
                <div class="uev-empty" id="uevLinkEmpty" style="display:none;">ยังไม่มีลิงก์</div>
            </div>
        </div>
    </div>
</div>
<div id="uevLightbox" onclick="this.style.display='none'"><img id="uevLightboxImg" src="" alt=""></div>

<script>
(function(){
    var API  = <?= json_encode($ev_api) ?>;
    var BASE = <?= json_encode($ev_base) ?>;
    var cur = null;              // descriptor ปัจจุบัน
    var editing = false;
    var staged = {images:[], documents:[]};

    var EXT_COLOR={pdf:'#EF4444',doc:'#3B82F6',docx:'#3B82F6',xls:'#10B981',xlsx:'#10B981',ppt:'#F97316',pptx:'#F97316'};
    var EXT_BG={pdf:'#FEF2F2',doc:'#EFF6FF',docx:'#EFF6FF',xls:'#ECFDF5',xlsx:'#ECFDF5',ppt:'#FFF7ED',pptx:'#FFF7ED'};
    var SVG_PENCIL='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    var SVG_CHECK='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
    function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
    function isImg(ft){return ft && ft.indexOf('image/')===0;}
    // คืน query string + FormData keys ของ entity ปัจจุบัน
    function q(){
        if(cur.type) return 'entity_type='+encodeURIComponent(cur.type)+'&entity_id='+cur.id;
        return 'admin_item_id='+cur.adminItemId+'&year_id='+cur.yearId;
    }
    function appendEntity(fd){
        if(cur.type){ fd.append('entity_type',cur.type); fd.append('entity_id',cur.id); }
        else { fd.append('admin_item_id',cur.adminItemId); fd.append('year_id',cur.yearId); }
    }
    function badgeKey(){ return cur.type ? (cur.type+':'+cur.id) : ('user_item_legacy:'+cur.adminItemId+':'+cur.yearId); }

    window.openEvidence = function(desc){
        cur = desc; editing=false; staged={images:[],documents:[]};
        document.getElementById('uevModal').classList.remove('editing');
        var eb0=document.getElementById('uevEdit'); eb0.classList.remove('on'); eb0.innerHTML=SVG_PENCIL+' แก้ไข';
        document.getElementById('uevTitle').textContent = desc.title || 'จัดการหลักฐาน';
        document.getElementById('uevImgStaged').innerHTML=''; document.getElementById('uevDocStaged').innerHTML='';
        document.getElementById('uevImgSave').style.display='none'; document.getElementById('uevDocSave').style.display='none';
        document.getElementById('uevLinkUrl').value='';
        uevTab('img');
        document.getElementById('uevModal').style.display='flex';
        // force restart pop animation ทุกครั้งที่เปิด (parent toggle display ไม่ re-trigger เอง)
        var box=document.querySelector('#uevModal .uev-box');
        if(box){ box.style.animation='none'; void box.offsetWidth; box.style.animation=''; }
        document.body.style.overflow='hidden';
        loadEv();
    };
    window.closeEvidence = function(){
        document.getElementById('uevModal').style.display='none';
        document.body.style.overflow='';
    };
    window.uevTab = function(t){
        document.getElementById('uevPanelImg').style.display  = t==='img'?'block':'none';
        document.getElementById('uevPanelDoc').style.display  = t==='doc'?'block':'none';
        document.getElementById('uevPanelLink').style.display = t==='link'?'block':'none';
        document.getElementById('uevTabImg').classList.toggle('on',t==='img');
        document.getElementById('uevTabDoc').classList.toggle('on',t==='doc');
        document.getElementById('uevTabLink').classList.toggle('on',t==='link');
    };
    window.uevToggleEdit = function(){
        editing=!editing;
        document.getElementById('uevModal').classList.toggle('editing',editing);
        var eb=document.getElementById('uevEdit');
        eb.classList.toggle('on',editing);
        eb.innerHTML = (editing ? SVG_CHECK+' เสร็จสิ้น' : SVG_PENCIL+' แก้ไข');
    };
    function setRowBadge(n){
        var btn=document.querySelector('.ev-open-btn[data-ev="'+badgeKey()+'"]');
        if(!btn) return;
        var b=btn.querySelector('.ev-badge');
        if(n>0){ if(!b){b=document.createElement('span');b.className='ev-badge';btn.appendChild(b);} b.textContent=n; }
        else if(b){ b.remove(); }
    }
    window.uevLightbox = function(src){ document.getElementById('uevLightboxImg').src=src; document.getElementById('uevLightbox').style.display='flex'; };

    function loadEv(){
        var grid=document.getElementById('uevGrid'), dl=document.getElementById('uevDocList'), ll=document.getElementById('uevLinkList');
        grid.innerHTML=''; dl.innerHTML=''; ll.innerHTML='';
        fetch(API+'?action=list&'+q()).then(function(r){return r.json();}).then(function(res){
            if(!res.success){ grid.innerHTML='<div class="uev-empty" style="color:#EF4444;">'+esc(res.message||'error')+'</div>'; return; }
            var imgs=res.data.filter(function(x){return x.kind==='file'&&isImg(x.file_type);});
            var docs=res.data.filter(function(x){return x.kind==='file'&&!isImg(x.file_type);});
            var links=res.data.filter(function(x){return x.kind==='link';});
            document.getElementById('uevImgCount').textContent=imgs.length;
            document.getElementById('uevDocCount').textContent=docs.length;
            document.getElementById('uevLinkCount').textContent=links.length;
            var total=imgs.length+docs.length+links.length;
            var fileCnt=imgs.length+docs.length;
            var cnt=document.getElementById('uevCount');
            cnt.style.display='inline-block';
            cnt.classList.toggle('empty', fileCnt===0);
            cnt.textContent = fileCnt>0 ? (fileCnt+' ไฟล์') : 'ไม่มีไฟล์';
            var hasAny = total>0;
            document.getElementById('uevEdit').style.display   = imgs.length? 'inline-flex':'none';
            document.getElementById('uevDelAll').style.display = hasAny? 'inline-flex':'none';
            setRowBadge(total);
            // รูปภาพ — กรอบอัปโหลดโชว์ตลอด (เพิ่มรูปได้คลิกเดียว); "แก้ไข" ใช้สำหรับลบรูปผ่าน overlay
            document.getElementById('uevImgEmpty').style.display='none';
            grid.innerHTML = imgs.map(function(f){
                var url=BASE+f.file_path;
                return '<div class="uev-item"><img src="'+url+'" onclick="uevLightbox(\''+url+'\')">'
                    +'<div class="uev-ovl">'
                    +'<button type="button" class="zoom" title="ดูขนาดเต็ม" onclick="event.stopPropagation();uevLightbox(\''+url+'\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg></button>'
                    +'<button type="button" class="del" title="ลบรูปนี้" onclick="event.stopPropagation();uevDel('+f.id+')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>'
                    +'</div></div>';
            }).join('');
            // เอกสาร
            document.getElementById('uevDocEmpty').style.display = docs.length?'none':'block';
            dl.innerHTML = docs.map(function(f){
                var url=BASE+f.file_path;
                var name=f.original_name||f.file_path.split('/').pop();
                var ext=(name.split('.').pop()||'').toLowerCase();
                var color=EXT_COLOR[ext]||'#6B7280', bg=EXT_BG[ext]||'#F3F4F6';
                var dt=f.created_at?new Date(f.created_at.replace(' ','T')).toLocaleDateString('th-TH'):'';
                return '<div class="uev-drow" style="background:'+bg+';">'
                    +'<div class="uev-dbadge" style="background:'+color+'20;color:'+color+';">'+esc(ext||'DOC')+'</div>'
                    +'<div class="uev-dmeta"><div class="uev-dname" title="'+esc(name)+'">'+esc(name)+'</div>'+(dt?'<div class="uev-ddate">'+esc(dt)+'</div>':'')+'</div>'
                    +'<div class="uev-dact">'
                    +'<a class="dl" href="'+url+'" download="'+esc(name)+'" title="ดาวน์โหลด"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></a>'
                    +'<button class="rm" title="ลบ" onclick="uevDel('+f.id+')"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>'
                    +'</div></div>';
            }).join('');
            // ลิงก์
            document.getElementById('uevLinkEmpty').style.display = links.length?'none':'block';
            ll.innerHTML = links.map(function(l){
                return '<div class="uev-row"><div class="uev-doc" style="background:#EEF2FF;color:#4F46E5;">'
                    +'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>'
                    +'<a href="'+esc(l.url)+'" target="_blank" rel="noopener" class="uev-name">'+esc(l.label||l.url)+'</a>'
                    +'<button class="uev-del" title="ลบ" onclick="uevDel('+l.id+')">✕</button></div>';
            }).join('');
        }).catch(function(){ grid.innerHTML='<div class="uev-empty" style="color:#EF4444;">เชื่อมต่อไม่ได้</div>'; });
    }

    window.uevStage = function(inp,kind){
        for(var i=0;i<inp.files.length;i++) staged[kind].push(inp.files[i]);
        inp.value='';
        renderStaged(kind);
    };
    function renderStaged(kind){
        var box=document.getElementById(kind==='images'?'uevImgStaged':'uevDocStaged');
        var save=document.getElementById(kind==='images'?'uevImgSave':'uevDocSave');
        box.innerHTML='';
        staged[kind].forEach(function(f,i){
            if(kind==='images'){
                // thumbnail preview (เหมือน officer)
                var wrap=document.createElement('div'); wrap.className='uev-sthumb';
                wrap.innerHTML='<button type="button" class="x" title="เอาออก" onclick="uevUnstage(\'images\','+i+')">&times;</button>';
                var img=document.createElement('img');
                var rd=new FileReader(); rd.onload=function(e){img.src=e.target.result;}; rd.readAsDataURL(f);
                wrap.insertBefore(img, wrap.firstChild);
                box.appendChild(wrap);
            } else {
                var ext=(f.name.split('.').pop()||'').toLowerCase();
                var chip=document.createElement('div'); chip.className='uev-chip';
                chip.innerHTML='<span class="ext" style="color:'+(EXT_COLOR[ext]||'#EF4444')+';">'+esc(ext)+'</span>'
                    +'<span class="nm" title="'+esc(f.name)+'">'+esc(f.name)+'</span>'
                    +'<button type="button" class="x" title="เอาออก" onclick="uevUnstage(\'documents\','+i+')">&times;</button>';
                box.appendChild(chip);
            }
        });
        save.style.display = staged[kind].length?'flex':'none';
    }
    window.uevUnstage = function(kind,i){ staged[kind].splice(i,1); renderStaged(kind); };
    window.uevCommit = function(kind){
        if(!staged[kind].length) return;
        var fd=new FormData(); appendEntity(fd);
        staged[kind].forEach(function(f){ fd.append(kind==='images'?'images[]':'documents[]', f); });
        var loading=document.getElementById('uevLoading');
        var saveBar=document.getElementById(kind==='images'?'uevImgSave':'uevDocSave');
        if(loading) loading.style.display='block';
        if(saveBar) saveBar.style.display='none';
        fetch(API+'?action=upload',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
            if(loading) loading.style.display='none';
            if(!res.success){ alert(res.message||'อัปโหลดไม่สำเร็จ'); return; }
            staged[kind]=[]; renderStaged(kind); loadEv();
        }).catch(function(){ if(loading) loading.style.display='none'; alert('เกิดข้อผิดพลาด'); });
    };
    window.uevAddLink = function(){
        var url=document.getElementById('uevLinkUrl').value.trim();
        if(!url){ alert('กรุณาใส่ลิงก์'); return; }
        var fd=new FormData(); appendEntity(fd);
        fd.append('url',url); fd.append('label','');
        fetch(API+'?action=add_link',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
            if(!res.success){ alert(res.message||'เพิ่มลิงก์ไม่สำเร็จ'); return; }
            document.getElementById('uevLinkUrl').value=''; loadEv();
        });
    };
    // ใช้ confirmDelete (กล่องสวย) ถ้ามีในหน้า ไม่งั้น fallback เป็น confirm() ของเบราว์เซอร์
    function uevAskDel(msg){ return window.confirmDelete ? window.confirmDelete({message:msg}) : Promise.resolve(window.confirm(msg)); }
    window.uevDel = function(id){
        uevAskDel('ต้องการลบหลักฐานนี้?').then(function(ok){
            if(!ok) return;
            var fd=new FormData(); fd.append('evidence_id',id);
            fetch(API+'?action=delete',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
                if(!res.success){ alert(res.message||'ลบไม่สำเร็จ'); return; } loadEv();
            });
        });
    };
    window.uevDeleteAll = function(){
        uevAskDel('ลบหลักฐานทั้งหมด (ไฟล์+ลิงก์) ของรายการนี้?').then(function(ok){
            if(!ok) return;
            var fd=new FormData(); appendEntity(fd);
            fetch(API+'?action=delete_all',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
                if(!res.success){ alert(res.message||'ลบไม่สำเร็จ'); return; } editing=false;
                document.getElementById('uevModal').classList.remove('editing');
                var eb=document.getElementById('uevEdit'); eb.classList.remove('on'); eb.innerHTML=SVG_PENCIL+' แก้ไข'; loadEv();
            });
        });
    };
    document.getElementById('uevModal').addEventListener('click',function(e){ if(e.target===this) closeEvidence(); });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ var lb=document.getElementById('uevLightbox'); if(lb.style.display==='flex'){lb.style.display='none';return;} closeEvidence(); } });
})();
</script>
