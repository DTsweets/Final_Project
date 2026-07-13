<?php
/**
 * COMPONENT: Button (components/button.php)
 * -----------------------------------------
 * ปุ่มใช้ซ้ำได้ทั่วทั้งระบบ — hover แบบยกขึ้น + เงาสีเข้มขึ้น (เหมือน .btn-primary เดิม)
 * รองรับหลายสี/ขนาด/ไอคอน และเป็นได้ทั้ง <button> และลิงก์ <a>
 *
 * วิธีใช้ (ตัวแปรทั้งหมด optional ยกเว้น $btn_label):
 *   $btn_label    = ข้อความบนปุ่ม                         (required)
 *   $btn_variant  = 'primary' | 'success' | 'danger' | 'warning' | 'secondary' | 'ghost'
 *                   (default 'primary')
 *   $btn_size     = 'sm' | 'md' | 'lg'                    (default 'md')
 *   $btn_type     = 'button' | 'submit' | 'reset'         (default 'button')
 *   $btn_icon     = ไอคอน (optional) — ใส่ได้ทั้ง emoji หรือ raw SVG (โค้ด HTML ที่เชื่อถือได้)
 *   $btn_icon_pos = 'left' | 'right'                      (default 'left')
 *   $btn_href     = ถ้ากำหนด → render เป็นลิงก์ <a> แทน <button> (optional)
 *   $btn_name     = ชื่อ field (สำหรับ submit ในฟอร์ม)      (optional)
 *   $btn_value    = ค่าของ field                          (optional)
 *   $btn_id       = id ของปุ่ม                             (optional)
 *   $btn_onclick  = โค้ด JS ตอนคลิก                        (optional)
 *   $btn_disabled = true/false                            (default false)
 *   $btn_full     = true → กว้างเต็ม container             (default false)
 *   $btn_class    = class เสริม                            (optional)
 *   $btn_style    = inline style เสริม                     (optional)
 *
 * ตัวอย่าง:
 *   <?php
 *     $btn_label='เพิ่มปี'; $btn_variant='success';
 *     $btn_icon='📅'; $btn_type='submit';
 *     include __DIR__ . '/../components/button.php';
 *   ?>
 *
 * หมายเหตุ: self-contained (CSS ของตัวเอง โหลดครั้งเดียวต่อหน้า)
 *   $btn_icon เป็น markup ที่ผู้พัฒนากำหนดเอง (ไม่ escape เพื่อให้ใส่ SVG ได้)
 *   — อย่าส่งค่าจากผู้ใช้เข้ามาโดยตรง
 */

// ── Normalize ─────────────────────────────────────
$btn_label    = $btn_label    ?? '';
$btn_variant  = $btn_variant  ?? 'primary';
$btn_size     = $btn_size     ?? 'md';
$btn_type     = $btn_type     ?? 'button';
$btn_icon     = $btn_icon     ?? '';
$btn_icon_pos = $btn_icon_pos ?? 'left';
$btn_href     = $btn_href     ?? '';
$btn_name     = $btn_name     ?? '';
$btn_value    = $btn_value    ?? '';
$btn_id       = $btn_id       ?? '';
$btn_onclick  = $btn_onclick  ?? '';
$btn_disabled = $btn_disabled ?? false;
$btn_full     = $btn_full     ?? false;
$btn_class    = $btn_class    ?? '';
$btn_style    = $btn_style    ?? '';

$btn_allowed_variants = ['primary', 'success', 'danger', 'warning', 'secondary', 'ghost'];
if (!in_array($btn_variant, $btn_allowed_variants, true)) $btn_variant = 'primary';
$btn_allowed_sizes = ['sm', 'md', 'lg'];
if (!in_array($btn_size, $btn_allowed_sizes, true)) $btn_size = 'md';
if (!in_array($btn_type, ['button', 'submit', 'reset'], true)) $btn_type = 'button';

$btn_classes = 'btn-c btn-c--' . $btn_variant . ' btn-c--' . $btn_size
    . ($btn_full ? ' btn-c--full' : '')
    . ($btn_class !== '' ? ' ' . $btn_class : '');

// ส่วนเนื้อในปุ่ม (ไอคอน + ข้อความ) — ใช้ร่วมกันทั้ง <button>/<a>
$btn_inner = '';
if ($btn_icon !== '' && $btn_icon_pos === 'left') $btn_inner .= '<span class="btn-c__icon">' . $btn_icon . '</span>';
if ($btn_label !== '') $btn_inner .= '<span class="btn-c__label">' . htmlspecialchars($btn_label) . '</span>';
if ($btn_icon !== '' && $btn_icon_pos === 'right') $btn_inner .= '<span class="btn-c__icon">' . $btn_icon . '</span>';
?>
<?php if ($btn_href !== '' && !$btn_disabled): ?>
    <a class="<?= htmlspecialchars($btn_classes) ?>"
        href="<?= htmlspecialchars($btn_href) ?>"
        <?= $btn_id !== '' ? 'id="' . htmlspecialchars($btn_id) . '"' : '' ?>
        <?= $btn_onclick !== '' ? 'onclick="' . htmlspecialchars($btn_onclick) . '"' : '' ?>
        <?= $btn_style !== '' ? 'style="' . htmlspecialchars($btn_style) . '"' : '' ?>><?= $btn_inner ?></a>
<?php else: ?>
    <button class="<?= htmlspecialchars($btn_classes) ?>"
        type="<?= htmlspecialchars($btn_type) ?>"
        <?= $btn_id !== '' ? 'id="' . htmlspecialchars($btn_id) . '"' : '' ?>
        <?= $btn_name !== '' ? 'name="' . htmlspecialchars($btn_name) . '"' : '' ?>
        <?= $btn_value !== '' ? 'value="' . htmlspecialchars($btn_value) . '"' : '' ?>
        <?= $btn_onclick !== '' ? 'onclick="' . htmlspecialchars($btn_onclick) . '"' : '' ?>
        <?= $btn_disabled ? 'disabled' : '' ?>
        <?= $btn_style !== '' ? 'style="' . htmlspecialchars($btn_style) . '"' : '' ?>><?= $btn_inner ?></button>
<?php endif; ?>

<?php
// ล้างตัวแปรต่อ instance — กันค่าค้างไปยัง include ครั้งถัดไป
unset(
    $btn_label, $btn_variant, $btn_size, $btn_type, $btn_icon, $btn_icon_pos,
    $btn_href, $btn_name, $btn_value, $btn_id, $btn_onclick, $btn_disabled,
    $btn_full, $btn_class, $btn_style, $btn_classes, $btn_inner,
    $btn_allowed_variants, $btn_allowed_sizes
);
?>

<?php if (!defined('BTN_COMPONENT_ASSETS_LOADED')): ?>
    <?php define('BTN_COMPONENT_ASSETS_LOADED', true); ?>
    <style>
        .btn-c {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            border-radius: 999px;
            font-family: 'Kanit', sans-serif;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s ease, background 0.2s ease, opacity 0.2s ease;
        }

        .btn-c__icon { display: inline-flex; align-items: center; font-size: 1.05em; }
        .btn-c__icon svg { width: 1.1em; height: 1.1em; display: block; }

        /* ── ขนาด ── */
        .btn-c--sm { padding: 8px 18px; font-size: 0.85rem; }
        .btn-c--md { padding: 12px 30px; font-size: 0.95rem; }
        .btn-c--lg { padding: 15px 40px; font-size: 1.05rem; }
        .btn-c--full { width: 100%; }

        /* ── hover: ยกขึ้น + เงาสีเข้มขึ้น (เอฟเฟกต์เดียวกับ .btn-primary เดิม) ── */
        .btn-c:hover { transform: translateY(-2px); }
        .btn-c:active { transform: translateY(0); }
        .btn-c:focus-visible { outline: 3px solid rgba(98, 54, 139, 0.35); outline-offset: 2px; }

        .btn-c:disabled,
        .btn-c[disabled] {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* ── สี (variant) ── base shadow + hover shadow เข้มขึ้น ── */
        .btn-c--primary   { background: var(--clr-primary, #62368B); color: #fff; box-shadow: 0 8px 20px rgba(98, 54, 139, 0.25); }
        .btn-c--primary:hover   { box-shadow: 0 15px 30px rgba(98, 54, 139, 0.4); }

        .btn-c--success   { background: #16A34A; color: #fff; box-shadow: 0 8px 20px rgba(22, 163, 74, 0.28); }
        .btn-c--success:hover   { box-shadow: 0 15px 30px rgba(22, 163, 74, 0.45); background: #15903F; }

        .btn-c--danger    { background: #EF4444; color: #fff; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.28); }
        .btn-c--danger:hover    { box-shadow: 0 15px 30px rgba(239, 68, 68, 0.45); background: #DC2626; }

        .btn-c--warning   { background: #F59E0B; color: #fff; box-shadow: 0 8px 20px rgba(245, 158, 11, 0.28); }
        .btn-c--warning:hover   { box-shadow: 0 15px 30px rgba(245, 158, 11, 0.45); background: #D97706; }

        .btn-c--secondary { background: #F3F4F6; color: #4B5563; }
        .btn-c--secondary:hover { background: #E5E7EB; box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08); }

        .btn-c--ghost     { background: transparent; color: var(--clr-primary, #62368B); box-shadow: inset 0 0 0 1.5px var(--clr-primary, #62368B); }
        .btn-c--ghost:hover     { background: rgba(98, 54, 139, 0.08); }

        /* ── Dark mode ── */
        html.dark-theme .btn-c--secondary { background: #374151; color: #F9FAFB; }
        html.dark-theme .btn-c--secondary:hover { background: #4B5563; }
    </style>
<?php endif; ?>
