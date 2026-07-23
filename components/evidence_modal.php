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
?>
<style>
    /* ── ปุ่มเปิด popup ในตาราง (ไอคอน + badge) ── */
    .ev-open-btn{position:relative;border:none;border-radius:9px;padding:7px;cursor:pointer;color:#fff;background:#6366F1;box-shadow:0 4px 10px rgba(99,102,241,.2);transition:all .2s;}
    .ev-open-btn:hover{background:#4F46E5;transform:translateY(-2px);}
    .ev-badge{position:absolute;top:-6px;right:-6px;background:#EF4444;color:#fff;font-size:.65rem;font-weight:800;min-width:18px;height:18px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid #fff;line-height:1;}
    /* ── modal ── */
    #uevModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:300;align-items:center;justify-content:center;}
    #uevModal .uev-box{background:#fff;border-radius:24px;width:94%;max-width:720px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;position:relative;padding:2rem 2.5rem;}
    #uevModal .uev-close{position:absolute;top:12px;right:12px;background:#FF4747;color:#fff;border:none;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1.2rem;line-height:1;z-index:10;}
    #uevModal .uev-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.1rem;padding-right:30px;flex-wrap:wrap;gap:10px;}
    #uevModal .uev-title{font-size:1.2rem;font-weight:800;color:#1F2937;margin:0;overflow-wrap:anywhere;word-break:break-word;}
    #uevModal .uev-cnt{font-size:.7rem;font-weight:700;background:#EDE9FE;color:#7C3AED;border-radius:999px;padding:2px 10px;margin-left:6px;white-space:nowrap;}
    #uevModal .uev-hbtn{display:none;align-items:center;gap:6px;border:none;padding:8px 16px;border-radius:999px;font-size:.85rem;font-weight:700;cursor:pointer;font-family:inherit;}
    #uevModal .uev-edit{background:#6366F1;color:#fff;}
    #uevModal .uev-delall{background:#FEF2F2;color:#EF4444;border:1px solid #FCA5A5;}
    #uevModal .uev-tabs{display:flex;border-bottom:2px solid #F3F4F6;margin-bottom:1.1rem;}
    #uevModal .uev-tab{padding:8px 22px;border:none;background:none;font-weight:600;font-size:.9rem;color:#9CA3AF;border-bottom:3px solid transparent;margin-bottom:-2px;cursor:pointer;font-family:inherit;transition:all .2s;}
    #uevModal .uev-tab.on{font-weight:700;color:#7C3AED;border-bottom-color:#7C3AED;}
    #uevModal .uev-tabcnt{background:#F3F4F6;color:#6B7280;border-radius:999px;padding:1px 8px;font-size:.72rem;margin-left:4px;}
    #uevModal .uev-tab.on .uev-tabcnt{background:#EDE9FE;color:#7C3AED;}
    #uevModal .uev-body{overflow-y:auto;padding-bottom:6px;}
    #uevModal .uev-drop{border:2px dashed #C7CDD6;border-radius:16px;padding:20px;text-align:center;color:#6B7280;cursor:pointer;transition:all .2s;margin-bottom:14px;display:block;}
    #uevModal .uev-drop:hover{border-color:#8B5CF6;color:#6D28D9;background:#FAF9FF;}
    #uevModal .uev-drop .t{font-weight:700;color:#4B5563;}
    #uevModal .uev-drop .s{font-size:.8rem;color:#9CA3AF;margin-top:4px;}
    #uevModal .uev-staged{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
    #uevModal .uev-chip{background:#F5F3FF;color:#6D28D9;border-radius:8px;padding:4px 10px;font-size:.8rem;}
    #uevModal .uev-save{display:none;justify-content:flex-end;margin-bottom:12px;}
    #uevModal .uev-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;}
    #uevModal .uev-item{position:relative;border-radius:12px;overflow:hidden;aspect-ratio:1;background:#F3F4F6;}
    #uevModal .uev-item img{width:100%;height:100%;object-fit:cover;cursor:zoom-in;display:block;}
    #uevModal .uev-itemdel{position:absolute;top:6px;right:6px;background:#EF4444;color:#fff;border:none;width:26px;height:26px;border-radius:8px;cursor:pointer;display:none;align-items:center;justify-content:center;}
    #uevModal.editing .uev-itemdel{display:flex;}
    #uevModal .uev-row{display:flex;align-items:center;gap:10px;padding:8px;border:1px solid #EEF0F4;border-radius:12px;margin-bottom:8px;}
    #uevModal .uev-doc{width:48px;height:48px;border-radius:10px;background:#FEE2E2;color:#DC2626;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.7rem;flex-shrink:0;}
    #uevModal .uev-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#374151;text-decoration:none;font-size:.9rem;}
    #uevModal .uev-name:hover{color:#6D28D9;text-decoration:underline;}
    #uevModal .uev-dl{background:#3B82F6;color:#fff;border:none;width:34px;height:34px;border-radius:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;text-decoration:none;}
    #uevModal .uev-del{background:#FEE2E2;color:#DC2626;border:none;width:34px;height:34px;border-radius:9px;cursor:pointer;flex-shrink:0;font-weight:800;}
    #uevModal .uev-del:hover{background:#FCA5A5;color:#fff;}
    #uevModal .uev-empty{text-align:center;color:#9CA3AF;padding:26px 0;font-size:.92rem;}
    #uevModal .uev-addlink{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
    #uevLightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:320;align-items:center;justify-content:center;cursor:zoom-out;}
    #uevLightbox img{max-width:92%;max-height:92%;border-radius:10px;}
</style>

<div id="uevModal">
    <div class="uev-box">
        <button type="button" class="uev-close" onclick="closeEvidence()">&times;</button>
        <div class="uev-head">
            <h3 class="uev-title"><span id="uevTitle">จัดการหลักฐาน</span><span id="uevCount" class="uev-cnt" style="display:none;">0</span></h3>
            <div style="display:flex;gap:8px;align-items:center;">
                <button id="uevEdit" type="button" class="uev-hbtn uev-edit" onclick="uevToggleEdit()">✏️ แก้ไข</button>
                <button id="uevDelAll" type="button" class="uev-hbtn uev-delall" onclick="uevDeleteAll()">🗑 ลบทั้งหมด</button>
            </div>
        </div>
        <div class="uev-tabs">
            <button type="button" id="uevTabImg"  class="uev-tab on" onclick="uevTab('img')">📷 รูปภาพ <span class="uev-tabcnt" id="uevImgCount">0</span></button>
            <button type="button" id="uevTabDoc"  class="uev-tab"    onclick="uevTab('doc')">📄 เอกสาร <span class="uev-tabcnt" id="uevDocCount">0</span></button>
            <button type="button" id="uevTabLink" class="uev-tab"    onclick="uevTab('link')">🔗 ลิงก์ <span class="uev-tabcnt" id="uevLinkCount">0</span></button>
        </div>
        <div class="uev-body">
            <!-- รูปภาพ -->
            <div id="uevPanelImg">
                <label class="uev-drop">
                    <div class="t">⬆️ คลิกเพื่อเลือกรูปภาพ</div>
                    <div class="s">JPG, PNG, WebP (เลือกได้หลายไฟล์ — เลือกแล้วกด "บันทึกรูปภาพ")</div>
                    <input type="file" id="uevImgInput" multiple accept="image/*" style="display:none;" onchange="uevStage(this,'images')">
                </label>
                <div class="uev-staged" id="uevImgStaged"></div>
                <div class="uev-save" id="uevImgSave"><?php $btn_label='💾 บันทึกรูปภาพ';$btn_variant='primary';$btn_type='button';$btn_onclick="uevCommit('images')";include __DIR__.'/button.php'; ?></div>
                <div class="uev-grid" id="uevGrid"></div>
                <div class="uev-empty" id="uevImgEmpty" style="display:none;">ยังไม่มีรูปภาพ</div>
            </div>
            <!-- เอกสาร -->
            <div id="uevPanelDoc" style="display:none;">
                <label class="uev-drop">
                    <div class="t">⬆️ คลิกเพื่อแนบไฟล์เอกสาร</div>
                    <div class="s">PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX (เลือกได้หลายไฟล์)</div>
                    <input type="file" id="uevDocInput" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" style="display:none;" onchange="uevStage(this,'documents')">
                </label>
                <div class="uev-staged" id="uevDocStaged"></div>
                <div class="uev-save" id="uevDocSave"><?php $btn_label='💾 บันทึกเอกสาร';$btn_variant='primary';$btn_type='button';$btn_onclick="uevCommit('documents')";include __DIR__.'/button.php'; ?></div>
                <div id="uevDocList"></div>
                <div class="uev-empty" id="uevDocEmpty" style="display:none;">ยังไม่มีเอกสารแนบ</div>
            </div>
            <!-- ลิงก์ -->
            <div id="uevPanelLink" style="display:none;">
                <div class="uev-addlink">
                    <input type="url" id="uevLinkUrl" class="ti-input" style="flex:1;min-width:220px;" placeholder="วางลิงก์ เช่น https://forms.gle/...">
                    <input type="text" id="uevLinkLabel" class="ti-input" style="width:170px;" placeholder="คำอธิบาย (ถ้ามี)">
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
        document.getElementById('uevTitle').textContent = desc.title || 'จัดการหลักฐาน';
        document.getElementById('uevImgStaged').innerHTML=''; document.getElementById('uevDocStaged').innerHTML='';
        document.getElementById('uevImgSave').style.display='none'; document.getElementById('uevDocSave').style.display='none';
        document.getElementById('uevLinkUrl').value=''; document.getElementById('uevLinkLabel').value='';
        uevTab('img');
        document.getElementById('uevModal').style.display='flex';
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
        document.getElementById('uevEdit').textContent = editing ? '✓ เสร็จ' : '✏️ แก้ไข';
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
            var cnt=document.getElementById('uevCount'); cnt.style.display=total?'inline-block':'none'; cnt.textContent=total+' รายการ';
            var hasAny = total>0;
            document.getElementById('uevEdit').style.display   = imgs.length? 'inline-flex':'none';
            document.getElementById('uevDelAll').style.display = hasAny? 'inline-flex':'none';
            setRowBadge(total);
            // รูปภาพ
            document.getElementById('uevImgEmpty').style.display = imgs.length?'none':'block';
            grid.innerHTML = imgs.map(function(f){
                var url=BASE+f.file_path;
                return '<div class="uev-item"><img src="'+url+'" onclick="uevLightbox(\''+url+'\')">'
                    +'<button class="uev-itemdel" title="ลบ" onclick="uevDel('+f.id+')">✕</button></div>';
            }).join('');
            // เอกสาร
            document.getElementById('uevDocEmpty').style.display = docs.length?'none':'block';
            dl.innerHTML = docs.map(function(f){
                var url=BASE+f.file_path;
                return '<div class="uev-row"><div class="uev-doc">'+esc((f.original_name||'').split('.').pop().toUpperCase()||'DOC')+'</div>'
                    +'<a href="'+url+'" target="_blank" class="uev-name">'+esc(f.original_name||f.file_path)+'</a>'
                    +'<a href="'+url+'" download class="uev-dl" title="ดาวน์โหลด">⬇</a>'
                    +'<button class="uev-del" title="ลบ" onclick="uevDel('+f.id+')">✕</button></div>';
            }).join('');
            // ลิงก์
            document.getElementById('uevLinkEmpty').style.display = links.length?'none':'block';
            ll.innerHTML = links.map(function(l){
                return '<div class="uev-row"><div class="uev-doc" style="background:#EEF2FF;color:#4F46E5;font-size:1.2rem;">🔗</div>'
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
        box.innerHTML = staged[kind].map(function(f,i){
            return '<span class="uev-chip">'+esc(f.name)+' <b style="cursor:pointer;color:#DC2626;" onclick="uevUnstage(\''+kind+'\','+i+')">✕</b></span>';
        }).join('');
        save.style.display = staged[kind].length?'flex':'none';
    }
    window.uevUnstage = function(kind,i){ staged[kind].splice(i,1); renderStaged(kind); };
    window.uevCommit = function(kind){
        if(!staged[kind].length) return;
        var fd=new FormData(); appendEntity(fd);
        staged[kind].forEach(function(f){ fd.append(kind==='images'?'images[]':'documents[]', f); });
        fetch(API+'?action=upload',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
            if(!res.success){ alert(res.message||'อัปโหลดไม่สำเร็จ'); return; }
            staged[kind]=[]; renderStaged(kind); loadEv();
        });
    };
    window.uevAddLink = function(){
        var url=document.getElementById('uevLinkUrl').value.trim();
        if(!url){ alert('กรุณาใส่ลิงก์'); return; }
        var fd=new FormData(); appendEntity(fd);
        fd.append('url',url); fd.append('label',document.getElementById('uevLinkLabel').value.trim());
        fetch(API+'?action=add_link',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
            if(!res.success){ alert(res.message||'เพิ่มลิงก์ไม่สำเร็จ'); return; }
            document.getElementById('uevLinkUrl').value=''; document.getElementById('uevLinkLabel').value=''; loadEv();
        });
    };
    window.uevDel = function(id){
        if(!confirm('ลบหลักฐานนี้?')) return;
        var fd=new FormData(); fd.append('evidence_id',id);
        fetch(API+'?action=delete',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
            if(!res.success){ alert(res.message||'ลบไม่สำเร็จ'); return; } loadEv();
        });
    };
    window.uevDeleteAll = function(){
        if(!confirm('ลบหลักฐานทั้งหมด (ไฟล์+ลิงก์) ของรายการนี้?')) return;
        var fd=new FormData(); appendEntity(fd);
        fetch(API+'?action=delete_all',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
            if(!res.success){ alert(res.message||'ลบไม่สำเร็จ'); return; } editing=false;
            document.getElementById('uevModal').classList.remove('editing');
            document.getElementById('uevEdit').textContent='✏️ แก้ไข'; loadEv();
        });
    };
    document.getElementById('uevModal').addEventListener('click',function(e){ if(e.target===this) closeEvidence(); });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ var lb=document.getElementById('uevLightbox'); if(lb.style.display==='flex'){lb.style.display='none';return;} closeEvidence(); } });
})();
</script>
