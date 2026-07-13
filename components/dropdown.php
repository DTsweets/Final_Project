<?php
/**
 * COMPONENT: Custom Dropdown (components/dropdown.php)
 * -----------------------------------------------------
 * Dropdown แบบ custom (ไม่ใช่ <select> ปกติ) ใช้ซ้ำได้ทั่วทั้งระบบ
 *
 * วิธีใช้:
 *   $dd_id          = 'รหัสไม่ซ้ำของ dropdown นี้ในหน้า'  (required)
 *   $dd_name        = 'ชื่อ field ที่จะส่งไปกับ form'      (required)
 *   $dd_options     = array ของตัวเลือก รองรับ 2 รูปแบบ:
 *                      1) แบบ list ของ value ตรงๆ: [2567, 2568, 2569]
 *                      2) แบบ label/value:        [['value' => 1, 'label' => '[Scope 1] ไฟฟ้า'], ...]
 *   $dd_selected    = ค่าที่เลือกไว้ล่วงหน้า (optional, default: '')
 *   $dd_placeholder = ข้อความตอนยังไม่เลือก (optional)
 *   $dd_required    = true/false (optional, default: true)
 *   $dd_disabled    = true/false (optional, default: false)
 *
 * ตัวอย่างเรียกใช้:
 *   <?php
 *     $dd_id = 'yearSelectAddYear';
 *     $dd_name = 'new_year';
 *     $dd_options = $available_years; // [2567, 2568, ...]
 *     $dd_placeholder = '-- เลือกปีงบประมาณ --';
 *     include __DIR__ . '/../components/dropdown.php';
 *   ?>
 *
 * หมายเหตุ: component นี้ self-contained (มี CSS + JS ของตัวเอง ผูกกับ $dd_id)
 * จึงวางหลาย dropdown ในหน้าเดียวกันได้โดยไม่ชนกัน
 */

// ── Normalize input ───────────────────────────────
$dd_id          = $dd_id ?? ('dd_' . uniqid());
$dd_name        = $dd_name ?? $dd_id;
$dd_options     = $dd_options ?? [];
$dd_selected    = $dd_selected ?? '';
$dd_placeholder = $dd_placeholder ?? '-- กรุณาเลือก --';
$dd_required    = $dd_required ?? true;
$dd_disabled    = $dd_disabled ?? false;
$dd_style       = $dd_style ?? '';
// class เสริม เช่น variant 'dd-field' (ฟอร์ม), 'dd-pill' (ปุ่มบน dashboard), 'dd-compact'
$dd_class       = $dd_class ?? '';

// แปลง options ให้เป็นรูปแบบเดียวกันเสมอ: [['value' => ..., 'label' => ...], ...]
$dd_normalized = [];
foreach ($dd_options as $key => $opt) {
    if (is_array($opt)) {
        $dd_normalized[] = [
            'value' => $opt['value'] ?? $key,
            'label' => $opt['label'] ?? ($opt['value'] ?? $key),
        ];
    } else {
        // list ของ value ตรงๆ เช่น [2567, 2568]
        $dd_normalized[] = [
            'value' => $opt,
            'label' => $opt,
        ];
    }
}

// หา label ของค่าที่เลือกไว้ล่วงหน้า (ถ้ามี) เพื่อโชว์ใน trigger ทันทีตอน render
$dd_selected_label = null;
if ($dd_selected !== '' && $dd_selected !== null) {
    foreach ($dd_normalized as $opt) {
        if ((string) $opt['value'] === (string) $dd_selected) {
            $dd_selected_label = $opt['label'];
            break;
        }
    }
}

$dd_has_options = count($dd_normalized) > 0;
?>

<div class="dd-component <?= htmlspecialchars($dd_class) ?>" id="<?= htmlspecialchars($dd_id) ?>"
    data-empty-label="<?= htmlspecialchars($dd_placeholder) ?>"
    <?= $dd_style !== '' ? 'style="' . htmlspecialchars($dd_style) . '"' : '' ?>>
    <input type="hidden" name="<?= htmlspecialchars($dd_name) ?>" id="<?= htmlspecialchars($dd_id) ?>_input"
        value="<?= htmlspecialchars((string) $dd_selected) ?>"
        <?= $dd_required ? 'data-required="1"' : '' ?>>

   <button type="button" class="form-control-dark dd-trigger" id="<?= htmlspecialchars($dd_id) ?>_trigger"
        onclick="ddToggle('<?= htmlspecialchars($dd_id) ?>')"
        <?= $dd_style !== '' ? 'style="' . htmlspecialchars($dd_style) . '"' : '' ?>
        <?= ($dd_disabled || !$dd_has_options) ? 'disabled' : '' ?>>
        <span class="dd-label" id="<?= htmlspecialchars($dd_id) ?>_label"
            style="<?= $dd_selected_label === null ? 'color:#9CA3AF;' : 'color:#374151;' ?>">
            <?= htmlspecialchars($dd_has_options ? ($dd_selected_label ?? $dd_placeholder) : 'ไม่มีตัวเลือก') ?>
        </span>
        <svg class="dd-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
    </button>

    <div class="dd-menu" id="<?= htmlspecialchars($dd_id) ?>_menu">
        <?php foreach ($dd_normalized as $opt): ?>
            <div class="dd-option <?= (string) $opt['value'] === (string) $dd_selected ? 'active' : '' ?>"
                data-value="<?= htmlspecialchars((string) $opt['value']) ?>"
                onclick="ddSelect('<?= htmlspecialchars($dd_id) ?>', this)">
                <?= htmlspecialchars((string) $opt['label']) ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$dd_has_options): ?>
            <div class="dd-empty">ไม่มีตัวเลือกให้เลือก</div>
        <?php endif; ?>
    </div>
</div>

<?php if (!defined('DD_COMPONENT_ASSETS_LOADED')): ?>
    <?php define('DD_COMPONENT_ASSETS_LOADED', true); ?>
    <style>
        .dd-component {
            position: relative;
            width: 100%;
        }

        .dd-trigger {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            cursor: pointer;
            text-align: center;
            gap: 8px;
            background: #fff;
        }

        .dd-trigger:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        .dd-trigger .dd-chevron {
            flex-shrink: 0;
            color: #9CA3AF;
            transition: transform 0.2s;
        }

        .dd-trigger.open .dd-chevron {
            transform: rotate(180deg);
        }

        .dd-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            z-index: 50;
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            padding: 6px;
            max-height: 160px;
            /* แสดงผลสูงสุด ~5 รายการ ส่วนที่เหลือเลื่อนดูได้ (overflow) */
            overflow-y: auto;
        }

        .dd-menu.open {
            display: block;
        }

        .dd-menu::-webkit-scrollbar {
            width: 6px;
        }

        .dd-menu::-webkit-scrollbar-track {
            background: #F3F4F6;
            border-radius: 4px;
        }

        .dd-menu::-webkit-scrollbar-thumb {
            background: #D1D5DB;
            border-radius: 4px;
        }

        .dd-menu::-webkit-scrollbar-thumb:hover {
            background: #9CA3AF;
        }

        .dd-option {
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            color: #374151;
            transition: background 0.15s;
        }

        .dd-option:hover {
            background: #F3F4F6;
        }

        .dd-option.active {
            background: #EDE9FE;
            color: #6D28D9;
            font-weight: 600;
        }

        .dd-empty {
            padding: 10px 14px;
            font-size: 0.9rem;
            color: #9CA3AF;
            text-align: center;
        }

        /* Compact variant: สำหรับใช้เป็น inline filter เล็กๆ เช่น "Show entries" */
        .dd-component.dd-compact {
            width: auto;
            display: inline-block;
        }

        .dd-component.dd-compact .dd-trigger {
            padding: 4px 10px;
            font-size: 0.9rem;
            border-radius: 8px;
            min-width: 70px;
            gap: 6px;
        }

        .dd-component.dd-compact .dd-chevron {
            width: 14px;
            height: 14px;
        }

        .dd-component.dd-compact .dd-menu {
            min-width: 70px;
            max-height: 180px;
        }

        .dd-component.dd-compact .dd-option {
            padding: 6px 10px;
            font-size: 0.9rem;
        }

        /* ── Variant: dd-field ── ใช้ใน form/modal: label ชิดซ้าย, chevron ชิดขวา
           (ทรง/ขนาด base มาจาก .form-control-dark ของแต่ละหน้า) */
        .dd-component.dd-field .dd-trigger {
            justify-content: space-between;
            text-align: left;
        }

        /* ── Variant: dd-pill ── ปุ่มเลือกปีบน dashboard (ทรงแคปซูล + glass) */
        .dd-component.dd-pill {
            width: auto;
            display: inline-block;
        }
        .dd-component.dd-pill .dd-trigger {
            box-sizing: border-box;
            height: 45px;
            border-radius: 14px;
            padding: 0 18px;
            min-width: 100px;
            gap: 8px;
            line-height: 1;
            font-family: 'Kanit', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            border: 1px solid var(--border);
            background: var(--glass-bg);
        }
        .dd-component.dd-pill .dd-trigger:hover {
            background: #fff;
            border-color: var(--clr-primary);
            box-shadow: 0 10px 20px rgba(98, 54, 139, 0.1);
        }
        .dd-component.dd-pill .dd-label { color: var(--text-primary) !important; }
        .dd-component.dd-pill .dd-menu { min-width: 130px; border-radius: 14px; }
        .dd-component.dd-pill .dd-option { font-weight: 600; }
        .dd-component.dd-pill .dd-option.active { background: var(--clr-primary); color: #fff; }

        /* ── Dark mode (built-in) ── ทุก dropdown รองรับอัตโนมัติเมื่อ html.dark-theme */
        html.dark-theme .dd-component .dd-trigger,
        html.dark-theme .dd-component .dd-menu {
            background-color: #1F2937 !important;
            border-color: #374151 !important;
            color: #F9FAFB !important;
        }
        html.dark-theme .dd-component .dd-label { color: #F9FAFB !important; }
        html.dark-theme .dd-component .dd-option { color: #F9FAFB !important; }
        html.dark-theme .dd-component .dd-option:hover { background-color: #374151 !important; }
        html.dark-theme .dd-component .dd-option.active { background: var(--clr-primary) !important; color: #fff !important; }
    </style>

    <script>
        // ── Custom Dropdown: shared logic (โหลดครั้งเดียวต่อหน้า) ──
        function ddToggle(id) {
            const menu = document.getElementById(id + '_menu');
            const trigger = document.getElementById(id + '_trigger');
            if (!menu || !trigger || trigger.disabled) return;
            const isOpen = menu.classList.contains('open');
            document.querySelectorAll('.dd-menu.open').forEach(m => m.classList.remove('open'));
            document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
            if (!isOpen) {
                menu.classList.add('open');
                trigger.classList.add('open');
            }
        }

        function ddSelect(id, optionEl) {
            const value = optionEl.dataset.value;
            const label = optionEl.textContent.trim();
            const input = document.getElementById(id + '_input');
            const labelEl = document.getElementById(id + '_label');

            if (input) input.value = value;
            if (labelEl) {
                labelEl.textContent = label;
                labelEl.style.color = '#374151';
            }

            document.querySelectorAll('#' + id + '_menu .dd-option').forEach(opt => {
                opt.classList.toggle('active', opt === optionEl);
            });

            const menu = document.getElementById(id + '_menu');
            const trigger = document.getElementById(id + '_trigger');
            if (menu) menu.classList.remove('open');
            if (trigger) trigger.classList.remove('open');

            // ยิง custom event เผื่อหน้าอยากดักฟังตอนค่าเปลี่ยน
            document.getElementById(id)?.dispatchEvent(
                new CustomEvent('dd:change', { detail: { value, label } })
            );
        }

        function ddSetValue(id, value, label) {
            const input = document.getElementById(id + '_input');
            const labelEl = document.getElementById(id + '_label');
            if (input) input.value = value;
            if (labelEl) {
                labelEl.textContent = label;
                labelEl.style.color = '#374151';
            }
            document.querySelectorAll('#' + id + '_menu .dd-option').forEach(opt => {
                opt.classList.toggle('active', String(opt.dataset.value) === String(value));
            });
        }

        // ปิดเมนูเมื่อคลิกนอกกรอบ
        document.addEventListener('click', function (e) {
            document.querySelectorAll('.dd-component').forEach(wrap => {
                if (!wrap.contains(e.target)) {
                    wrap.querySelectorAll('.dd-menu.open').forEach(m => m.classList.remove('open'));
                    wrap.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
                }
            });
        });

        // ตรวจ required ก่อน submit ฟอร์มที่มี dropdown นี้อยู่
        document.addEventListener('submit', function (e) {
            const form = e.target;
            const requiredInputs = form.querySelectorAll('.dd-component input[type="hidden"][data-required="1"]');
            for (const input of requiredInputs) {
                if (!input.value) {
                    e.preventDefault();
                    alert('กรุณาเลือกข้อมูลให้ครบถ้วน');
                    return;
                }
            }
        });
    </script>
<?php endif; ?>