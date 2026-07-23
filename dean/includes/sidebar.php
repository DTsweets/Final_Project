<?php
/**
 * Sidebar — โซนบุคลากร/คณบดี (Dean, ดูอย่างเดียว)
 */
$current_page = basename($_SERVER['PHP_SELF']);
$admin_name = isset($_SESSION['firstname'], $_SESSION['lastname']) ? $_SESSION['firstname'] . ' ' . $_SESSION['lastname'] : 'DEAN';
?>
<!-- ปุ่มเปิด/ปิดเมนู (แสดงเฉพาะจอเล็ก ≤1024px) -->
<button type="button" class="sidebar-toggle" aria-label="เปิดเมนู" onclick="toggleSidebar()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
    </svg>
</button>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>
<script>
    window.toggleSidebar = function () {
        var s = document.querySelector('.sidebar-body'), b = document.getElementById('sidebarBackdrop');
        if (s) s.classList.toggle('open'); if (b) b.classList.toggle('show');
    };
    window.closeSidebar = function () {
        var s = document.querySelector('.sidebar-body'), b = document.getElementById('sidebarBackdrop');
        if (s) s.classList.remove('open'); if (b) b.classList.remove('show');
    };
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.sidebar-nav a').forEach(function (a) {
            a.addEventListener('click', function () { if (window.innerWidth <= 1024) window.closeSidebar(); });
        });
    });
</script>
<aside class="sidebar-body">
    <div class="sidebar-logo">
        <img src="<?= $root ?? '../' ?>assets/images/logol.webp" alt="UP Logo" style="max-width: 100%; height: auto;"
            fetchpriority="high">
    </div>

    <div class="figma-profile-area" style="position: relative;">
        <div class="figma-avatar" style="overflow: hidden; position: relative; margin-bottom: 0;">
            <?php if (!empty($_SESSION['profile_image'])): ?>
                <img src="<?= $root ?? '../' ?>assets/images/profiles/<?= htmlspecialchars(pathinfo($_SESSION['profile_image'], PATHINFO_FILENAME) . '.webp') ?>"
                    alt="Profile" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
            <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
            <?php endif; ?>
        </div>
        <div class="figma-name" style="margin-top: 12px;"><?= htmlspecialchars(strtoupper($admin_name)) ?></div>
        <div style="font-size:12px;color:#C09A75;font-weight:600;margin-top:2px;">บุคลากร/คณบดี</div>
    </div>

    <nav class="sidebar-nav">
        <a href="<?= $root ?? '../' ?>dean/index.php"
            class="nav-item <?= ($current_page == 'index.php') ? 'active' : '' ?>">
            <div class="nav-icon-box">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83M22 12A10 10 0 0 0 12 2v10z"></path>
                </svg>
            </div>
            Dashboard
        </a>

        <div class="nav-category">รายงาน</div>
        <a href="<?= $root ?? '../' ?>dean/reports.php"
            class="nav-item <?= ($current_page == 'reports.php') ? 'active' : '' ?>">
            <div class="nav-icon-box">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
            </div>
            รายงาน GHG
        </a>

        <div class="nav-category">LABELS</div>
        <a href="<?= $root ?? '../' ?>logout.php" class="nav-item logout-item">
            <div class="nav-icon-box">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="margin-right:0;">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </div>
            ลงชื่อออก
        </a>
    </nav>
</aside>

<!-- SPA Router (ภายในโซน dean) -->
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const navLinks = document.querySelectorAll('.sidebar-nav .nav-item');
        let isNavigating = false;
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,0.55);backdrop-filter:blur(2px);cursor:wait;';
        overlay.innerHTML = '<div class="nav-spinner" role="status" aria-label="กำลังโหลด"></div>';
        document.body.appendChild(overlay);
        if (!document.getElementById('navSpinStyle')) {
            const _st = document.createElement('style');
            _st.id = 'navSpinStyle';
            _st.textContent = '@keyframes navspin{to{transform:rotate(360deg)}}.nav-spinner{width:46px;height:46px;border:4px solid rgba(124,58,237,.18);border-top-color:#7C3AED;border-radius:50%;animation:navspin .7s linear infinite;}';
            document.head.appendChild(_st);
        }

        function injectPageStyles(doc) {
            const existing = new Set(Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(l => l.href));
            const proms = Array.from(doc.querySelectorAll('link[rel="stylesheet"]'))
                .filter(l => !existing.has(l.href))
                .map(l => new Promise(res => {
                    const c = document.createElement('link');
                    c.rel = 'stylesheet'; c.href = l.href; c.onload = res; c.onerror = res;
                    document.head.appendChild(c);
                }));
            return Promise.all(proms);
        }

        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                if (this.classList.contains('logout-item')) return;
                if (this.classList.contains('active')) { e.preventDefault(); return; }
                if (isNavigating) { e.preventDefault(); return; }
                e.preventDefault();
                const url = this.getAttribute('href');
                if (!url || url === '#') return;
                isNavigating = true; overlay.style.display = 'flex';
                fetch(url).then(r => r.text()).then(html => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    if (doc.title) document.title = doc.title;
                    return injectPageStyles(doc).then(() => {
                        const nm = doc.querySelector('.main-content'), cm = document.querySelector('.main-content');
                        if (nm && cm) {
                            cm.innerHTML = nm.innerHTML;
                            cm.querySelectorAll('script').forEach(os => {
                                const ns = document.createElement('script');
                                Array.from(os.attributes).forEach(a => ns.setAttribute(a.name, a.value));
                                ns.appendChild(document.createTextNode(os.innerHTML));
                                os.parentNode.replaceChild(ns, os);
                            });
                            window.history.pushState(null, '', url);
                            navLinks.forEach(n => n.classList.remove('active'));
                            this.classList.add('active');
                        } else { window.location.href = url; }
                    });
                }).catch(() => { window.location.href = url; })
                  .finally(() => { isNavigating = false; overlay.style.display = 'none'; });
            });
        });
    });
</script>

<script>
/* ── จำตำแหน่ง scroll ข้ามการ reload (POST→redirect) กันหน้าเด้งขึ้นบนสุด — ใช้ร่วมทุกหน้าที่มี sidebar ── */
(function () {
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    var KEY = 'scrollY:' + location.pathname; // key ตาม path เพื่อไม่ให้ scroll ของหน้าหนึ่งไปเด้งอีกหน้า
    window.addEventListener('beforeunload', function () {
        try { sessionStorage.setItem(KEY, String(window.scrollY)); } catch (e) {}
    });
    window.addEventListener('load', function () {
        try {
            var y = sessionStorage.getItem(KEY);
            if (y !== null) { window.scrollTo(0, parseInt(y, 10) || 0); sessionStorage.removeItem(KEY); }
        } catch (e) {}
    });
})();
</script>
