<?php
/**
 * Header Component for Super Admin
 */
$firstname = $_SESSION['firstname'] ?? 'Admin';
$lastname = $_SESSION['lastname'] ?? '';
$affiliation_name = $_SESSION['affiliation_name'] ?? 'ADMIN(คณะ)';
?>

<style>
    @keyframes headerSlideIn {
        from {
            opacity: 0;
            transform: translateX(-15px);
        }

        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .animate-item {
        display: inline-block;
        opacity: 0;
        animation: headerSlideIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .delay-1 { animation-delay: 0.1s; }
    .delay-2 { animation-delay: 0.15s; }
    .delay-3 { animation-delay: 0.2s; }
    .delay-4 { animation-delay: 0.25s; }

    /* =========================================================
       GLOBAL DARK THEME OVERRIDES (Applies to all pages)
       ========================================================= */
    html.dark-theme {
        --bg-page: #0B0F19 !important;
        --bg-base: #0B0F19 !important;
        --bg-surface: #111827 !important;
        --bg-card: #1F2937 !important;
        --text-primary: #F9FAFB !important;
        --text-secondary: #D1D5DB !important;
        --border: #374151 !important;
    }

    html.dark-theme body,
    html.dark-theme .main-content {
        background-color: #0B0F19 !important;
        background-image: none !important;
        color: #F9FAFB !important;
    }

    html.dark-theme .top-header {
        background-color: #111827 !important;
        border-bottom: 1px solid #374151 !important;
    }

    /* Components & Cards */
    html.dark-theme .accordion-section,
    html.dark-theme .item-card,
    html.dark-theme .modal-box,
    html.dark-theme .admin-table-container,
    html.dark-theme .year-card,
    html.dark-theme .stat-card,
    html.dark-theme .card,
    html.dark-theme .tab-item {
        background-color: #111827 !important;
        border-color: #374151 !important;
        color: #F9FAFB !important;
    }

    /* ── Dark mode: Dashboard summary cards (db-*) ── */
    html.dark-theme .db-card,
    html.dark-theme .db-card-white {
        background-color: #111827 !important;
        border-color: #374151 !important;
    }

    html.dark-theme .db-big-num,
    html.dark-theme .db-big-unit,
    html.dark-theme .db-title {
        color: #F9FAFB !important;
    }

    html.dark-theme .db-card-desc {
        color: #D1D5DB !important;
    }

    html.dark-theme .db-card-subdesc,
    html.dark-theme .db-year-label {
        color: #9CA3AF !important;
    }

    html.dark-theme .db-section-label {
        background-color: #111827 !important;
        border-color: #374151 !important;
        color: #F9FAFB !important;
    }

    /* year dropdown */
    html.dark-theme .db-year-btn,
    html.dark-theme .db-year-menu {
        background-color: #1F2937 !important;
        border-color: #374151 !important;
        color: #F9FAFB !important;
    }
    html.dark-theme .db-year-option {
        color: #F9FAFB !important;
    }
    html.dark-theme .db-year-option:hover,
    html.dark-theme .db-year-option.active {
        background-color: #374151 !important;
    }

    /* การ์ด Scope ให้เป็นโทนเข้มแบบมีสีจาง (เลขสียังอ่านชัด) */
    html.dark-theme .db-card-scope1 { background-color: #2A1A0E !important; border-color: #7C2D12 !important; }
    html.dark-theme .db-card-scope2 { background-color: #2A0E1C !important; border-color: #9D174D !important; }
    html.dark-theme .db-card-scope3 { background-color: #0E1A2A !important; border-color: #1E3A8A !important; }

    /* Sidebar Overrides */
    html.dark-theme .sidebar-body {
        background-color: #111827 !important;
        border-color: #374151 !important;
    }

    html.dark-theme .figma-name,
    html.dark-theme .nav-category {
        color: #D1D5DB !important;
    }

    html.dark-theme .nav-item {
        background-color: transparent !important;
        border-color: #374151 !important;
        color: #F9FAFB !important;
    }

    html.dark-theme .nav-item:hover {
        background-color: #1F2937 !important;
    }

    html.dark-theme .nav-item.active {
        background-color: #62368B !important;
        color: #FFFFFF !important;
    }

    html.dark-theme .sub-item {
        background-color: #1F2937 !important;
        border-color: #374151 !important;
        color: #D1D5DB !important;
    }

    html.dark-theme .sub-item:hover {
        background-color: #374151 !important;
    }

    html.dark-theme .sub-item.active {
        background-color: #111827 !important;
        color: #FBB03B !important;
    }

    html.dark-theme .figma-profile-area {
        border-color: #374151 !important;
    }

    html.dark-theme .figma-avatar {
        background-color: #1F2937 !important;
    }

    /* Inputs & Table Cells */
    html.dark-theme .header-pill,
    html.dark-theme .cell-pill,
    html.dark-theme .vol-input,
    html.dark-theme .form-control-dark,
    html.dark-theme input,
    html.dark-theme select,
    html.dark-theme .data-table th,
    html.dark-theme .data-table td {
        background-color: #1F2937 !important;
        border-color: #4B5563 !important;
        color: #F9FAFB !important;
    }

    /* Interactables */
    html.dark-theme .accordion-header,
    html.dark-theme .scope-card-option {
        background-color: #111827 !important;
        color: #F9FAFB !important;
    }

    html.dark-theme .accordion-header:hover,
    html.dark-theme .scope-card-option:hover,
    html.dark-theme .data-table tbody tr:hover,
    html.dark-theme .tab-item:hover {
        background-color: #1F2937 !important;
    }

    /* Pills & Specifics */
    html.dark-theme .info-pill {
        background-color: #1F2937 !important;
        color: #F9FAFB !important;
    }

    html.dark-theme .total-pill {
        background-color: #374151 !important;
        border-color: #4B5563 !important;
        color: #F9FAFB !important;
    }

    /* Typography Overrides */
    html.dark-theme .accordion-title,
    html.dark-theme .main-heading,
    html.dark-theme .modal-title,
    html.dark-theme .page-heading h2,
    html.dark-theme h1,
    html.dark-theme h2,
    html.dark-theme h3 {
        color: #F9FAFB !important;
    }

    html.dark-theme .form-label-dark {
        color: #D1D5DB !important;
    }

    /* Override Hardcoded Inline Styles */
    html.dark-theme [style*="color: #4B5563"],
    html.dark-theme [style*="color: #6B7280"],
    html.dark-theme [style*="color: #1F2937"],
    html.dark-theme [style*="color: #374151"],
    html.dark-theme [style*="color: #111827"] {
        color: #D1D5DB !important;
    }

    html.dark-theme [style*="background: #FFFFFF"],
    html.dark-theme [style*="background-color: #FFFFFF"],
    html.dark-theme [style*="background: white"],
    html.dark-theme [style*="background-color: white"],
    html.dark-theme [style*="background: #FDFCF8"],
    html.dark-theme [style*="background-color: #fcfcfc"] {
        background-color: #111827 !important;
        border-color: #374151 !important;
        color: #F9FAFB !important;
    }

    html.dark-theme [style*="background: #F8FAFC"],
    html.dark-theme [style*="background-color: #F8FAFC"],
    html.dark-theme [style*="background: #F3F4F6"],
    html.dark-theme [style*="background-color: #F3F4F6"],
    html.dark-theme [style*="background: #F9FAFB"],
    html.dark-theme [style*="background-color: #F9FAFB"] {
        background-color: #1F2937 !important;
        border-color: #4B5563 !important;
    }

    /* Modals Overlay */
    html.dark-theme .modal-overlay {
        background: rgba(0, 0, 0, 0.75) !important;
    }
</style>

<header class="top-header"
    style="background-color: #fcfcfc; border: none; padding-bottom: 0; display: flex; justify-content: space-between; align-items: center; border-radius: 4vh 0 0 4vh; box-shadow: -2px 4px 12px rgba(0, 0, 0, 0.05); margin-top: 2vh;">

    <div class="header-title" style="color: #6B7280; font-size: 1.1rem; font-weight: 500;">
        <span class="animate-item">
            <strong><?= htmlspecialchars($page_title ?? 'หน้าระบบ') ?></strong>
        </span>

        <?php if (!empty($page_title2) && $page_title2 !== 'null'): ?>
            <span class="animate-item delay-1">
                <span style="margin: 0 8px; color: #D1D5DB;">|</span>
                <?= htmlspecialchars($page_title2) ?>
            </span>
        <?php endif; ?>

        <?php if (!empty($page_title3) && $page_title3 !== 'null'): ?>
            <span class="animate-item delay-2">
                <span style="margin: 0 8px; color: #D1D5DB;">|</span>
                <?= htmlspecialchars($page_title3) ?>
            </span>
        <?php endif; ?>
        <?php if (!empty($page_title4) && $page_title4 !== 'null'): ?>
            <span class="animate-item delay-3">
                <span style="margin: 0 8px; color: #D1D5DB;">|</span>
                <?= htmlspecialchars($page_title4) ?>
            </span>
        <?php endif; ?>
    </div>

    <div style="display:flex; align-items:center; gap: 12px; margin-right: 15px;">
        <!-- Theme Toggle Button -->
        <button id="theme-toggle" class="animate-item delay-4" title="Toggle Dark/Light Mode"
            style="background: #F3F4F6; border: 1px solid #E5E7EB; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); color: #4B5563;">
            <svg id="theme-icon-light" style="display: none; pointer-events: none;" viewBox="0 0 24 24" width="20"
                height="20" fill="none" stroke="#F59E0B" stroke-width="2.5" stroke-linecap="round"
                stroke-linejoin="round">
                <circle cx="12" cy="12" r="5"></circle>
                <line x1="12" y1="1" x2="12" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="23"></line>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                <line x1="1" y1="12" x2="3" y2="12"></line>
                <line x1="21" y1="12" x2="23" y2="12"></line>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
            <svg id="theme-icon-dark" style="pointer-events: none;" viewBox="0 0 24 24" width="20" height="20"
                fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>
        </button>

        <svg viewBox="0 0 24 24" width="28" height="28" fill="#A78BFA" style="cursor: pointer;"
            class="animate-item delay-4">
            <path
                d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z" />
        </svg>
    </div>

</header>

<script>
    // FIX SPA ROUTING BUG:
    // Because header.php is re-injected via innerHTML by the SPA router, DOMContentLoaded fires ONLY ONCE on page load,
    // and subsequent re-loads of the header destroy the event listeners.
    // To solve this, we initialize immediately for the current DOM, AND use event delegation for clicks.

    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.documentElement.classList.add('dark-theme');
        } else {
            document.documentElement.classList.remove('dark-theme');
        }

        updateThemeUI();
    }

    function updateThemeUI() {
        const themeToggle = document.getElementById('theme-toggle');
        const iconLight = document.getElementById('theme-icon-light');
        const iconDark = document.getElementById('theme-icon-dark');

        if (themeToggle && iconLight && iconDark) {
            const isDark = document.documentElement.classList.contains('dark-theme');
            iconDark.style.display = isDark ? 'none' : 'block';
            iconLight.style.display = isDark ? 'block' : 'none';
            themeToggle.style.background = isDark ? '#374151' : '#F3F4F6';
            themeToggle.style.borderColor = isDark ? '#4B5563' : '#E5E7EB';
        }
    }

    // Run initialization immediately when script is evaluated
    initTheme();

    // Use global Event Delegation so the button always works even after SPA re-injection
    if (!window.themeToggleInitialized) {
        window.themeToggleInitialized = true;

        document.addEventListener('click', (e) => {
            const toggle = e.target.closest('#theme-toggle');
            if (toggle) {
                document.documentElement.classList.toggle('dark-theme');
                const isDark = document.documentElement.classList.contains('dark-theme');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                updateThemeUI();
            }
        });

        document.addEventListener('mouseover', (e) => {
            const toggle = e.target.closest('#theme-toggle');
            if (toggle) {
                const isDark = document.documentElement.classList.contains('dark-theme');
                toggle.style.transform = 'scale(1.05)';
                toggle.style.background = isDark ? '#4B5563' : '#E5E7EB';
            }
        });

        document.addEventListener('mouseout', (e) => {
            const toggle = e.target.closest('#theme-toggle');
            if (toggle) {
                const isDark = document.documentElement.classList.contains('dark-theme');
                toggle.style.transform = 'scale(1)';
                toggle.style.background = isDark ? '#374151' : '#F3F4F6';
            }
        });
    }
</script>