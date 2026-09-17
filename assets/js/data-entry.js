/**
 * ตารางกรอกปริมาณ — ใช้ร่วม: officer/data_entry_items.php และ includes/collect_page.php (แบบสอบถาม & กิจกรรม)
 * - คำนวณ tCO₂e รายแถว / รายหมวด / รวม ทันทีที่พิมพ์
 *   แถวหนึ่งมีได้หลายช่อง (แบบสอบถาม: จำนวนผู้ตอบ × ค่าเฉลี่ย) → ค่าของแถว = ผลคูณทุกช่อง
 * - ตรวจค่าที่กรอก (ตัวเลข, ไม่ติดลบ, ไม่เกิน 1,000,000, จำนวนเต็มถ้า data-int="1", กรอกครบทุกช่องของแถว, ผลคูณของแถวไม่เกิน 999,999,999.9999)
 * - จำว่ามีการแก้ไขที่ยังไม่บันทึก → เตือนก่อนออกจากหน้า (ปิดแท็บ/รีโหลด, เมนูด้านข้างแบบ SPA, ลิงก์ a[data-de-guard])
 * - หลายฟอร์มในหน้าเดียวได้: ตัวเลขสรุปอ่านใน [data-de-scope] ที่ครอบฟอร์ม (ไม่มี = ทั้งหน้า)
 * ฟังก์ชันคำนวณแยกจาก DOM → ทดสอบด้วย node ได้ (tests/data_entry_calc_test.js)
 */
(function (w) {
    var MAX = 1000000;
    var PRODUCT_MAX = 999999999.9999;   // ผู้ตอบ × ค่าเฉลี่ย: ความจุ user_item.Vol decimal(13,4) (ตรงกับ COLLECT_SURVEY_VOL_MAX)

    /** ค่าที่กรอก → { value, error } — ว่าง = 0 (ยังไม่กรอก) ไม่ถือว่าผิด · opts.int = ต้องเป็นจำนวนเต็ม */
    function parseVol(raw, opts) {
        var s = String(raw == null ? '' : raw).trim().replace(/,/g, '');
        if (s === '') return { value: 0, error: null };
        var v = Number(s);
        if (!isFinite(v)) return { value: 0, error: 'กรุณากรอกเป็นตัวเลข' };
        if (v < 0) return { value: 0, error: 'ปริมาณต้องไม่ติดลบ' };
        if (v > MAX) return { value: v, error: 'ปริมาณต้องไม่เกิน 1,000,000' };
        if (opts && opts.int && v % 1 !== 0) return { value: v, error: 'กรุณากรอกเป็นจำนวนเต็ม' };
        return { value: v, error: null };
    }

    /** tCO₂e ของแถว = ปริมาณ × EF (kgCO₂e/หน่วย) ÷ 1000 */
    function rowEmission(vol, ef) {
        var e = Number(vol) * Number(ef) / 1000;
        return isFinite(e) ? e : 0;
    }

    /**
     * ค่าของแถว: r.raws = [{ raw, int }] (หลายช่อง) หรือ r.raw (ช่องเดียว)
     * หลายช่อง: ค่า = ผลคูณ, กรอกบางช่องแต่ไม่ครบ = ผิด (เซิร์ฟเวอร์ไม่บันทึกแถวที่ไม่ครบ ข้อมูลจะหายเงียบ ๆ)
     * @return { value, error }
     */
    function rowValue(r) {
        var parts = r.raws || [{ raw: r.raw }];
        var value = 1, error = null, filled = 0;
        parts.forEach(function (p) {
            var x = parseVol(p.raw, p);
            if (x.error && !error) error = x.error;
            if (x.value > 0) filled++;
            value *= x.value;
        });
        if (!error && parts.length > 1 && filled > 0 && filled < parts.length) error = 'กรุณากรอกให้ครบทุกช่องของแถวนี้';
        var over = !error && parts.length > 1 && value > PRODUCT_MAX;
        if (over) error = 'ผู้ตอบ × ค่าเฉลี่ย เกิน 999,999,999';
        return { value: error ? 0 : value, error: error, over: over };
    }

    /** ตัวเลข tCO₂e สำหรับแสดง — 0 แสดง "-" (กฎเดียวกับหน้าต่างรายละเอียดบน Dashboard)
     *  ยอดรวม/รายหมวดทศนิยม 2 ตำแหน่ง (ค่าเริ่มต้น ตรงกับที่ PHP แสดงตอนโหลด) · รายแถวส่ง 4 */
    function fmt(n, digits) {
        if (!isFinite(n) || n === 0) return '-';
        var d = digits == null ? 2 : digits;
        return n.toLocaleString('th-TH', { minimumFractionDigits: d, maximumFractionDigits: d });
    }

    /**
     * สรุปทุกแถว: rows = [{ group, ef, raw | raws }]
     * @return { total, filled, count, errors, groups: { [group]: { total, filled, count } } }
     */
    function summarize(rows) {
        var out = { total: 0, filled: 0, count: 0, errors: 0, groups: {} };
        rows.forEach(function (r) {
            var p = rowValue(r);
            var g = out.groups[r.group] || (out.groups[r.group] = { total: 0, filled: 0, count: 0 });
            var e = p.error ? 0 : rowEmission(p.value, r.ef);
            out.count++; g.count++;
            if (p.error) out.errors++;
            if (!p.error && p.value > 0) { out.filled++; g.filled++; }
            out.total += e; g.total += e;
        });
        return out;
    }

    w.deParseVol = parseVol;
    w.deRowEmission = rowEmission;
    w.deRowValue = rowValue;
    w.deFormat = fmt;
    w.deSummarize = summarize;

    // ฟอร์มที่ผูกแล้วทั้งหมด (เก็บบน window: SPA รันไฟล์นี้ซ้ำ แต่ตัวดักเตือนก่อนออกผูกไว้ครั้งเดียว)
    w.__deForms = w.__deForms || [];

    /** ผูกฟอร์ม — เรียกซ้ำได้ (SPA) ตัวฟอร์มจำว่าผูกแล้ว */
    w.deInit = function (form) {
        if (!form || form.__deBound) return;
        form.__deBound = true;
        var doc = form.ownerDocument;
        var scope = form.closest('[data-de-scope]') || doc;
        var digits = scope.dataset && scope.dataset.deDigits ? Number(scope.dataset.deDigits) : 2;
        var rowsEl = Array.prototype.filter.call(form.querySelectorAll('.item-row'), function (tr) { return tr.querySelector('input.vol-input'); });
        var inputs = Array.prototype.slice.call(form.querySelectorAll('input.vol-input'));
        var initial = inputs.map(function (i) { return i.value; });
        var state = { dirty: false, submitting: false };
        form.__deState = state;
        w.__deForms.push(form);

        function all(sel) { return Array.prototype.slice.call(scope.querySelectorAll(sel)); }
        function setText(sel, text) { all(sel).forEach(function (el) { el.textContent = text; }); }
        function rowOf(tr) {
            var ins = Array.prototype.slice.call(tr.querySelectorAll('input.vol-input'));
            return { tr: tr, ins: ins, group: ins[0].dataset.group, ef: Number(ins[0].dataset.ef),
                     raws: ins.map(function (i) { return { raw: i.value, int: i.dataset.int === '1' }; }) };
        }

        function refresh() {
            var rows = rowsEl.map(rowOf);
            var sum = summarize(rows);
            rows.forEach(function (r) {
                var p = rowValue(r);
                r.tr.classList.toggle('is-filled', !p.error && p.value > 0);
                r.tr.classList.toggle('is-invalid', !!p.error);
                var out = r.tr.querySelector('.total-pill');
                if (out) out.textContent = p.error ? '-' : fmt(rowEmission(p.value, r.ef), 4);
                var err = r.tr.querySelector('.de-err');
                if (err) err.textContent = p.error || '';
                // ช่องที่ผิดเอง, ช่องที่ยังว่างในแถวที่กรอกไม่ครบ หรือทุกช่องของแถวที่ผลคูณเกิน (ปุ่มบันทึกเลื่อนไปช่องแรกที่ผิดได้)
                r.ins.forEach(function (i, k) {
                    var own = parseVol(i.value, r.raws[k]).error;
                    var bad = !!own || p.over || (!!p.error && String(i.value).trim() === '');
                    i.setAttribute('aria-invalid', bad ? 'true' : 'false');
                });
            });
            setText('[data-de-total]', fmt(sum.total, digits));
            setText('[data-de-filled]', sum.filled + ' / ' + sum.count);
            Object.keys(sum.groups).forEach(function (g) {
                setText('[data-de-group-total="' + g + '"]', fmt(sum.groups[g].total, digits));
                setText('[data-de-group-filled="' + g + '"]', sum.groups[g].filled + ' / ' + sum.groups[g].count);
            });
            state.dirty = inputs.some(function (i, k) { return i.value !== initial[k]; });
            all('[data-de-dirty]').forEach(function (el) { el.hidden = !state.dirty; });
            // ให้หน้าที่ใช้ต่อยอด (เช่น แถบสัดส่วน / การ์ดสรุป) อัปเดตตามได้
            if (typeof w.CustomEvent === 'function') form.dispatchEvent(new w.CustomEvent('de:refresh', { detail: sum }));
            return sum;
        }

        inputs.forEach(function (i) { i.addEventListener('input', refresh); });

        /** บันทึก: มีค่าผิด → เลื่อนไปช่องแรกที่ผิด / ไม่มี → ยืนยันแล้วส่งฟอร์ม */
        form.__deSave = function () {
            var sum = refresh();
            var msg = scope.querySelector('[data-de-msg]');
            if (sum.errors > 0) {
                var bad = form.querySelector('input.vol-input[aria-invalid="true"]');
                var sec = bad && bad.closest('.accordion-section');
                if (sec && !sec.classList.contains('active')) sec.classList.add('active');
                if (bad) { bad.focus(); bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                if (msg) msg.textContent = 'มีช่องที่กรอกไม่ถูกต้อง ' + sum.errors + ' ช่อง';
                return;
            }
            if (msg) msg.textContent = '';
            var go = function () { state.submitting = true; form.submit(); };
            if (w.confirmDelete) {
                w.confirmDelete({ title: 'ยืนยันการบันทึก?', message: 'บันทึกปริมาณ ' + sum.filled + ' / ' + sum.count + ' รายการ รวม ' + fmt(sum.total, digits) + ' tCO₂e', confirmText: 'บันทึก', variant: 'success' })
                    .then(function (ok) { if (ok) go(); });
            } else { go(); }
        };
        w.__deLast = form;
        // กด Enter ในช่องกรอก = บันทึกผ่านขั้นตอนเดียวกัน (ไม่ส่งฟอร์มตรง ๆ โดยไม่ตรวจค่า)
        form.addEventListener('submit', function (e) { if (!state.submitting) { e.preventDefault(); form.__deSave(); } });

        refresh();
        return state;
    };

    /** ปุ่มบันทึก: deSave(this) → ฟอร์มใน [data-de-scope] ของปุ่ม · deSave() → ฟอร์มล่าสุดที่ผูก */
    w.deSave = function (el) {
        var sc = el && el.closest ? el.closest('[data-de-scope]') : null;
        var form = (el && el.form) || (sc && sc.querySelector('form')) || w.__deLast;
        if (form && form.__deSave) form.__deSave();
    };

    // เตือนก่อนออกจากหน้า — ผูกครั้งเดียว ตรวจเฉพาะฟอร์มที่ยังอยู่ในหน้า (SPA อาจสลับเนื้อหาไปแล้ว)
    if (!w.__deGuard && w.document) {
        w.__deGuard = true;
        var dirtyForms = function () {
            return w.__deForms.filter(function (f) { return f.ownerDocument.body.contains(f) && f.__deState.dirty && !f.__deState.submitting; });
        };
        w.addEventListener('beforeunload', function (e) { if (dirtyForms().length) { e.preventDefault(); e.returnValue = ''; } });
        w.document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('.sidebar-body a, a[data-de-guard]');
            var list = a ? dirtyForms() : [];
            if (!list.length) return;
            if (!w.confirm('มีการแก้ไขที่ยังไม่บันทึก ต้องการออกจากหน้านี้หรือไม่?')) { e.preventDefault(); e.stopImmediatePropagation(); }
            else { list.forEach(function (f) { f.__deState.submitting = true; }); }   // ยืนยันออกแล้ว ไม่ต้องถามซ้ำตอน beforeunload
        }, true);
    }
})(window);
