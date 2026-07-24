<?php
/**
 * COMPONENT: Page Loader (components/page_loader.php)
 * --------------------------------------------------
 * spinner เต็มจอตอนกำลังโหลดหน้าใหม่ (รีเฟรช/กดลิงก์/submit ฟอร์ม → PRG)
 * include 1 ครั้งต่อหน้า (วางใน sidebar → ครอบทุกหน้าหลัก) · self-contained · กัน bind ซ้ำ
 */
?>
<style>
    #pageLoader{position:fixed;inset:0;background:rgba(255,255,255,.55);backdrop-filter:blur(2px);z-index:99999;display:none;align-items:center;justify-content:center;}
    #pageLoader.show{display:flex;}
    #pageLoader .pl-spin{width:48px;height:48px;border:4px solid #E9E3F0;border-top-color:var(--clr-primary,#62368B);border-radius:50%;animation:plspin .7s linear infinite;}
    @keyframes plspin{to{transform:rotate(360deg);}}
</style>
<div id="pageLoader" aria-hidden="true"><div class="pl-spin"></div></div>
<script>
(function(){
    if (window.__pageLoaderBound) return; window.__pageLoaderBound = true;
    function show(){ var el=document.getElementById('pageLoader'); if(el) el.classList.add('show'); }
    function hide(){ var el=document.getElementById('pageLoader'); if(el) el.classList.remove('show'); }
    // กำลังออกจากหน้า (รีเฟรช/ลิงก์/submit → โหลดหน้าใหม่) → โชว์ spinner
    window.addEventListener('beforeunload', show);
    // กลับมาจาก cache (กดย้อนกลับ) → ซ่อน ไม่ให้ค้าง
    window.addEventListener('pageshow', hide);
})();
</script>
