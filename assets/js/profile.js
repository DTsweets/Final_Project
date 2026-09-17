/**
 * หน้าโปรไฟล์ (includes/profile_page.php)
 * - ตรวจข้อมูลตอนพิมพ์ (กฎเดียวกับ profile_validate ใน includes/profile_entry.php — ยกเว้นการเทียบรหัสเดิม ทำฝั่งเซิร์ฟเวอร์)
 * - รูปโปรไฟล์: เลือก/ลากมาวาง → ย่อเหลือ 400px แปลงเป็น WebP → ดูตัวอย่าง / ยกเลิกรูปที่เลือก
 * - เตือนก่อนออกจากหน้าเมื่อยังไม่บันทึก (ใช้ตัวดักเดียวกับ data-entry.js ผ่าน window.__deForms)
 * ฟังก์ชันตรวจค่าแยกจาก DOM → ทดสอบด้วย node ได้ (tests/profile_validate_test.js)
 */
(function (w) {
    var PW_MAX = 50, PW_MIN = 8, IMG_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], IMG_RAW_MAX = 15 * 1024 * 1024;

    /** @return { ชื่อช่อง: ข้อความ } */
    function validate(v) {
        var e = {}, t = function (x) { return String(x == null ? '' : x).trim(); };
        var fn = t(v.firstname), ln = t(v.lastname), un = t(v.username), em = t(v.email);
        var old = String(v.old_password || ''), pw = String(v.password || ''), cf = String(v.password_confirm || '');
        if (!fn) e.firstname = 'กรุณากรอกชื่อจริง'; else if (fn.length > 100) e.firstname = 'ชื่อจริงยาวเกิน 100 ตัวอักษร';
        if (!ln) e.lastname = 'กรุณากรอกนามสกุล'; else if (ln.length > 100) e.lastname = 'นามสกุลยาวเกิน 100 ตัวอักษร';
        if (!un) e.username = 'กรุณากรอกชื่อผู้ใช้งาน';
        else if (un.length > 50) e.username = 'ชื่อผู้ใช้งานยาวเกิน 50 ตัวอักษร';
        else if (/\s/.test(un)) e.username = 'ชื่อผู้ใช้งานต้องไม่มีช่องว่าง';
        if (em && (em.length > 255 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em))) e.email = 'รูปแบบอีเมลไม่ถูกต้อง';
        if (pw || cf) {
            if (!old) e.old_password = 'กรุณากรอกรหัสผ่านเดิม';
            if (!pw) e.password = 'กรุณากรอกรหัสผ่านใหม่'; else if (pw.length > PW_MAX) e.password = 'รหัสผ่านยาวเกิน ' + PW_MAX + ' ตัวอักษร';
            else if (pw.length < PW_MIN) e.password = 'รหัสผ่านต้องมีอย่างน้อย ' + PW_MIN + ' ตัวอักษร';
            if (cf !== pw) e.password_confirm = 'รหัสผ่านยืนยันไม่ตรงกัน';
        }
        return e;
    }
    /** ไฟล์รูปก่อนแปลง: ชนิดที่รองรับ และไม่ใหญ่ผิดปกติ */
    function checkImage(type, size) {
        if (IMG_TYPES.indexOf(type) < 0) return 'ไฟล์ไม่ใช่รูปภาพที่รองรับ (JPG, PNG, GIF, WEBP)';
        if (size > IMG_RAW_MAX) return 'ไฟล์รูปใหญ่เกินไป';
        return null;
    }
    w.pfValidate = validate;
    w.pfCheckImage = checkImage;

    w.pfInit = function (form) {
        if (!form || form.__pfBound) return;
        form.__pfBound = true;
        var d = form.ownerDocument;
        var q = function (s) { return form.querySelector(s); };
        var FIELDS = ['firstname', 'lastname', 'username', 'email', 'old_password', 'password', 'password_confirm'];
        var input = function (n) { return form.elements[n]; };
        var initial = {}; FIELDS.forEach(function (n) { initial[n] = input(n).value; });
        var touched = {};
        var state = { dirty: false, submitting: false };
        form.__deState = state;
        w.__deForms = w.__deForms || [];
        w.__deForms.push(form);

        var file = q('#pfFile'), preview = q('#pfPreview'), drop = q('#pfDrop'), fileName = q('#pfFileName'), clearBtn = q('#pfClearImg');
        var origSrc = preview.dataset.src || '';
        var imgChosen = false;

        function values() { var v = {}; FIELDS.forEach(function (n) { v[n] = input(n).value; }); return v; }
        function showErrors(errs, all) {
            FIELDS.forEach(function (n) {
                var el = input(n), box = el.closest('.pf-field'), msg = box.querySelector('.pf-err');
                var show = errs[n] && (all || touched[n]);
                // ข้อความจากเซิร์ฟเวอร์ (เช่น รหัสเดิมไม่ถูก / ชื่อซ้ำ) คงไว้จนกว่าจะแก้ช่องนั้น
                if (!show && box.dataset.server && !touched[n]) return;
                box.classList.toggle('is-invalid', !!show);
                el.setAttribute('aria-invalid', show ? 'true' : 'false');
                msg.textContent = show ? errs[n] : '';
            });
        }
        function refresh() {
            var v = values();
            state.dirty = imgChosen || FIELDS.some(function (n) { return v[n] !== initial[n]; });
            q('[data-pf-dirty]').hidden = !state.dirty;
            // แถบความยาวรหัสใหม่ + สถานะยืนยันตรงกัน
            var len = v.password.length, meter = q('.pf-meter-bar');
            meter.style.width = Math.min(100, len / PW_MAX * 100) + '%';
            meter.classList.toggle('is-over', len > PW_MAX);
            q('[data-pf-len]').textContent = len + ' / ' + PW_MAX;
            var match = q('[data-pf-match]');
            match.hidden = !v.password_confirm;
            match.classList.toggle('is-ok', !!v.password_confirm && v.password_confirm === v.password);
            match.textContent = v.password_confirm === v.password ? '✓ ตรงกัน' : '✕ ไม่ตรงกัน';
            showErrors(validate(v), false);
        }
        FIELDS.forEach(function (n) {
            var el = input(n);
            el.addEventListener('input', function () {
                touched[n] = true;
                var box = el.closest('.pf-field'); if (box.dataset.server) { delete box.dataset.server; }
                refresh();
            });
            el.addEventListener('blur', function () { if (el.value !== initial[n]) { touched[n] = true; refresh(); } });
        });

        // ── แสดง/ซ่อนรหัสผ่าน ──
        form.querySelectorAll('.pf-eye').forEach(function (b) {
            b.addEventListener('click', function () {
                var el = b.parentNode.querySelector('input'), show = el.type === 'password';
                el.type = show ? 'text' : 'password';
                b.setAttribute('aria-pressed', show ? 'true' : 'false');
                b.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
            });
        });
        // ── พับ/กาง ส่วนเปลี่ยนรหัสผ่าน ──
        var pw = q('.pf-pw'), pwToggle = q('.pf-pw-toggle');
        function openPw(open) { pw.classList.toggle('is-open', open); pwToggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        pwToggle.addEventListener('click', function () {
            var open = !pw.classList.contains('is-open'); openPw(open);
            if (open) setTimeout(function () { input('old_password').focus(); }, 80);
        });

        // ── รูปโปรไฟล์ ──
        function setPreview(src) {
            preview.classList.toggle('has-img', !!src);
            var img = preview.querySelector('img');
            img.src = src || '';
            preview.classList.remove('pf-pop'); void preview.offsetWidth; preview.classList.add('pf-pop');
        }
        function imgError(text) {
            var box = q('.pf-avatar-err'); box.textContent = text || '';
            if (text) { drop.classList.remove('oe-shake'); void drop.offsetWidth; drop.classList.add('oe-shake'); }
        }
        function takeFile(f) {
            if (!f) return;
            var err = checkImage(f.type, f.size);
            if (err) { imgError(err); return; }
            imgError('');
            fileName.textContent = 'กำลังย่อรูปและแปลงเป็น WebP…';
            var reader = new FileReader();
            reader.onload = function (ev) {
                var img = new Image();
                img.onload = function () {
                    var W = img.width, H = img.height, MAX = 400;
                    if (W > H && W > MAX) { H *= MAX / W; W = MAX; } else if (H > MAX) { W *= MAX / H; H = MAX; }
                    var c = d.createElement('canvas'); c.width = W; c.height = H;
                    c.getContext('2d').drawImage(img, 0, 0, W, H);
                    setPreview(c.toDataURL('image/webp', 0.85));
                    c.toBlob(function (blob) {
                        var name = f.name.replace(/\.[^/.]+$/, '') + '.webp';
                        var dt = new DataTransfer();
                        dt.items.add(new File([blob], name, { type: blob.type || 'image/webp', lastModified: Date.now() }));
                        file.files = dt.files;
                        fileName.textContent = name + ' · ' + Math.max(1, Math.round(blob.size / 1024)) + ' KB';
                        imgChosen = true; clearBtn.hidden = false; drop.classList.add('is-chosen'); refresh();
                    }, 'image/webp', 0.85);
                };
                img.onerror = function () { imgError('อ่านไฟล์รูปไม่ได้'); fileName.textContent = ''; };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(f);
        }
        function clearImage() {
            file.value = ''; imgChosen = false; clearBtn.hidden = true; drop.classList.remove('is-chosen');
            fileName.textContent = ''; imgError(''); setPreview(origSrc); refresh();
        }
        file.addEventListener('change', function () { takeFile(file.files[0]); });
        clearBtn.addEventListener('click', clearImage);
        ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-drag'); }); });
        ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-drag'); }); });
        drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) takeFile(e.dataTransfer.files[0]); });

        // ── ยกเลิกการแก้ไขทั้งหมด ──
        q('[data-pf-reset]').addEventListener('click', function () {
            FIELDS.forEach(function (n) { input(n).value = initial[n]; touched[n] = false; });
            form.querySelectorAll('.pf-field').forEach(function (b) { delete b.dataset.server; });
            showErrors({}, true); q('[data-pf-msg]').textContent = '';
            clearImage();
        });

        // ── บันทึก: ตรวจก่อนส่ง ──
        form.addEventListener('submit', function (e) {
            var errs = validate(values()), keys = Object.keys(errs);
            if (keys.length) {
                e.preventDefault();
                FIELDS.forEach(function (n) { if (errs[n]) touched[n] = true; });
                showErrors(errs, true);
                if (errs.old_password || errs.password || errs.password_confirm) openPw(true);
                q('[data-pf-msg]').textContent = 'มีช่องที่ต้องแก้ไข ' + keys.length + ' ช่อง';
                var dock = q('.oe-dock'); dock.classList.remove('oe-shake'); void dock.offsetWidth; dock.classList.add('oe-shake');
                var first = input(keys[0]); first.focus(); first.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }
            state.submitting = true;
            var btn = q('[data-pf-save]'); btn.disabled = true; btn.querySelector('span').textContent = 'กำลังบันทึก…';
        });

        if (pw.dataset.open === '1') openPw(true);
        refresh();
        return state;
    };
})(window);
