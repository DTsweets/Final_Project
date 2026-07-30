/**
 * activity-modal.js — โมดัล "การปล่อยจากกิจกรรมของคณะ" แบบ 2 ระดับ (list → detail)
 * ใช้ร่วม officer/dean · อ่าน window.EMISSION_ROWS · แสดงเฉพาะคณะตัวเอง (ข้อมูลถูกกรองมาแล้วฝั่ง PHP)
 * ต้องมี element: #emissionModal, #emissionModalBody
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
    // จัดกลุ่มตามกิจกรรม (event) + ยอดปล่อยรวมของกิจกรรม
    function groups() {
        var g = {};
        (w.EMISSION_ROWS || []).forEach(function (a) {
            var k = a.event_id;
            if (!g[k]) g[k] = { eid: k, name: a.event_name || '-', date: a.event_date, end: a.event_end_date, items: [], emit: 0 };
            g[k].items.push(a);
            g[k].emit += parseFloat(a.emission) || 0;
        });
        return g;
    }
    // ค้นหาในตาราง (ซ่อนแถวที่ไม่ตรง)
    w.emFilter = function (inp, tbodyId) {
        var q = inp.value.trim().toLowerCase(), tb = document.getElementById(tbodyId);
        if (!tb) return;
        Array.from(tb.rows).forEach(function (r) { r.style.display = r.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none'; });
    };
    var backSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>';

    // ระดับ 1: รายการกิจกรรม
    w.emRenderList = function () {
        var evs = Object.values(groups());
        var body = document.getElementById('emissionModalBody');
        if (!body) return;
        var bc = document.getElementById('emBreadcrumb'); if (bc) bc.style.display = 'none';   // list = ไม่มีปุ่มย้อนกลับ
        if (!evs.length) { body.innerHTML = '<p style="color:#9CA3AF;text-align:center;padding:24px;">ยังไม่มีข้อมูล</p>'; return; }
        var rows = evs.map(function (ev) {
            var date = ev.end ? (dmy(ev.date) + ' - ' + dmy(ev.end)) : dmy(ev.date);
            return '<tr>'
                + '<td style="font-weight:600;"><div style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(ev.name) + '">' + esc(ev.name) + '</div></td>'
                + '<td style="text-align:center;color:#6B7280;white-space:nowrap;">' + date + '</td>'
                + '<td style="text-align:right;font-weight:700;color:var(--clr-primary);">' + f4(ev.emit) + '</td>'
                + '<td style="text-align:center;"><button class="btn-detail" onclick="emOpenDetail(' + ev.eid + ')">ดูรายละเอียด</button></td>'
                + '</tr>';
        }).join('');
        body.innerHTML = '<p class="muted" style="margin:0 0 12px;color:#8A8194;">คลิกที่กิจกรรมเพื่อดูรายละเอียด</p>'
            + '<div style="margin-bottom:12px;"><input type="text" class="modal-search-input" placeholder="ค้นหากิจกรรม..." oninput="emFilter(this,\'em-l1\')"></div>'
            + '<table class="data-table" style="width:100%;"><thead><tr>'
            + '<th>กิจกรรม</th><th style="text-align:center;">วันที่</th>'
            + '<th style="text-align:right;">Emission<br>(tCO₂e/ต่อปี)</th><th style="text-align:center;">ดูรายละเอียด</th>'
            + '</tr></thead><tbody id="em-l1">' + rows + '</tbody></table>';
    };

    // ระดับ 2: รายการภายในกิจกรรม
    w.emOpenDetail = function (eid) {
        var g = groups()[eid];
        var body = document.getElementById('emissionModalBody');
        if (!g || !body) return;
        var bc = document.getElementById('emBreadcrumb'); if (bc) bc.style.display = 'block';   // detail = โชว์ปุ่มย้อนกลับที่หัวโมดัล
        var rows = g.items.map(function (r) {
            var scope = (r.scope == null)
                ? '<span style="color:var(--text-muted);">-</span>'
                : '<span style="font-size:.72rem;font-weight:700;border-radius:999px;padding:2px 10px;background:#EFF6FF;color:#2563EB;white-space:nowrap;">Scope ' + r.scope + '</span>';
            return '<tr>'
                + '<td style="font-weight:600;overflow-wrap:anywhere;word-break:break-word;">' + esc(r.name_tiem) + '</td>'
                + '<td style="text-align:center;"><span style="font-size:.72rem;font-weight:700;color:#92400E;background:#FEF3C7;border-radius:999px;padding:2px 10px;white-space:nowrap;">ปล่อย</span></td>'
                + '<td style="text-align:center;">' + scope + '</td>'
                + '<td style="text-align:center;color:#6B7280;">' + esc(r.unit || '-') + '</td>'
                + '<td style="text-align:right;font-weight:700;">' + qty(r.qty) + '</td>'
                + '<td style="text-align:right;font-weight:700;color:var(--clr-primary);">' + f4(r.emission) + '</td>'
                + '</tr>';
        }).join('');
        body.innerHTML = '<div style="font-weight:800;color:#2A2233;margin-bottom:10px;overflow-wrap:anywhere;word-break:break-word;">' + esc(g.name) + '</div>'
            + '<div style="margin-bottom:12px;"><input type="text" class="modal-search-input" placeholder="ค้นหารายการ..." oninput="emFilter(this,\'em-l2\')"></div>'
            + '<table class="data-table" style="width:100%;"><thead><tr>'
            + '<th>รายการ</th><th style="text-align:center;">ประเภท</th><th style="text-align:center;">Scope</th>'
            + '<th style="text-align:center;">หน่วย</th><th style="text-align:right;">จำนวน</th><th style="text-align:right;">tCO₂e</th>'
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
})(window);
