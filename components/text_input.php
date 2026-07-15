<?php
/**
 * COMPONENT: Custom Text Input (components/text_input.php)
 * --------------------------------------------------------
 * ช่องกรอกข้อความแบบใช้ซ้ำได้ทั่วทั้งระบบ — ทรง/เอฟเฟกต์เดียวกับช่อง
 * "ชื่อประเภทการปล่อยก๊าซ" ในหน้ากรอกข้อมูล (ขอบมน + โฟกัสเรืองม่วง)
 * รองรับ "คำแนะนำ (autocomplete)" แบบ custom ที่เข้าชุดกับ components/dropdown.php
 * (แทนกล่อง autocomplete ของเบราว์เซอร์ที่ไม่เข้าธีม)
 *
 * วิธีใช้ (ตัวแปรทั้งหมดเป็น optional ยกเว้น $ti_id, $ti_name):
 *   $ti_id          = 'รหัสไม่ซ้ำในหน้า'                 (required)
 *   $ti_name        = 'ชื่อ field ที่ส่งไปกับ form'       (required)
 *   $ti_value       = ค่าเริ่มต้น                         (default '')
 *   $ti_label       = ข้อความ label ด้านบน (ถ้าไม่ใส่ = ไม่แสดง label)
 *   $ti_placeholder = placeholder                        (default '')
 *   $ti_type        = 'text' | 'email' | 'number' | 'password' | 'tel' (default 'text')
 *   $ti_required    = true/false                         (default true)
 *   $ti_disabled    = true/false                         (default false)
 *   $ti_suggestions = คำแนะนำ (optional) รองรับ 2 รูปแบบ:
 *                       1) list ของ string:  ['ดีเซล (Diesel)', 'ไฟฟ้า']
 *                       2) label/value:       [['value'=>1,'label'=>'ดีเซล'], ...]
 *                     (ค่าที่กรอกลงช่อง = label เสมอ; ถ้าต้องการเก็บ value
 *                      แยก ให้ดักฟัง event 'ti:select' — ดูหมายเหตุด้านล่าง)
 *   $ti_maxlength   = จำนวนอักขระสูงสุด (optional)
 *   $ti_step/$ti_min/$ti_max = สำหรับ $ti_type='number' (optional)
 *                     เช่น $ti_step='0.0001', $ti_min=0
 *   $ti_style       = inline style เสริมของ input (optional)
 *   $ti_class       = class เสริมของ input (optional) เช่น 'form-control-dark'
 *                     เพื่อสืบทอดสไตล์เฉพาะหน้า
 *   $ti_wrap_class  = class ของกล่องนอก (.ti-component) — ใช้คุมความกว้าง/สูง (optional)
 *   $ti_wrap_style  = inline style ของกล่องนอก เช่น 'width:320px' (optional)
 *   $ti_bg          = สีพื้นหลัง input (optional) เช่น '#FCFBFD' — ตั้งตัวแปร --ti-bg
 *   $ti_bg_focus    = สีพื้นหลังตอน focus (optional) — ตั้งตัวแปร --ti-bg-focus
 *   $ti_autofocus   = true/false                         (default false)
 *
 * การกำหนดสีพื้นหลัง มี 2 ทาง:
 *   1) ส่งสีตรง ๆ (เร็ว):   $ti_bg = '#FCFBFD';
 *   2) ส่งผ่าน "class สี" (reuse): ให้ class ตั้งค่าตัวแปร --ti-bg แล้วส่งทาง $ti_wrap_class
 *      <style> .bg-soft { --ti-bg:#FCFBFD; --ti-bg-focus:#fff; } </style>
 *      <?php $ti_wrap_class = 'bg-soft'; include ...; ?>
 *   ทั้งสองทางคุม background ครบทั้ง ปกติ/focus/autofill โดยไม่ต้องสู้ specificity
 *
 * การกำหนดความกว้าง/ความสูงของกล่อง (แนะนำใช้ $ti_wrap_class):
 *   ความกว้าง -> ใส่ที่กล่องนอก, ความสูง -> ใส่ที่ .ti-input ข้างใน
 *   เขียน CSS scope ใต้ class ที่ส่งเข้ามา จะชนะสไตล์ base ของ component เสมอ:
 *     <style>
 *       .ti-narrow            { width: 320px; }      // ความกว้าง
 *       .ti-narrow .ti-input  { height: 44px; }      // ความสูง
 *     </style>
 *     <?php $ti_wrap_class = 'ti-narrow'; include ...; ?>
 *   หรือแบบเร็ว ๆ ผ่าน inline: $ti_wrap_style = 'width:320px; max-width:100%;'
 *
 * ตัวอย่าง:
 *   <?php
 *     $ti_id = 'scopeName'; $ti_name = 'name_tiem';
 *     $ti_label = 'ชื่อประเภทการปล่อยก๊าซ';
 *     $ti_placeholder = 'เช่น ไฟฟ้า, น้ำมัน';
 *     $ti_suggestions = ['ดีเซล (Diesel)', 'ไฟฟ้า', 'แก๊สโซฮอล์'];
 *     include __DIR__ . '/../components/text_input.php';
 *   ?>
 *
 * Events (dispatch จาก element #<$ti_id>):
 *   - 'ti:input'  {value}          — ทุกครั้งที่พิมพ์
 *   - 'ti:select' {value, label}   — เมื่อเลือกจากคำแนะนำ (value = value ของ option
 *                                     ถ้าใช้รูปแบบ label/value, ไม่งั้น = label)
 *
 * หมายเหตุ: component นี้ self-contained (มี CSS + JS ของตัวเอง ผูกกับ $ti_id)
 * จึงวางหลายช่องในหน้าเดียวกันได้โดยไม่ชนกัน
 */

// ── Normalize input ───────────────────────────────
$ti_id          = $ti_id ?? ('ti_' . uniqid());
$ti_name        = $ti_name ?? $ti_id;
$ti_value       = $ti_value ?? '';
$ti_label       = $ti_label ?? '';
$ti_placeholder = $ti_placeholder ?? '';
$ti_type        = $ti_type ?? 'text';
$ti_required    = $ti_required ?? true;
$ti_disabled    = $ti_disabled ?? false;
$ti_suggestions = $ti_suggestions ?? [];
$ti_maxlength   = $ti_maxlength ?? null;
$ti_step        = $ti_step ?? null;   // สำหรับ type=number เช่น '0.0001'
$ti_min         = $ti_min  ?? null;   // สำหรับ type=number เช่น 0
$ti_max         = $ti_max  ?? null;   // สำหรับ type=number
$ti_style       = $ti_style ?? '';
$ti_class       = $ti_class ?? '';
$ti_wrap_class  = $ti_wrap_class ?? '';   // class ของกล่องนอก (.ti-component) — ใช้คุมความกว้าง/สูง
$ti_wrap_style  = $ti_wrap_style ?? '';   // inline style ของกล่องนอกโดยเฉพาะ (เช่น 'width:320px')
$ti_bg          = $ti_bg ?? '';           // สีพื้นหลัง input (ตั้ง --ti-bg) เช่น '#FCFBFD'
$ti_bg_focus    = $ti_bg_focus ?? '';     // สีพื้นหลังตอน focus (ตั้ง --ti-bg-focus)
$ti_autofocus   = $ti_autofocus ?? false;

// รวมสีพื้นหลังเป็น CSS variable บนกล่องนอก (ครอบทั้ง base/focus/autofill)
$ti_bg_vars  = ($ti_bg !== '' ? '--ti-bg:' . $ti_bg . ';' : '')
             . ($ti_bg_focus !== '' ? '--ti-bg-focus:' . $ti_bg_focus . ';' : '');
$ti_wrap_style = trim($ti_bg_vars . $ti_wrap_style);

// อนุญาตเฉพาะ type ที่ปลอดภัย/สมเหตุสมผลกับ component นี้
$ti_allowed_types = ['text', 'email', 'number', 'password', 'tel', 'search'];
if (!in_array($ti_type, $ti_allowed_types, true)) {
    $ti_type = 'text';
}

// แปลง suggestions ให้เป็นรูปแบบเดียวกันเสมอ: [['value'=>.., 'label'=>..], ...]
$ti_norm_suggestions = [];
foreach ($ti_suggestions as $key => $sug) {
    if (is_array($sug)) {
        $ti_norm_suggestions[] = [
            'value' => (string) ($sug['value'] ?? ($sug['label'] ?? $key)),
            'label' => (string) ($sug['label'] ?? ($sug['value'] ?? $key)),
        ];
    } else {
        $ti_norm_suggestions[] = ['value' => (string) $sug, 'label' => (string) $sug];
    }
}
$ti_has_suggestions = count($ti_norm_suggestions) > 0;
?>

<div class="ti-component <?= $ti_has_suggestions ? 'ti-has-suggestions' : '' ?> <?= htmlspecialchars($ti_wrap_class) ?>"
    id="<?= htmlspecialchars($ti_id) ?>"
    <?= $ti_wrap_style !== '' ? 'style="' . htmlspecialchars($ti_wrap_style) . '"' : '' ?>>

    <?php if ($ti_label !== ''): ?>
        <label class="ti-label" for="<?= htmlspecialchars($ti_id) ?>_input">
            <?= htmlspecialchars($ti_label) ?><?= $ti_required ? ' <span class="ti-req">*</span>' : '' ?>
        </label>
    <?php endif; ?>

    <div class="ti-field">
        <input type="<?= htmlspecialchars($ti_type) ?>"
            class="ti-input <?= htmlspecialchars($ti_class) ?>"
            id="<?= htmlspecialchars($ti_id) ?>_input"
            name="<?= htmlspecialchars($ti_name) ?>"
            value="<?= htmlspecialchars((string) $ti_value) ?>"
            placeholder="<?= htmlspecialchars($ti_placeholder) ?>"
            autocomplete="off"
            <?= $ti_maxlength !== null ? 'maxlength="' . (int) $ti_maxlength . '"' : '' ?>
            <?= $ti_step !== null ? 'step="' . htmlspecialchars((string) $ti_step) . '"' : '' ?>
            <?= $ti_min  !== null ? 'min="'  . htmlspecialchars((string) $ti_min)  . '"' : '' ?>
            <?= $ti_max  !== null ? 'max="'  . htmlspecialchars((string) $ti_max)  . '"' : '' ?>
            <?= $ti_required ? 'required' : '' ?>
            <?= $ti_disabled ? 'disabled' : '' ?>
            <?= $ti_autofocus ? 'autofocus' : '' ?>
            <?= $ti_has_suggestions ? 'role="combobox" aria-autocomplete="list" aria-expanded="false"' : '' ?>
            <?= $ti_style !== '' ? 'style="' . htmlspecialchars($ti_style) . '"' : '' ?>>

        <?php if ($ti_has_suggestions): ?>
            <div class="ti-menu" id="<?= htmlspecialchars($ti_id) ?>_menu" role="listbox">
                <?php foreach ($ti_norm_suggestions as $s): ?>
                    <div class="ti-option"
                        data-value="<?= htmlspecialchars($s['value']) ?>"
                        data-label="<?= htmlspecialchars($s['label']) ?>"
                        role="option">
                        <?= htmlspecialchars($s['label']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// ── ล้างตัวแปรต่อ instance ── กัน option ค้างไปยัง include ครั้งถัดไปในหน้าเดียวกัน
// (ผู้เรียกกำหนดใหม่เฉพาะตัวที่ต้องการ ตัวที่ไม่กำหนดจะกลับเป็น default เสมอ)
unset(
    $ti_id, $ti_name, $ti_value, $ti_label, $ti_placeholder, $ti_type,
    $ti_required, $ti_disabled, $ti_suggestions, $ti_maxlength,
    $ti_step, $ti_min, $ti_max, $ti_style,
    $ti_class, $ti_wrap_class, $ti_wrap_style, $ti_bg, $ti_bg_focus, $ti_bg_vars, $ti_autofocus,
    $ti_allowed_types, $ti_norm_suggestions, $ti_has_suggestions
);
?>

<?php if (!defined('TI_COMPONENT_ASSETS_LOADED')): ?>
    <?php define('TI_COMPONENT_ASSETS_LOADED', true); ?>
    <style>
        /* ── ตัวแปรสีหลัก: ใช้ของหน้าเว็บถ้ามี ไม่งั้น fallback เป็นสีม่วงแบรนด์ ── */
        /* ประกาศตัวแปรทั้งบนกล่องนอกและตัว input เอง เผื่อใช้ .ti-input แบบเดี่ยว (ไม่มี .ti-component ห่อ) */
        .ti-component, .ti-input {
            --ti-primary: var(--clr-primary, #62368B);
            --ti-border: var(--border, #E5E7EB);
        }
        .ti-component {
            position: relative;
            /* ไม่ล็อก width ไว้ที่นี่ (div เป็น block กว้างเต็ม parent อยู่แล้ว)
               เพื่อให้ $ti_wrap_class / $ti_wrap_style กำหนดความกว้างทับได้โดยไม่ติด specificity */
        }

        .ti-label {
            display: block;
            margin-bottom: 0.5rem;
            font-family: 'Kanit', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-primary, #374151);
        }

        .ti-label .ti-req { color: var(--clr-danger, #EF4444); }

        .ti-field { position: relative; }

        /* ── ช่องกรอก: ทรง/เอฟเฟกต์เดียวกับ .form-control-dark ──
           (ถ้าหน้าไหนส่ง $ti_class='form-control-dark' มา สไตล์หน้านั้นจะทับให้เอง) */
        .ti-input {
            width: 100%;
            height: 58px;
            background: var(--ti-bg, #F9FAFB);
            border: 1px solid var(--ti-border);
            border-radius: 18px;
            padding: 0 1.5rem;
            font-family: 'Kanit', sans-serif;
            font-size: 1.05rem;
            font-weight: 500;
            color: var(--text-primary, #374151);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-sizing: border-box;
        }

        .ti-input::placeholder { color: #9CA3AF; font-weight: 400; }

        .ti-input:focus {
            outline: none;
            border-color: var(--ti-primary);
            background: var(--ti-bg-focus, #fff);
            box-shadow: 0 0 0 1px var(--ti-primary), 0 0 0 4px rgba(98, 54, 139, 0.15);
            transform: translateY(-1px);
        }

        .ti-input:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* ── ล้างพื้นหลัง autofill ของเบราว์เซอร์ (โทนฟ้า/ม่วง) ให้ใช้สีเดียวกับ .ti-input ── */
        .ti-input:-webkit-autofill,
        .ti-input:-webkit-autofill:hover,
        .ti-input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 1000px var(--ti-bg, #F9FAFB) inset;
            -webkit-text-fill-color: var(--text-primary, #374151);
            transition: background-color 9999s ease-in-out 0s;
        }
        .ti-input:-webkit-autofill:focus { -webkit-box-shadow: 0 0 0 1000px var(--ti-bg-focus, #fff) inset; }

        /* ── เมนูคำแนะนำ (autocomplete) — เข้าชุดกับ .dd-menu ── */
        .ti-menu {
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
            max-height: 200px;
            overflow-y: auto;
        }

        .ti-menu.open { display: block; }

        .ti-menu::-webkit-scrollbar { width: 6px; }
        .ti-menu::-webkit-scrollbar-track { background: #F3F4F6; border-radius: 4px; }
        .ti-menu::-webkit-scrollbar-thumb { background: #D1D5DB; border-radius: 4px; }
        .ti-menu::-webkit-scrollbar-thumb:hover { background: #9CA3AF; }

        .ti-option {
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-family: 'Kanit', sans-serif;
            font-size: 0.95rem;
            color: #374151;
            transition: background 0.15s;
        }

        .ti-option:hover,
        .ti-option.ti-active {
            background: #EDE9FE;
            color: #6D28D9;
            font-weight: 600;
        }

        .ti-option.ti-hidden { display: none; }

        /* ── Dark mode (built-in) — สอดคล้องกับ dropdown component ── */
        html.dark-theme .ti-input {
            background-color: #1F2937;
            border-color: #374151;
            color: #F9FAFB;
        }
        html.dark-theme .ti-input:-webkit-autofill,
        html.dark-theme .ti-input:-webkit-autofill:hover,
        html.dark-theme .ti-input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 1000px #1F2937 inset;
            -webkit-text-fill-color: #F9FAFB;
        }
        html.dark-theme .ti-label { color: #F9FAFB; }
        html.dark-theme .ti-menu {
            background-color: #1F2937;
            border-color: #374151;
        }
        html.dark-theme .ti-option { color: #F9FAFB; }
        html.dark-theme .ti-option:hover,
        html.dark-theme .ti-option.ti-active {
            background-color: #374151;
            color: #fff;
        }
    </style>

    <script>
        // ── Custom Text Input: shared logic (โหลดครั้งเดียวต่อหน้า) ──
        (function () {
            function menuOf(input) {
                const field = input.closest('.ti-field');
                return field ? field.querySelector('.ti-menu') : null;
            }

            function closeMenu(input) {
                const menu = menuOf(input);
                if (menu) menu.classList.remove('open');
                input.setAttribute('aria-expanded', 'false');
                clearActive(menu);
            }

            function clearActive(menu) {
                if (!menu) return;
                menu.querySelectorAll('.ti-option.ti-active').forEach(o => o.classList.remove('ti-active'));
            }

            function visibleOptions(menu) {
                return Array.from(menu.querySelectorAll('.ti-option:not(.ti-hidden)'));
            }

            // กรองคำแนะนำตามข้อความที่พิมพ์ + เปิด/ปิดเมนู
            function filterMenu(input) {
                const menu = menuOf(input);
                if (!menu) return;
                const q = input.value.trim().toLowerCase();
                let shown = 0;
                menu.querySelectorAll('.ti-option').forEach(opt => {
                    const label = (opt.dataset.label || opt.textContent).toLowerCase();
                    const match = q === '' || label.indexOf(q) !== -1;
                    opt.classList.toggle('ti-hidden', !match);
                    if (match) shown++;
                });
                clearActive(menu);
                if (shown > 0) {
                    menu.classList.add('open');
                    input.setAttribute('aria-expanded', 'true');
                } else {
                    closeMenu(input);
                }
            }

            function selectOption(input, opt) {
                input.value = opt.dataset.label;
                closeMenu(input);
                const comp = input.closest('.ti-component');
                comp?.dispatchEvent(new CustomEvent('ti:select', {
                    detail: { value: opt.dataset.value, label: opt.dataset.label }
                }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function moveActive(menu, dir) {
                const opts = visibleOptions(menu);
                if (!opts.length) return;
                let idx = opts.findIndex(o => o.classList.contains('ti-active'));
                clearActive(menu);
                idx = (idx + dir + opts.length) % opts.length;
                const cur = opts[idx];
                cur.classList.add('ti-active');
                cur.scrollIntoView({ block: 'nearest' });
            }

            // ── delegated events (รองรับ input ที่เพิ่มมาทีหลัง เช่นใน modal) ──
            document.addEventListener('input', function (e) {
                const input = e.target.closest('.ti-component.ti-has-suggestions .ti-input');
                if (!input) return;
                filterMenu(input);
                input.closest('.ti-component')
                    ?.dispatchEvent(new CustomEvent('ti:input', { detail: { value: input.value } }));
            });

            document.addEventListener('focusin', function (e) {
                const input = e.target.closest('.ti-component.ti-has-suggestions .ti-input');
                if (input) filterMenu(input);
            });

            document.addEventListener('keydown', function (e) {
                const input = e.target.closest('.ti-component.ti-has-suggestions .ti-input');
                if (!input) return;
                const menu = menuOf(input);
                if (!menu) return;
                const open = menu.classList.contains('open');

                if (e.key === 'ArrowDown') {
                    if (!open) { filterMenu(input); } else { moveActive(menu, 1); }
                    e.preventDefault();
                } else if (e.key === 'ArrowUp') {
                    if (open) { moveActive(menu, -1); e.preventDefault(); }
                } else if (e.key === 'Enter') {
                    const active = menu.querySelector('.ti-option.ti-active');
                    if (open && active) { selectOption(input, active); e.preventDefault(); }
                } else if (e.key === 'Escape') {
                    if (open) { closeMenu(input); e.preventDefault(); }
                }
            });

            // คลิกเลือกจากเมนู (mousedown กันไม่ให้ blur ปิดเมนูก่อน)
            document.addEventListener('mousedown', function (e) {
                const opt = e.target.closest('.ti-component.ti-has-suggestions .ti-option');
                if (!opt) return;
                const input = opt.closest('.ti-field').querySelector('.ti-input');
                selectOption(input, opt);
                e.preventDefault();
            });

            // ปิดเมนูเมื่อคลิกนอกกรอบ
            document.addEventListener('click', function (e) {
                document.querySelectorAll('.ti-component.ti-has-suggestions').forEach(comp => {
                    if (!comp.contains(e.target)) {
                        const input = comp.querySelector('.ti-input');
                        if (input) closeMenu(input);
                    }
                });
            });
        })();
    </script>
<?php endif; ?>
