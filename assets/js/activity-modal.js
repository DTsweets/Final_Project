/**
 * activity-modal.js — โมดัลกิจกรรมของคณะ แบบ 2 ระดับ (list → detail)
 * ใช้ร่วม officer/dean · แสดงเฉพาะคณะตัวเอง (ข้อมูลกรองมาแล้วฝั่ง PHP)
 *   • การปล่อย  : window.EMISSION_ROWS → #emissionModal / #emissionModalBody / #emBreadcrumb
 *   • การดูดกลับ: window.REMOVAL_ROWS  → #removalModal  / #removalModalBody  / #rmBreadcrumb
 */
(function (w) {
    'use strict';
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
    function f4(n) { return (parseFloat(n) || 0).toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 }); }
    function qty(n) { return (parseFloat(n) || 0).toLocaleString('th-TH', { maximumFractionDigits: 4 }); }
    function dmy(d) {
        if (!d) return '-';
        // DB เก็บปีเป็น พ.ศ. อยู่แล้ว → ไม่ต้อง +543 (แค่จัดรูป DD/MM/YYYY)
        var p = String(d).slice(0, 10).split('-');
        return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : d;
    }
    // จัดกลุ่มตามกิจกรรม (event) + ยอดรวม tCO₂e ของกิจกรรม
    function groupRows(arr) {
        var g = {};
        (arr || []).forEach(function (a) {
            var k = a.event_id;
            if (!g[k]) g[k] = { eid: k, name: a.event_name || '-', date: a.event_date, end: a.event_end_date, items: [], sub: 0 };
            g[k].items.push(a);
            g[k].sub += parseFloat(a.emission) || 0;
        });
        return g;
    }
    // ค้นหาในตาราง (ซ่อนแถวที่ไม่ตรง) — ใช้ร่วมทุกโมดัล
    w.emFilter = function (inp, tbodyId) {
        var q = inp.value.trim().toLowerCase(), tb = document.getElementById(tbodyId);
        if (!tb) return;
        Array.from(tb.rows).forEach(function (r) { r.style.display = r.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none'; });
    };
    function setTitle(id, txt) { var t = document.getElementById(id); if (t) t.textContent = txt; }

    /* ═══════════ การปล่อย (สีม่วง) ═══════════ */
    w.emRenderList = function () {
        var evs = Object.values(groupRows(w.EMISSION_ROWS));
        var body = document.getElementById('emissionModalBody');
        if (!body) return;
        var bc = document.getElementById('emBreadcrumb'); if (bc) bc.style.display = 'none';
        setTitle('emModalTitle', 'การปล่อยจากกิจกรรมที่คณะจัด');
        if (!evs.length) { body.innerHTML = '<p style="color:#9CA3AF;text-align:center;padding:24px;">ยังไม่มีข้อมูล</p>'; return; }
        var rows = evs.map(function (ev) {
            var date = ev.end ? (dmy(ev.date) + ' - ' + dmy(ev.end)) : dmy(ev.date);
            return '<tr>'
                + '<td style="font-weight:600;"><div style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(ev.name) + '">' + esc(ev.name) + '</div></td>'
                + '<td style="text-align:center;color:#6B7280;white-space:nowrap;">' + date + '</td>'
                + '<td style="text-align:right;font-weight:700;color:var(--clr-primary);">' + f4(ev.sub) + '</td>'
                + '<td style="text-align:center;"><button class="btn-detail" onclick="emOpenDetail(' + ev.eid + ')">ดูรายละเอียด</button></td>'
                + '</tr>';
        }).join('');
        body.innerHTML = '<p class="muted" style="margin:0 0 12px;color:#8A8194;">คลิกที่กิจกรรมเพื่อดูรายละเอียด</p>'
            + '<div style="margin-bottom:12px;"><input type="text" class="modal-search-input" placeholder="ค้นหากิจกรรม..." oninput="emFilter(this,\'em-l1\')"></div>'
            + '<table class="data-table" style="width:100%;table-layout:fixed;"><thead><tr>'
            + '<th>กิจกรรม</th><th style="text-align:center;width:7.5rem;">วันที่</th>'
            + '<th style="text-align:right;width:8rem;">Emission<br><span style="white-space:nowrap;">(tCO₂e/ต่อปี)</span></th><th style="text-align:center;width:8.5rem;">ดูรายละเอียด</th>'
            + '</tr></thead><tbody id="em-l1">' + rows + '</tbody></table>';
    };
    w.emOpenDetail = function (eid) {
        var g = groupRows(w.EMISSION_ROWS)[eid];
        var body = document.getElementById('emissionModalBody');
        if (!g || !body) return;
        var bc = document.getElementById('emBreadcrumb'); if (bc) bc.style.display = 'block';
        setTitle('emModalTitle', g.name);   // ชื่อกิจกรรมขึ้นเป็นหัวโมดัล
        var rows = g.items.map(function (r) {
            var scope = (r.scope == null)
                ? '<span style="color:var(--text-muted);">-</span>'
                : '<span style="font-size:.72rem;font-weight:700;border-radius:999px;padding:2px 10px;background:#EFF6FF;color:#2563EB;white-space:nowrap;">Scope ' + r.scope + '</span>';
            return '<tr>'
                + '<td style="font-weight:600;"><div style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + esc(r.name_tiem) + '">' + esc(r.name_tiem) + '</div></td>'
                + '<td style="text-align:center;"><span style="font-size:.72rem;font-weight:700;color:#92400E;background:#FEF3C7;border-radius:999px;padding:2px 10px;white-space:nowrap;">ปล่อย</span></td>'
                + '<td style="text-align:center;">' + scope + '</td>'
                + '<td style="text-align:center;color:#6B7280;">' + esc(r.unit || '-') + '</td>'
                + '<td style="text-align:center;font-weight:700;">' + qty(r.qty) + '</td>'
                + '<td style="text-align:center;font-weight:700;color:var(--clr-primary);">' + f4(r.emission) + '</td>'
                + '</tr>';
        }).join('');
        body.innerHTML = '<div style="margin-bottom:12px;"><input type="text" class="modal-search-input" placeholder="ค้นหารายการ..." oninput="emFilter(this,\'em-l2\')"></div>'
            + '<table class="data-table" style="width:100%;table-layout:fixed;"><thead><tr>'
            + '<th>รายการ</th><th style="text-align:center;width:6rem;">ประเภท</th><th style="text-align:center;width:6rem;">Scope</th>'
            + '<th style="text-align:center;width:5rem;">หน่วย</th><th style="text-align:center;width:6rem;">จำนวน</th><th style="text-align:center;width:7rem;">tCO₂e</th>'
            + '</tr></thead><tbody id="em-l2">' + rows + '</tbody></table>';
    };
    w.openEmissionModal = function () {
        w.emRenderList();
        var m = document.getElementById('emissionModal');
        if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
    };
    w.closeEmissionModal = function () {
        var m = document.getElementById('emissionModal');
        if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
    };

    /* ═══════════ การดูดกลับ (สีเขียว) ═══════════ */
    w.rmRenderList = function () {
        var evs = Object.values(groupRows(w.REMOVAL_ROWS));
        var body = document.getElementById('removalModalBody');
        if (!body) return;
        var bc = document.getElementById('rmBreadcrumb'); if (bc) bc.style.display = 'none';
        setTitle('rmModalTitle', 'การดูดกลับจากกิจกรรมที่คณะจัด');
        if (!evs.length) { body.innerHTML = '<p style="color:#9CA3AF;text-align:center;padding:24px;">ยังไม่มีข้อมูล</p>'; return; }
        var rows = evs.map(function (ev) {
            var date = ev.end ? (dmy(ev.date) + ' - ' + dmy(ev.end)) : dmy(ev.date);
            return '<tr>'
                + '<td style="font-weight:600;"><div style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(ev.name) + '">' + esc(ev.name) + '</div></td>'
                + '<td style="text-align:center;color:#6B7280;white-space:nowrap;">' + date + '</td>'
                + '<td style="text-align:right;font-weight:700;color:#166534;">' + f4(ev.sub) + '</td>'
                + '<td style="text-align:center;"><button class="btn-detail" onclick="rmOpenDetail(' + ev.eid + ')">ดูรายละเอียด</button></td>'
                + '</tr>';
        }).join('');
        body.innerHTML = '<p class="muted" style="margin:0 0 12px;color:#8A8194;">คลิกที่กิจกรรมเพื่อดูรายละเอียด</p>'
            + '<div style="margin-bottom:12px;"><input type="text" class="modal-search-input" placeholder="ค้นหากิจกรรม..." oninput="emFilter(this,\'rm-l1\')"></div>'
            + '<table class="data-table" style="width:100%;table-layout:fixed;"><thead><tr>'
            + '<th>กิจกรรม</th><th style="text-align:center;width:7.5rem;">วันที่</th>'
            + '<th style="text-align:right;width:8rem;">การดูดกลับ<br><span style="white-space:nowrap;">(tCO₂e/ต่อปี)</span></th><th style="text-align:center;width:8.5rem;">ดูรายละเอียด</th>'
            + '</tr></thead><tbody id="rm-l1">' + rows + '</tbody></table>';
    };
    w.rmOpenDetail = function (eid) {
        var g = groupRows(w.REMOVAL_ROWS)[eid];
        var body = document.getElementById('removalModalBody');
        if (!g || !body) return;
        var bc = document.getElementById('rmBreadcrumb'); if (bc) bc.style.display = 'block';
        setTitle('rmModalTitle', g.name);   // ชื่อกิจกรรมขึ้นเป็นหัวโมดัล
        var rows = g.items.map(function (r) {
            return '<tr>'
                + '<td style="font-weight:600;"><div style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + esc(r.name_tiem) + '">' + esc(r.name_tiem) + '</div></td>'
                + '<td style="text-align:center;color:#6B7280;">' + esc(r.unit || '-') + '</td>'
                + '<td style="text-align:center;">' + f4(r.factor) + '</td>'
                + '<td style="text-align:center;font-weight:700;">' + qty(r.qty) + '</td>'
                + '<td style="text-align:center;font-weight:700;color:#166534;">' + f4(r.emission) + '</td>'
                + '</tr>';
        }).join('');
        body.innerHTML = '<div style="margin-bottom:12px;"><input type="text" class="modal-search-input" placeholder="ค้นหารายการ..." oninput="emFilter(this,\'rm-l2\')"></div>'
            + '<table class="data-table" style="width:100%;table-layout:fixed;"><thead><tr>'
            + '<th>รายการดูดกลับ</th><th style="text-align:center;width:5rem;">หน่วย</th>'
            + '<th style="text-align:center;width:11rem;">ค่าดูดกลับ<br><span style="white-space:nowrap;">(kgCO₂e/หน่วย)</span></th><th style="text-align:center;width:6rem;">ปริมาณ</th><th style="text-align:center;width:7rem;">tCO₂e</th>'
            + '</tr></thead><tbody id="rm-l2">' + rows + '</tbody></table>';
    };
    w.openRemovalModal = function () {
        w.rmRenderList();
        var m = document.getElementById('removalModal');
        if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
    };
    w.closeRemovalModal = function () {
        var m = document.getElementById('removalModal');
        if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
    };
})(window);
