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
</style>

<header class="top-header"
    style="background-color: #fcfcfc; border: none; padding: 1.5vh 2.5vh; display: flex; justify-content: space-between; align-items: center; border-radius: 4vh 0 0 4vh; box-shadow: -2px 4px 12px rgba(0, 0, 0, 0.05); margin-top: 2vh;">

    <div class="header-title" style="color: #6B7280; font-size: 1.1rem; font-weight: 500;">
        <span class="animate-item">
            <strong><?= htmlspecialchars($page_title ?? 'ระบบ') ?></strong>
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

        <?php $has_subtitle = (!empty($page_title2) || !empty($page_title3) || !empty($page_title4)); ?>

        <!-- <span class="animate-item <?= $has_subtitle ? 'delay-2' : 'delay-1' ?>">
            <span style="margin: 0 8px; color: #D1D5DB;">|</span>
            ข้อมูลของหน่วยงาน :
            <?= htmlspecialchars($affiliation_name) ?>
        </span> -->
    </div>

    <div style="display:flex; align-items:center;">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="#A78BFA" style="cursor: pointer;" class="animate-item delay-4">
            <path
                d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z" />
        </svg>
    </div>

</header>