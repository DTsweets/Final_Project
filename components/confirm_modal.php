<?php
/**
 * COMPONENT: Confirm Modal (components/confirm_modal.php)
 * ------------------------------------------------------
 * กล่องยืนยันการลบแบบเด้งขึ้น (แทน confirm() ของเบราว์เซอร์)
 *
 * วิธีใช้ (JS):
 *   confirmDelete({title, message, confirmText}).then(function(ok){ if(ok){ ... } });
 *   หรือกับฟอร์ม: <form onsubmit="return cfmForm(event, this, 'ข้อความ')">
 *
 * include ไฟล์นี้ 1 ครั้งต่อหน้า (วางในส่วนที่ถูกโหลด/สลับ เช่นใน .main-content ก็ได้ — JS กัน bind ซ้ำ)
 */
?>
<style>
    #cfmModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:400;align-items:center;justify-content:center;}
    #cfmModal .cfm-box{background:#fff;border-radius:20px;padding:2rem 2rem 1.75rem;max-width:400px;width:92%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.18);animation:cfmPop .5s cubic-bezier(0.34,1.56,0.64,1);}
    @keyframes cfmPop{from{opacity:0;transform:translateY(50px) scale(.9);}to{opacity:1;transform:translateY(0) scale(1);}}
    #cfmModal .cfm-ic{width:72px;height:72px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;}
    #cfmModal .cfm-ic svg{width:36px;height:36px;color:#EF4444;}
    #cfmModal .cfm-title{font-size:1.35rem;font-weight:800;color:#111827;margin:0 0 .5rem;}
    #cfmModal .cfm-msg{color:#6B7280;font-size:.95rem;margin:0 0 1.75rem;line-height:1.55;}
    #cfmModal .cfm-actions{display:flex;gap:10px;}
    #cfmModal .cfm-btn{flex:1;padding:12px;border-radius:12px;font-weight:700;font-family:inherit;font-size:.95rem;cursor:pointer;border:none;transition:transform .2s ease,background .2s ease,border-color .2s ease;}
    #cfmModal .cfm-cancel{background:#fff;border:1px solid #E5E7EB;color:#4B5563;}
    #cfmModal .cfm-cancel:hover{transform:translateY(-2px);background:#F9FAFB;border-color:#D1D5DB;}
    #cfmModal .cfm-confirm{background:#EF4444;color:#fff;}
    #cfmModal .cfm-confirm:hover{transform:translateY(-2px);background:#DC2626;}
</style>
<div id="cfmModal">
    <div class="cfm-box">
        <div class="cfm-ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
        </div>
        <h3 class="cfm-title" id="cfmTitle">ยืนยันการลบ?</h3>
        <p class="cfm-msg" id="cfmMsg">การลบนี้ไม่สามารถกู้คืนได้</p>
        <div class="cfm-actions">
            <button type="button" class="cfm-btn cfm-cancel" id="cfmCancel">ยกเลิก</button>
            <button type="button" class="cfm-btn cfm-confirm" id="cfmConfirm">ยืนยันการลบ</button>
        </div>
    </div>
</div>
<script>
(function(){
    if (window.confirmDelete) return;   // กันนิยาม/bind ซ้ำ (เผื่อ include อยู่ในส่วนที่ถูก re-exec)
    var resolver = null;
    function close(v){
        var m = document.getElementById('cfmModal');
        if (m) m.style.display = 'none';
        document.body.style.overflow = '';
        if (resolver){ var r = resolver; resolver = null; r(v); }
    }
    window.confirmDelete = function(opts){
        opts = opts || {};
        var m = document.getElementById('cfmModal');
        if (!m) return Promise.resolve(window.confirm(opts.message || 'ยืนยันการลบ?'));  // fallback
        document.getElementById('cfmTitle').textContent   = opts.title || 'ยืนยันการลบ?';
        document.getElementById('cfmMsg').textContent      = opts.message || 'การลบนี้ไม่สามารถกู้คืนได้';
        document.getElementById('cfmConfirm').textContent  = opts.confirmText || 'ยืนยันการลบ';
        m.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var box = m.querySelector('.cfm-box');            // restart pop animation ทุกครั้ง
        if (box){ box.style.animation = 'none'; void box.offsetWidth; box.style.animation = ''; }
        return new Promise(function(res){ resolver = res; });
    };
    // helper สำหรับฟอร์ม: <form onsubmit="return cfmForm(event, this, 'ข้อความ')">
    window.cfmForm = function(ev, form, message, title){
        ev.preventDefault();
        window.confirmDelete({ message: message, title: title }).then(function(ok){ if (ok) form.submit(); });
        return false;
    };
    document.addEventListener('click', function(e){
        if (!e.target) return;
        if (e.target.id === 'cfmConfirm') close(true);
        else if (e.target.id === 'cfmCancel') close(false);
        else if (e.target.id === 'cfmModal') close(false);
    });
    document.addEventListener('keydown', function(e){
        var m = document.getElementById('cfmModal');
        if (e.key === 'Escape' && m && m.style.display === 'flex') close(false);
    });
})();
</script>
