<?php
/**
 * Sidebar Component for Super Admin
 */
$current_page = basename($_SERVER['PHP_SELF']);
$admin_name = isset($_SESSION['firstname']) && isset($_SESSION['lastname']) ? $_SESSION['firstname'] . ' ' . $_SESSION['lastname'] : 'ADMIN CESM';
$admin_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Admin';
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
    <!-- Logo -->
    <div class="sidebar-logo">
        <img src="<?= $root ?? '../' ?>assets/images/logol.webp" alt="UP Logo" style="max-width: 100%; height: auto;"
            fetchpriority="high">
    </div>

    <!-- User Profile Area (ตามแบบ Figma) -->
    <div class="figma-profile-area" style="position: relative;">
        <!-- Avatar Wrapper -->
        <a href="<?= $root ?? '../' ?>officer/profile.php"
            style="text-decoration: none; display: block; position: relative;" title="คลิกเพื่อเปลี่ยนโปรไฟล์">
            <div class="figma-avatar" style="overflow: hidden; cursor: pointer; position: relative; margin-bottom: 0;">
                <?php if (!empty($_SESSION['profile_image'])): ?>
                    <?php
                    $p_img = $_SESSION['profile_image'];
                    $webp_img = pathinfo($p_img, PATHINFO_FILENAME) . '.webp';
                    ?>
                    <img src="<?= $root ?? '../' ?>assets/images/profiles/<?= htmlspecialchars($webp_img) ?>" alt="Profile"
                        style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                <?php endif; ?>
            </div>
            <!-- Small camera icon indicator -->
            <div
                style="position: absolute; bottom: 0; right: 0; background: #E1CBAF; color: #62368B; border-radius: 50%; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; border: 2px solid #FFF;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    style="width: 12px; height: 12px;">
                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                    <circle cx="12" cy="13" r="3"></circle>
                </svg>
            </div>
        </a>
        <div class="figma-name" style="margin-top: 12px;"><?= htmlspecialchars(strtoupper($admin_name)) ?></div>
    </div>

    <!-- Navigation Scroll Area -->
    <nav class="sidebar-nav">
        <a href="<?= $root ?? '../' ?>officer/index.php"
            class="nav-item <?= ($current_page == 'index.php') ? 'active' : '' ?>">
            <div class="nav-icon-box">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83M22 12A10 10 0 0 0 12 2v10z"></path>
                </svg>
            </div>
            Dashboard
        </a>

        <!-- Category: กรอกข้อมูล -->
        <div class="nav-category">กรอกข้อมูล</div>
        <?php $is_data_entry_active = ($current_page == 'items.php' || $current_page == 'data_entry.php' || $current_page == 'data_entry_items.php' || $current_page == 'ghg.php' || $current_page == 'collect.php'); ?>
        <a href="<?= $root ?? '../' ?>officer/data_entry.php" id="data-entry-toggle"
            class="nav-item <?= $is_data_entry_active ? 'active' : '' ?>">
            <div class="nav-icon-box">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
            </div>
            กรอกข้อมูล
        </a>

        <!-- Sub menu items (Accordion Container) -->
        <div class="sub-nav-wrapper <?= $is_data_entry_active ? 'expanded' : '' ?>" id="data-entry-submenu"
            style="<?= $is_data_entry_active ? 'display:block !important;max-height:1000px !important;opacity:1 !important;overflow:visible !important;' : '' ?>">
            <div class="sub-nav">
                <!-- 1. UP Net Zero -->
                <a href="<?= $root ?? '../' ?>officer/items.php"
                    class="sub-item <?= ($current_page == 'items.php' || $current_page == 'data_entry.php' || $current_page == 'data_entry_items.php') ? 'active' : '' ?>">
                    <svg class="sub-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2.5C7 4.5 4 9 4 14C4 18.5 8 21.5 12 22.5V2.5Z" fill="#E1CBAF" />
                        <path d="M12 2.5C17 4.5 20 9 20 14C20 18.5 16 21.5 12 22.5" stroke="#BC8E5C" stroke-width="1.8"
                            stroke-linecap="round" />
                        <path d="M12 2.5V22.5" stroke="#BC8E5C" stroke-width="1.8" />
                        <path d="M12 7.5L17 6.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                        <path d="M12 12.5L18.5 10.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                        <path d="M12 17.5L16.5 15.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    UP Net Zero
                </a>
                <!-- 2. กิจกรรม -->
                <a href="<?= $root ?? '../' ?>officer/collect.php"
                    class="sub-item <?= ($current_page == 'collect.php') ? 'active' : '' ?>">
                    <svg class="sub-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2.5C7 4.5 4 9 4 14C4 18.5 8 21.5 12 22.5V2.5Z" fill="#E1CBAF" />
                        <path d="M12 2.5C17 4.5 20 9 20 14C20 18.5 16 21.5 12 22.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                        <path d="M12 2.5V22.5" stroke="#BC8E5C" stroke-width="1.8" />
                        <path d="M12 7.5L17 6.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                        <path d="M12 12.5L18.5 10.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                        <path d="M12 17.5L16.5 15.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    กิจกรรม
                </a>
                <?php if ((int) ($_SESSION['affiliation_id'] ?? 0) === 1): /* GHG Removal: เฉพาะศูนย์สิ่งแวดล้อม */ ?>
                <!-- 3. GHG Removal -->
                <a href="<?= $root ?? '../' ?>officer/ghg.php"
                    class="sub-item <?= ($current_page == 'ghg.php') ? 'active' : '' ?>">
                    <svg class="sub-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2.5C7 4.5 4 9 4 14C4 18.5 8 21.5 12 22.5V2.5Z" fill="#E1CBAF" />
                        <path d="M12 2.5C17 4.5 20 9 20 14C20 18.5 16 21.5 12 22.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                        <path d="M12 2.5V22.5" stroke="#BC8E5C" stroke-width="1.8" />
                        <path d="M12 7.5L17 6.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                        <path d="M12 12.5L18.5 10.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                        <path d="M12 17.5L16.5 15.5" stroke="#BC8E5C" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    GHG Removal
                </a>
                <?php endif; ?>
            </div>
        </div>
        <!-- Category: LABELS -->
        <div class="nav-category">LABELS</div>
        <a href="<?= $root ?? '../' ?>officer/profile.php"
            class="nav-item <?= ($current_page == 'profile.php') ? 'active' : '' ?>">
            <div class="nav-icon-box">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
            </div>
            เปลี่ยนโปรไฟล์
        </a>
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



<!-- Script SPA Router & Accordion -->
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const navLinks = document.querySelectorAll('.sidebar-nav .nav-item, .sidebar-nav .sub-item');
        let isNavigating = false;

        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.4);backdrop-filter:blur(2px);z-index:9999;display:none;cursor:wait;';
        document.body.appendChild(overlay);

        /**
         * ฉีด CSS ที่หน้าใหม่ต้องการ แต่ยังไม่มีใน <head> ปัจจุบัน
         * คืน Promise ที่รอจนกว่า stylesheet ที่เพิ่งเพิ่มจะโหลดเสร็จ
         */
        function injectPageStyles(doc) {
            const newLinks = Array.from(doc.querySelectorAll('link[rel="stylesheet"]'));
            const existingHrefs = new Set(
                Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(l => l.href)
            );

            const promises = newLinks
                .filter(l => !existingHrefs.has(l.href))
                .map(l => new Promise(resolve => {
                    const clone = document.createElement('link');
                    clone.rel = 'stylesheet';
                    clone.href = l.href;
                    clone.onload = resolve;
                    clone.onerror = resolve;
                    document.head.appendChild(clone);
                }));

            return Promise.all(promises);
        }

        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                const nextEl = this.nextElementSibling;
                const hasSubmenu = nextEl && nextEl.classList.contains('sub-nav-wrapper');
                const isSubItem = this.classList.contains('sub-item');

                // helper: กาง/พับ พร้อม inline style (กัน CSS ถูก cache/ไม่โหลด)
                const ddExpand = (w) => {
                    w.classList.add('expanded');
                    w.style.setProperty('display', 'block', 'important');
                    w.style.setProperty('max-height', '1000px', 'important');
                    w.style.setProperty('opacity', '1', 'important');
                    w.style.setProperty('overflow', 'visible', 'important');
                };
                const ddCollapseOthers = (keep) => document.querySelectorAll('.sub-nav-wrapper').forEach(w => {
                    if (w !== keep) {
                        w.classList.remove('expanded');
                        ['display','max-height','opacity','overflow'].forEach(p => w.style.removeProperty(p));
                    }
                });

                if (this.classList.contains('active')) {
                    e.preventDefault();
                    if (hasSubmenu) {
                        // อยู่ในกลุ่มนี้อยู่แล้ว → คงเมนูย่อยให้ "กางไว้เสมอ" (ไม่ toggle พับปิด)
                        ddCollapseOthers(nextEl);
                        ddExpand(nextEl);
                    }
                    return;
                }

                if (isNavigating) { e.preventDefault(); return; }

                if (!this.classList.contains('logout-item')) {
                    e.preventDefault();
                    const url = this.getAttribute('href');
                    if (!url || url === '#') return;

                    if (hasSubmenu) ddExpand(nextEl);
                    if (!isSubItem) ddCollapseOthers(nextEl);

                    isNavigating = true;
                    overlay.style.display = 'block';

                    fetch(url)
                        .then(res => res.text())
                        .then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            if (doc.title) document.title = doc.title;

                            // ฉีด CSS ของหน้าใหม่ก่อน แล้วค่อยแทนเนื้อหา
                            return injectPageStyles(doc).then(() => {
                                const newMain = doc.querySelector('.main-content');
                                const currentMain = document.querySelector('.main-content');

                                if (newMain && currentMain) {
                                    currentMain.innerHTML = newMain.innerHTML;

                                    currentMain.querySelectorAll('script').forEach(oldScript => {
                                        const newScript = document.createElement('script');
                                        Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                                        newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                                        oldScript.parentNode.replaceChild(newScript, oldScript);
                                    });

                                    window.history.pushState(null, '', url);
                                    navLinks.forEach(nav => nav.classList.remove('active'));
                                    this.classList.add('active');

                                    if (isSubItem) {
                                        const parentNav = this.closest('.sub-nav-wrapper').previousElementSibling;
                                        if (parentNav) parentNav.classList.add('active');
                                    }
                                    if (hasSubmenu) {
                                        const firstSub = nextEl.querySelector('.sub-item');
                                        if (firstSub) firstSub.classList.add('active');
                                    }
                                } else {
                                    window.location.href = url;
                                }
                            });
                        })
                        .catch(() => { window.location.href = url; })
                        .finally(() => {
                            isNavigating = false;
                            overlay.style.display = 'none';
                        });
                }
            });
        });

        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                isNavigating = false;
                overlay.style.display = 'none';
            }
        });
    });
</script>