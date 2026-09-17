/**
 * admin-dashboard.js — Dashboard ของ admin (admin/index.php) และคณบดี (dean/index.php, data.mode = 'faculty')
 * ------------------------------------------------------------------
 * หน้าต่างรายละเอียด "หน้าต่างเดียว ย้อนกลับได้หลายชั้น" แทนหน้าต่างเดิม 7 อัน + lightbox:
 *   แยกตามผู้ให้ข้อมูล / ขอบเขต n / ดูดกลับ / สะสม / ประวัติการรายงาน
 *     → หน่วยงาน×ปี → กลุ่มกิจกรรม → รายการ → ไฟล์หลักฐาน
 *     → กิจกรรม×ปี → กิจกรรม → รายการ · แบบสอบถาม×ปี → กลุ่ม → คำถาม
 * ข้อความจากผู้ใช้ทุกชิ้นผ่าน esc() ก่อนใส่ innerHTML · ไม่มี onclick ในสตริง (ใช้ data-go + ตัวดักคลิกที่ตัวหน้าต่าง)
 * โหลดซ้ำได้เมื่อ SPA สลับหน้า: ทุกอย่างอยู่ใน IIFE, ตัวดักของ document/window ผูกครั้งเดียว และตรวจว่าองค์ประกอบยังอยู่
 * API เดิม: admin/api/api_reports_list.php, api_affil_detail.php, api_item_detail.php, officer/api/manage_evidence.php
 * โหมด faculty (คณะเดียว): รายการทั้งหมด / ขอบเขต n → กิจกรรมที่คณะจัด → รายการปล่อย + ดูดกลับของงาน — ข้อมูลฝังในหน้า ไม่เรียก API
 */
(function (w) {
    'use strict';

    // ── ตัวช่วยที่ไม่แตะหน้าเว็บ (เทสต์ด้วย node: tests/admin_dashboard_js_test.js) ──
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function fmt(n, min, max) {
        return (Number(n) || 0).toLocaleString('th-TH', { minimumFractionDigits: min, maximumFractionDigits: max == null ? min : max });
    }
    /** 0 / ว่าง / ไม่ใช่ตัวเลข → "-" */
    function dash(n, min, max) {
        var v = (n === null || n === undefined || n === '') ? NaN : Number(n);
        return (!isFinite(v) || v === 0) ? '-' : fmt(v, min, max);
    }
    function pctOf(v, total) { return total > 0 ? (Number(v) || 0) / total * 100 : 0; }
    function dmy(iso) {
        var m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '-';
    }
    var PALETTE = ['#62368B', '#0EA5E9', '#10B981', '#F43F5E', '#8B5CF6', '#14B8A6', '#EAB308', '#6366F1', '#F97316', '#84CC16', '#EC4899', '#06B6D4'];
    var KIND_COLOR = { survey: '#7C3AED', event: '#F59E0B', central: '#16A34A' };
    var OTHER = '#9CA3AF', EMPTY = '#E5E7EB';
    /**
     * สีต่อแถว: แบบสอบถาม/กิจกรรมสีคงที่ · หน่วยงานไล่ตามลำดับยอด (เดิมเดาจากชื่อคณะ ชื่อคล้ายกันได้สีซ้ำ) · ยอด 0 = เทา
     */
    function colorize(rows) {
        var k = 0;
        return (rows || []).map(function (r) {
            var v = Number(r.value) || 0, c;
            if (v <= 0) c = EMPTY;
            else if (KIND_COLOR[r.kind] && r.kind !== 'central') c = KIND_COLOR[r.kind];
            else c = PALETTE[k++ % PALETTE.length];
            var o = {}; for (var key in r) o[key] = r[key];
            o.color = c;
            return o;
        });
    }
    /** ชิ้นของโดนัท: N อันดับแรกที่มียอด + "อื่น ๆ" */
    function pieSlices(rows, topN) {
        var has = (rows || []).filter(function (r) { return (Number(r.value) || 0) > 0; })
            .sort(function (a, b) { return b.value - a.value; });
        var n = topN || 5, out = has.slice(0, n);
        var rest = has.slice(n).reduce(function (s, r) { return s + Number(r.value); }, 0);
        if (rest > 0) out.push({ name: 'อื่น ๆ', value: rest, color: OTHER, other: true });
        return out;
    }
    function groupBy(rows, keyFn) {
        var idx = {}, out = [];
        (rows || []).forEach(function (r) {
            var k = keyFn(r);
            if (!(k in idx)) { idx[k] = out.length; out.push({ key: k, first: r, items: [] }); }
            out[idx[k]].items.push(r);
        });
        return out;
    }
    function sum(rows, field) { return (rows || []).reduce(function (s, r) { return s + (Number(r[field]) || 0); }, 0); }
    /** ลิงก์หลักฐานเปิดได้เฉพาะ http(s) — กัน javascript: */
    function safeUrl(u) { return /^https?:\/\//i.test(String(u || '')) ? String(u) : ''; }
    /**
     * แถวของหน้าต่างรายการคณะ (Dashboard คณบดี): แปลง source → kind (แถวรวมกิจกรรม/แบบสอบถามได้สีและป้ายของตัวเอง)
     * เรียงยอดมาก → น้อย โดยคงลำดับเดิมเมื่อยอดเท่ากัน (รายการที่ยังไม่กรอกอยู่ท้ายตามลำดับแม่บท)
     */
    function facultyRows(rows) {
        var KIND = { event_total: 'event', survey_total: 'survey' };
        return (rows || []).map(function (r, i) {
            return { kind: KIND[r.source] || 'item', name: r.name, unit: r.unit, vol: r.vol, scope: Number(r.scope) || 0, value: Number(r.emission) || 0, _i: i };
        }).sort(function (a, b) { return b.value - a.value || a._i - b._i; });
    }

    /** ชุดข้อมูลของกราฟประวัติ: Dashboard คณบดีสลับ 'faculty' / 'uni' ได้ · admin มีชุดเดียว (history) */
    function histGroups(data, which) {
        var rows = (which === 'uni' && data && data.uniHistory) ? data.uniHistory : ((data && data.history) || []);
        return rows.map(function (h) { return { label: h.year, values: [Number(h.s1) || 0, Number(h.s2) || 0, Number(h.s3) || 0] }; });
    }

    w.adHelpers = { esc: esc, fmt: fmt, dash: dash, pctOf: pctOf, dmy: dmy, colorize: colorize, pieSlices: pieSlices, groupBy: groupBy, safeUrl: safeUrl, facultyRows: facultyRows, histGroups: histGroups };
    var d = w.document;
    if (!d) return;

    // ── สถานะ ──
    var S = { data: null, stack: [], lb: { files: [], i: 0 } };
    var API = 'api/', EV_API = '../officer/api/manage_evidence.php', EV_BASE = '../assets/images/evidence/';
    var reduce = function () { return !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches); };
    function $(id) { return d.getElementById(id); }
    var ARROW = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>';
    var THEME = {
        gross: 'linear-gradient(135deg,#62368B,#8B5CF6)', removal: 'linear-gradient(135deg,#166534,#22C55E)',
        event: 'linear-gradient(135deg,#B45309,#F59E0B)', survey: 'linear-gradient(135deg,#5B21B6,#8B5CF6)',
        reports: 'linear-gradient(135deg,#334155,#62368B)', files: 'linear-gradient(135deg,#312E81,#6366F1)'
    };
    function scopeTheme(n) { var m = S.data && S.data.scopeMeta[n]; return m ? 'linear-gradient(135deg,' + m.deep + ',' + m.color + ')' : THEME.gross; }

    function getJSON(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
    }

    // ── หน้าต่าง: เปิด / ซ้อนชั้น / ย้อนกลับ ──
    function open(view) { S.stack = [view]; render('in'); var m = $('adModal'); m.classList.add('open'); m.style.display = 'flex'; d.body.style.overflow = 'hidden'; pop(); }
    function push(view) { S.stack.push(view); render('in'); }
    function back() { if (S.stack.length > 1) { S.stack.pop(); render('back'); } }
    function close() { var m = $('adModal'); if (!m) return; m.classList.remove('open'); m.style.display = 'none'; d.body.style.overflow = ''; S.stack = []; lbClose(); }
    function pop() { var b = d.querySelector('#adModal .modal-box'); if (b) { b.style.animation = 'none'; void b.offsetWidth; b.style.animation = ''; } }

    function render(dir) {
        var v = S.stack[S.stack.length - 1], body = $('adModalBody');
        if (!v || !body) return;
        $('adModalHead').style.setProperty('--mh', v.theme || THEME.gross);
        $('adModalLabel').textContent = v.label || '';
        $('adModalTitle').textContent = v.title || '';
        var prev = S.stack[S.stack.length - 2];
        $('adBack').hidden = !prev;
        $('adBackLabel').textContent = prev ? 'กลับ: ' + (prev.short || prev.title) : '';
        var token = {}; v._token = token;
        var put = function (html) {
            if (v._token !== token || S.stack[S.stack.length - 1] !== v) return;   // เปลี่ยนหน้าไปแล้วระหว่างรอ
            body.innerHTML = html;
            body.classList.remove('ad-view-in', 'ad-view-back'); void body.offsetWidth;
            body.classList.add(dir === 'back' ? 'ad-view-back' : 'ad-view-in');
            body.scrollTop = 0;
            if (v.after) v.after(body);
        };
        var out = v.render();
        if (out && typeof out.then === 'function') {
            body.innerHTML = '<div class="ad-loading"><span class="ad-spin"></span>กำลังโหลดข้อมูล…</div>';
            out.then(put).catch(function () { put('<div class="ad-empty">โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่</div>'); });
        } else put(out);
    }

    // ── ชิ้นส่วน HTML ──
    function stats(list) {
        return '<div class="ad-stats">' + list.map(function (s) {
            return '<div class="ad-stat' + (s.tone ? ' is-' + s.tone : '') + '"><span>' + esc(s.label) + '</span><b>' + s.value + '</b></div>';
        }).join('') + '</div>';
    }
    function search(ph) { return '<input type="search" class="oe-search ad-search" data-ad-search placeholder="' + esc(ph) + '" aria-label="' + esc(ph) + '">'; }
    function table(head, rows, empty) {
        return '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>' + head.map(function (h) {
            return '<th' + (h.cls ? ' class="' + h.cls + '"' : '') + (h.w ? ' style="width:' + h.w + '"' : '') + '>' + h.t + '</th>';
        }).join('') + '</tr></thead><tbody>' + (rows.length ? rows.join('') : '<tr><td colspan="' + head.length + '" class="ad-td-empty">' + esc(empty || 'ไม่มีข้อมูล') + '</td></tr>') + '</tbody></table></div>';
    }
    function shareCell(p, color) {
        return '<td class="ad-r"><span class="ad-share"><span class="ad-share-track"><span class="ad-share-bar" style="width:' + Math.min(100, p).toFixed(2) + '%;background:' + color + ';"></span></span><em>' + fmt(p, 2) + '%</em></span></td>';
    }
    /** ป้ายชนิด (แบบสอบถาม/กิจกรรม) — ไม่ซ้ำเมื่อชื่อแถวเป็นคำเดียวกันอยู่แล้ว */
    function kindTag(r) {
        if (r.kind !== 'survey' && r.kind !== 'event') return '';
        var t = r.kind === 'survey' ? 'แบบสอบถาม' : 'กิจกรรม';
        return r.name === t ? '' : ' <span class="ad-kind is-' + r.kind + '">' + t + '</span>';
    }
    function goCell(on) { return '<td class="ad-c ad-go-cell">' + (on ? '<span class="ad-go">' + ARROW + '</span>' : '') + '</td>'; }
    function scopePill(s) { var m = S.data.scopeMeta[s]; return m ? '<span class="ad-pill" style="--c:' + m.color + ';">ขอบเขต ' + s + '</span>' : '-'; }
    function filesBtn(i, n) {
        return Number(n) > 0 ? '<button type="button" class="ad-file-btn" data-go="files" data-i="' + i + '">ไฟล์ <b>' + Number(n) + '</b></button>' : '<span class="oe-muted">-</span>';
    }

    /** โดนัท + คำอธิบาย 5 อันดับแรก */
    function donut(rows, total, center) {
        var slices = pieSlices(rows, 5);
        var legend = slices.length ? slices.map(function (r, i) {
            return '<li style="--i:' + i + ';"><i style="background:' + r.color + ';"></i><span title="' + esc(r.name) + '">' + esc(r.name) + '</span><b>' + fmt(pctOf(r.value, total), 2) + '%</b><small>' + fmt(r.value, 2, 4) + '</small></li>';
        }).join('') : '<li class="ad-legend-empty">ยังไม่มีข้อมูล</li>';
        return {
            html: '<div class="ad-donut"><div class="ad-donut-canvas"><canvas width="220" height="220" data-ad-donut></canvas><span class="ad-donut-center"><b>' + fmt(total, 2) + '</b><small>' + esc(center) + '</small></span></div>'
                + '<div class="ad-donut-legend"><div class="ad-donut-title">สัดส่วน 5 อันดับแรก</div><ol>' + legend + '</ol></div></div>',
            after: function (body) { drawDonut(body.querySelector('[data-ad-donut]'), slices); }
        };
    }
    function drawDonut(canvas, slices) {
        if (!canvas || !w.drawGhgDonut) return;
        var dpr = Math.min(w.devicePixelRatio || 1, 2), size = 220;
        canvas.width = size * dpr; canvas.height = size * dpr; canvas.style.width = size + 'px'; canvas.style.height = size + 'px';
        var data = slices.map(function (s) { return { value: s.value, color: s.color }; });
        var total = sum(data, 'value');
        if (reduce() || total <= 0) { w.drawGhgDonut(canvas, data, ''); return; }
        // วาดภาพสุดท้ายไว้ก่อน (แท็บที่ไม่แสดงผลจะไม่มีเฟรมแอนิเมชัน) แล้วค่อยไล่วาดตามเข็มนาฬิกาเมื่อเฟรมแรกมาถึง
        w.drawGhgDonut(canvas, data, '');
        var t0 = null;
        w.requestAnimationFrame(function frame(ts) {
            if (!canvas.isConnected) return;
            if (t0 === null) t0 = ts;
            var p = Math.min(1, (ts - t0) / 700), e = 1 - Math.pow(1 - p, 3);
            w.drawGhgDonut(canvas, data.map(function (x) { return { value: x.value * e, color: x.color }; }).concat([{ value: total * (1 - e), color: 'rgba(0,0,0,0)' }]), '');
            if (p < 1) w.requestAnimationFrame(frame);
        });
    }

    // ── มุมมองระดับบน ──
    function providerRows(rows, total, linkable) {
        return rows.map(function (r, i) {
            var p = pctOf(r.value, total), on = linkable && r.value > 0;
            return '<tr class="ad-tr' + (on ? ' is-link' : '') + '"' + (on ? ' data-go="provider" data-i="' + i + '" tabindex="0"' : '') + '>'
                + '<td class="ad-c ad-muted">' + (i + 1) + '</td>'
                + '<td><span class="ad-name"><i style="background:' + r.color + ';"></i>' + esc(r.name) + kindTag(r) + '</span></td>'
                + '<td class="ad-r ad-num">' + (r.value > 0 ? fmt(r.value, 4) : '<span class="ad-muted">ไม่มีข้อมูล</span>') + '</td>'
                + shareCell(p, r.color) + goCell(on) + '</tr>';
        });
    }
    var PROVIDER_HEAD = [{ t: '#', cls: 'ad-c', w: '44px' }, { t: 'ผู้ให้ข้อมูล' }, { t: 'tCO₂e', cls: 'ad-r', w: '130px' }, { t: 'สัดส่วน', cls: 'ad-r', w: '170px' }, { t: '', w: '40px' }];

    /** แยกตามผู้ให้ข้อมูล (ปีที่เลือก) หรือรายขอบเขต */
    function breakdownView(scope) {
        var D = S.data, rows = colorize(scope ? D.scopeBreakdown[scope] : D.breakdown), total = scope ? D.scope[scope] : D.gross;
        var withData = rows.filter(function (r) { return r.value > 0; }).length;
        return {
            label: scope ? 'ขอบเขต ' + scope + ' · ' + D.scopeMeta[scope].name : 'การปล่อยทั้งหมด — แยกตามผู้ให้ข้อมูล',
            title: 'ปี ' + D.yearLabel + (scope ? ' · ขอบเขต ' + scope : ''), short: scope ? 'ขอบเขต ' + scope : 'แยกตามผู้ให้ข้อมูล',
            theme: scope ? scopeTheme(scope) : THEME.gross, rows: rows,
            render: function () {
                var dn = donut(rows, total, 'tCO₂e · ปี ' + D.yearLabel); this._after = dn.after;
                return stats([{ label: 'รวม (tCO₂e)', value: fmt(total, 2) }, { label: 'ผู้ให้ข้อมูลที่มียอด', value: withData + ' / ' + rows.length }])
                    + dn.html + search('ค้นหาหน่วยงาน…') + table(PROVIDER_HEAD, providerRows(rows, total, true));
            },
            after: function (b) { this._after(b); },
            go: function (name, i) { var r = rows[i]; if (r) push(r.kind === 'faculty' ? affilYearView(r.id, r.name, D.year, D.yearLabel) : sourceYearView(r.kind, D.year, D.yearLabel)); }
        };
    }

    function removalView() {
        var D = S.data;
        var rows = colorize(D.removalRows.map(function (r) { return { kind: r.kind === 'event' ? 'event' : 'central', name: r.name, unit: r.unit, factor: r.factor, qty: r.qty, value: Number(r.emission) || 0 }; }));
        rows.forEach(function (r) { if (r.kind === 'event' && r.value > 0) r.color = '#16A34A'; });
        return {
            label: 'การดูดกลับ — ส่วนกลาง + กิจกรรม', title: 'ปี ' + D.yearLabel, short: 'การดูดกลับ', theme: THEME.removal,
            render: function () {
                var dn = donut(rows, D.removal, 'tCO₂e · ดูดกลับ'); this._after = dn.after;
                var trs = rows.map(function (r, i) {
                    var ev = r.kind === 'event';
                    return '<tr class="ad-tr"><td class="ad-c ad-muted">' + (i + 1) + '</td><td><span class="ad-name"><i style="background:' + r.color + ';"></i>' + esc(r.name) + (ev ? kindTag({ kind: 'event', name: r.name }) : '') + '</span></td>'
                        + '<td class="ad-c ad-muted">' + (ev ? '—' : esc(r.unit || '-')) + '</td><td class="ad-r">' + (ev ? '—' : dash(r.factor, 4)) + '</td><td class="ad-r">' + (ev ? '—' : dash(r.qty, 0, 4)) + '</td>'
                        + '<td class="ad-r ad-num is-green">' + dash(r.value, 4) + '</td>' + shareCell(pctOf(r.value, D.removal), r.color) + '</tr>';
                });
                return stats([{ label: 'ส่วนกลาง (tCO₂e)', value: fmt(D.removalCentral, 4) }, { label: 'จากกิจกรรม (tCO₂e)', value: fmt(D.removalActivity, 4) }, { label: 'รวมการดูดกลับ', value: fmt(D.removal, 4), tone: 'green' }])
                    + dn.html + table([{ t: '#', cls: 'ad-c', w: '44px' }, { t: 'รายการดูดกลับ' }, { t: 'หน่วย', cls: 'ad-c', w: '90px' }, { t: 'kgCO₂e/หน่วย', cls: 'ad-r', w: '110px' }, { t: 'ปริมาณ', cls: 'ad-r', w: '100px' }, { t: 'tCO₂e', cls: 'ad-r', w: '110px' }, { t: 'สัดส่วน', cls: 'ad-r', w: '160px' }], trs, 'ยังไม่มีรายการดูดกลับในปีนี้');
            },
            after: function (b) { this._after(b); }
        };
    }

    function cumulativeView() {
        var D = S.data, rows = colorize(D.cumulative);
        return {
            label: 'การปล่อยสะสมทุกปี — แยกตามผู้ให้ข้อมูล', title: 'รวม ' + D.history.length + ' ปีงบประมาณ', short: 'สะสมทุกปี', theme: THEME.gross, rows: rows,
            render: function () {
                var dn = donut(rows, D.cumulativeTotal, 'tCO₂e · ทุกปี'); this._after = dn.after;
                return stats([{ label: 'สะสมทุกปี (tCO₂e)', value: fmt(D.cumulativeTotal, 2) }, { label: 'ผู้ให้ข้อมูลที่มียอด', value: rows.filter(function (r) { return r.value > 0; }).length + ' / ' + rows.length }])
                    + dn.html + search('ค้นหาหน่วยงาน…') + table(PROVIDER_HEAD, providerRows(rows, D.cumulativeTotal, true));
            },
            after: function (b) { this._after(b); },
            go: function (name, i) { var r = rows[i]; if (r) push(providerYearsView(r.kind, r.id, r.name)); }
        };
    }

    function reportsView() {
        var list = [];
        return {
            label: 'ประวัติการรายงาน — ผู้ให้ข้อมูลแต่ละรายรายงานกี่ปี', title: 'จำนวนครั้งที่รายงาน', short: 'ประวัติการรายงาน', theme: THEME.reports,
            render: function () {
                return getJSON(API + 'api_reports_list.php?mode=list').then(function (data) {
                    list = (data || []).map(function (r) { return { kind: r.kind, id: r.affil_id == null ? null : Number(r.affil_id), name: r.affil_name, years: Number(r.year_count) || 0, value: Number(r.total_emission) || 0 }; })
                        .sort(function (a, b) { return b.years - a.years || b.value - a.value; });
                    var reported = list.filter(function (r) { return r.years > 0; });
                    var trs = list.map(function (r, i) {
                        var on = r.years > 0;
                        return '<tr class="ad-tr' + (on ? ' is-link' : ' is-dim') + '"' + (on ? ' data-go="provider" data-i="' + i + '" tabindex="0"' : '') + '>'
                            + '<td><span class="ad-name">' + esc(r.name) + kindTag(r) + '</span></td>'
                            + '<td class="ad-c"><span class="ad-count' + (on ? '' : ' is-zero') + '">' + r.years + ' ปี</span></td>'
                            + '<td class="ad-r ad-num">' + dash(r.value, 2, 4) + '</td>' + goCell(on) + '</tr>';
                    });
                    return stats([{ label: 'ผู้ให้ข้อมูลที่เคยรายงาน', value: reported.length + ' / ' + list.length }, { label: 'รวมครั้งที่รายงาน', value: fmt(sum(list, 'years'), 0) + ' ครั้ง' }])
                        + search('ค้นหาผู้ให้ข้อมูล…') + table([{ t: 'ผู้ให้ข้อมูล' }, { t: 'รายงานแล้ว', cls: 'ad-c', w: '110px' }, { t: 'tCO₂e สะสม', cls: 'ad-r', w: '130px' }, { t: '', w: '40px' }], trs);
                });
            },
            go: function (name, i) { var r = list[i]; if (r) push(providerYearsView(r.kind, r.id, r.name)); }
        };
    }

    /** ปีที่ผู้ให้ข้อมูลรายงาน */
    function providerYearsView(kind, id, name) {
        var years = [];
        return {
            label: 'ปีที่รายงาน', title: name, short: name, theme: kind === 'faculty' ? THEME.gross : THEME[kind],
            render: function () {
                var q = kind === 'faculty' ? 'affil_id=' + encodeURIComponent(id) : 'source=' + encodeURIComponent(kind);
                return getJSON(API + 'api_reports_list.php?mode=years&' + q).then(function (data) {
                    years = data || [];
                    var unit = kind === 'event' ? 'กิจกรรม' : (kind === 'survey' ? 'ชุด' : 'รายการ');
                    var trs = years.map(function (y, i) {
                        return '<tr class="ad-tr is-link" data-go="year" data-i="' + i + '" tabindex="0"><td><b>' + esc(y.year_label) + '</b></td><td class="ad-c"><span class="ad-count">' + fmt(y.item_count, 0) + ' ' + unit + '</span></td><td class="ad-r ad-num">' + dash(y.total_emission, 4) + '</td>' + goCell(true) + '</tr>';
                    });
                    return stats([{ label: 'ปีที่มีข้อมูล', value: years.length + ' ปี' }, { label: 'รวม (tCO₂e)', value: fmt(sum(years, 'total_emission'), 2) }])
                        + table([{ t: 'ปีงบประมาณ' }, { t: 'จำนวน', cls: 'ad-c', w: '130px' }, { t: 'tCO₂e', cls: 'ad-r', w: '140px' }, { t: '', w: '40px' }], trs, 'ยังไม่มีข้อมูล');
                });
            },
            go: function (n, i) { var y = years[i]; if (y) push(kind === 'faculty' ? affilYearView(id, name, y.year_id, y.year_label) : sourceYearView(kind, y.year_id, y.year_label)); }
        };
    }

    /** หน่วยงาน × ปี → กลุ่มกิจกรรม */
    function affilYearView(id, name, yearId, yearLabel) {
        var groups = [];
        return {
            label: 'การดำเนินงาน · ปี ' + yearLabel, title: name, short: name, theme: THEME.gross,
            render: function () {
                return getJSON(API + 'api_affil_detail.php?affil_id=' + encodeURIComponent(id) + '&year_id=' + encodeURIComponent(yearId)).then(function (data) {
                    groups = groupBy(data, function (r) { return r.activity_type; });
                    var total = sum(data, 'emission');
                    var trs = groups.map(function (g, i) {
                        var e = sum(g.items, 'emission'), filled = g.items.filter(function (r) { return Number(r.vol) > 0; }).length;
                        return '<tr class="ad-tr is-link" data-go="group" data-i="' + i + '" tabindex="0"><td class="ad-c">' + scopePill(Number(g.first.scope)) + '</td><td><b>' + esc(g.key) + '</b></td><td class="ad-c"><span class="ad-count">' + filled + ' / ' + g.items.length + '</span></td><td class="ad-r ad-num">' + dash(e, 4) + '</td>' + goCell(true) + '</tr>';
                    });
                    return stats([{ label: 'รายการที่บันทึก', value: (data || []).length + ' รายการ' }, { label: 'รวม (tCO₂e)', value: fmt(total, 2) }])
                        + search('ค้นหากลุ่มกิจกรรม…') + table([{ t: 'ขอบเขต', cls: 'ad-c', w: '100px' }, { t: 'กลุ่มกิจกรรม' }, { t: 'กรอกแล้ว', cls: 'ad-c', w: '100px' }, { t: 'tCO₂e', cls: 'ad-r', w: '130px' }, { t: '', w: '40px' }], trs, 'หน่วยงานนี้ยังไม่ได้กรอกข้อมูลในปีนี้');
                });
            },
            go: function (n, i) { var g = groups[i]; if (g) push(groupItemsView(g, name)); }
        };
    }
    function groupItemsView(g, affilName) {
        return {
            label: affilName, title: g.key, short: g.key, theme: scopeTheme(Number(g.first.scope)),
            render: function () {
                var trs = g.items.map(function (r, i) {
                    return '<tr class="ad-tr"><td><b>' + esc(r.name_tiem) + '</b></td><td class="ad-c ad-muted">' + esc(r.unit || '-') + '</td><td class="ad-r">' + dash(r.vol, 0, 4) + '</td><td class="ad-r ad-num">' + dash(r.emission, 4) + '</td><td class="ad-c">' + filesBtn(i, r.ev_count) + '</td></tr>';
                });
                return stats([{ label: 'รายการ', value: g.items.length }, { label: 'รวม (tCO₂e)', value: fmt(sum(g.items, 'emission'), 4) }])
                    + search('ค้นหารายการ…') + table([{ t: 'รายการ' }, { t: 'หน่วย', cls: 'ad-c', w: '90px' }, { t: 'ปริมาณ', cls: 'ad-r', w: '110px' }, { t: 'tCO₂e', cls: 'ad-r', w: '120px' }, { t: 'หลักฐาน', cls: 'ad-c', w: '90px' }], trs);
            },
            go: function (n, i) {
                var r = g.items[i]; if (!r) return;
                push(filesView(r.name_tiem, function () { return getJSON(API + 'api_item_detail.php?user_item_id=' + encodeURIComponent(r.user_item_id)).then(function (res) { return (res && res.files) || []; }); }));
            }
        };
    }

    /** แบบสอบถาม / กิจกรรม × ปี */
    function sourceYearView(kind, yearId, yearLabel) {
        var groups = [], isEvent = kind === 'event';
        return {
            label: (isEvent ? 'กิจกรรม' : 'แบบสอบถาม') + ' · ปี ' + yearLabel, title: isEvent ? 'กิจกรรมที่มีการปล่อย' : 'แบบสอบถามทั้งหมด', short: (isEvent ? 'กิจกรรม ' : 'แบบสอบถาม ') + yearLabel, theme: THEME[kind],
            render: function () {
                return getJSON(API + 'api_affil_detail.php?source=' + kind + '&year_id=' + encodeURIComponent(yearId)).then(function (data) {
                    if (isEvent) {
                        groups = groupBy(data, function (r) { return r.event_id; }).map(function (g) { g.emit = g.items.filter(function (r) { return r.itype === 'emit'; }); return g; })
                            .filter(function (g) { return g.emit.length; });
                        var trs = groups.map(function (g, i) {
                            var f = g.first;
                            return '<tr class="ad-tr is-link" data-go="event" data-i="' + i + '" tabindex="0"><td><b>' + esc(f.event_name) + '</b><small class="ad-sub">ผู้จัด: ' + esc(f.organizer || '-') + '</small></td><td class="ad-c ad-muted">' + dmy(f.event_date) + (f.event_end_date ? ' – ' + dmy(f.event_end_date) : '') + '</td><td class="ad-r ad-num">' + dash(sum(g.emit, 'emission'), 4) + '</td><td class="ad-c">' + filesBtn(i, f.ev_count) + '</td>' + goCell(true) + '</tr>';
                        });
                        return stats([{ label: 'กิจกรรมที่มีการปล่อย', value: groups.length + ' งาน' }, { label: 'รวม (tCO₂e)', value: fmt(groups.reduce(function (s, g) { return s + sum(g.emit, 'emission'); }, 0), 4) }])
                            + search('ค้นหากิจกรรม…') + table([{ t: 'กิจกรรม' }, { t: 'วันที่', cls: 'ad-c', w: '190px' }, { t: 'tCO₂e', cls: 'ad-r', w: '120px' }, { t: 'หลักฐาน', cls: 'ad-c', w: '90px' }, { t: '', w: '40px' }], trs, 'ยังไม่มีกิจกรรมที่มีการปล่อยในปีนี้');
                    }
                    groups = groupBy(data, function (r) { return r.questionnaire_id != null ? 'q' + r.questionnaire_id : 'a:' + r.audience; });
                    var trs2 = groups.map(function (g, i) {
                        var f = g.first, resp = Math.max.apply(null, g.items.map(function (r) { return Number(r.respondents) || 0; }).concat([0]));
                        return '<tr class="ad-tr is-link" data-go="survey" data-i="' + i + '" tabindex="0"><td><b>' + esc(f.audience || '-') + '</b><small class="ad-sub">ผู้จัดทำ: ' + esc(f.maker_name || '-') + '</small></td><td class="ad-r">' + fmt(resp, 0) + ' คน</td><td class="ad-r ad-num">' + dash(sum(g.items, 'emission'), 4) + '</td><td class="ad-c">' + filesBtn(i, f.ev_count) + '</td>' + goCell(true) + '</tr>';
                    });
                    return stats([{ label: 'แบบสอบถาม', value: groups.length + ' ชุด' }, { label: 'รวม (tCO₂e)', value: fmt(sum(data, 'emission'), 4) }])
                        + search('ค้นหากลุ่มผู้ตอบ…') + table([{ t: 'กลุ่มผู้ตอบ' }, { t: 'ผู้ตอบ', cls: 'ad-r', w: '100px' }, { t: 'tCO₂e', cls: 'ad-r', w: '120px' }, { t: 'หลักฐาน', cls: 'ad-c', w: '90px' }, { t: '', w: '40px' }], trs2, 'ยังไม่มีแบบสอบถามในปีนี้');
                });
            },
            go: function (name, i) {
                var g = groups[i]; if (!g) return;
                if (name === 'files') {
                    var type = isEvent ? 'event' : 'questionnaire', eid = isEvent ? g.first.event_id : g.first.questionnaire_id;
                    push(filesView(isEvent ? g.first.event_name : g.first.audience, function () {
                        return getJSON(EV_API + '?action=list&entity_type=' + type + '&entity_id=' + encodeURIComponent(eid)).then(function (res) { return (res && res.success && res.data) || []; });
                    }));
                } else push(isEvent ? eventItemsView(g) : surveyItemsView(g));
            }
        };
    }
    function eventItemsView(g) {
        var f = g.first;
        return {
            label: 'กิจกรรม · ผู้จัด: ' + (f.organizer || '-'), title: f.event_name, short: f.event_name, theme: THEME.event,
            render: function () {
                var trs = g.emit.map(function (r) {
                    return '<tr class="ad-tr"><td><b>' + esc(r.name_tiem) + '</b></td><td class="ad-c">' + scopePill(Number(r.scope)) + '</td><td class="ad-c ad-muted">' + esc(r.unit || '-') + '</td><td class="ad-r">' + dash(r.vol, 0, 4) + '</td><td class="ad-r ad-num">' + dash(r.emission, 4) + '</td></tr>';
                });
                return stats([{ label: 'วันที่', value: dmy(f.event_date) + (f.event_end_date ? ' – ' + dmy(f.event_end_date) : '') }, { label: 'รวม (tCO₂e)', value: fmt(sum(g.emit, 'emission'), 4) }])
                    + search('ค้นหารายการ…') + table([{ t: 'รายการ' }, { t: 'ขอบเขต', cls: 'ad-c', w: '100px' }, { t: 'หน่วย', cls: 'ad-c', w: '90px' }, { t: 'จำนวน', cls: 'ad-r', w: '100px' }, { t: 'tCO₂e', cls: 'ad-r', w: '120px' }], trs);
            }
        };
    }
    function surveyItemsView(g) {
        var f = g.first;
        return {
            label: 'แบบสอบถาม · ผู้จัดทำ: ' + (f.maker_name || '-'), title: f.audience || '-', short: f.audience || 'แบบสอบถาม', theme: THEME.survey,
            render: function () {
                var trs = g.items.map(function (r) {
                    return '<tr class="ad-tr"><td><b>' + esc(r.name_tiem) + '</b></td><td class="ad-r">' + dash(r.avg_value, 0, 4) + '</td><td class="ad-c ad-muted">' + esc(r.unit || '-') + '</td><td class="ad-r ad-num">' + dash(r.emission, 4) + '</td></tr>';
                });
                return search('ค้นหาคำถาม…') + table([{ t: 'คำถาม' }, { t: 'เฉลี่ย/คน', cls: 'ad-r', w: '100px' }, { t: 'หน่วย', cls: 'ad-c', w: '90px' }, { t: 'tCO₂e', cls: 'ad-r', w: '120px' }], trs);
            }
        };
    }

    /** ไฟล์หลักฐาน (อ่านอย่างเดียว) — รูปเปิดดูขนาดใหญ่ได้ เอกสาร/ลิงก์เปิดแท็บใหม่ */
    function filesView(title, loader) {
        var files = [];
        return {
            label: 'ไฟล์หลักฐาน', title: title, short: 'ไฟล์หลักฐาน', theme: THEME.files,
            render: function () {
                return loader().then(function (list) {
                    files = list || [];
                    if (!files.length) return '<div class="ad-empty">ไม่มีไฟล์หลักฐาน</div>';
                    return '<div class="ad-files">' + files.map(function (f, i) {
                        if (f.kind === 'link') {
                            var u = safeUrl(f.url);
                            return '<a class="ad-file is-link" style="--i:' + i + ';"' + (u ? ' href="' + esc(u) + '" target="_blank" rel="noopener noreferrer"' : '') + '><span class="ad-file-ic">🔗</span><b>' + esc(f.label || f.url || 'ลิงก์') + '</b><small>' + esc(u || 'ลิงก์ไม่ถูกต้อง') + '</small></a>';
                        }
                        var path = EV_BASE + encodeURI(String(f.file_path || '')), name = f.original_name || String(f.file_path || '').split('/').pop();
                        if (String(f.file_type || '').indexOf('image') === 0) {
                            return '<button type="button" class="ad-file is-img" style="--i:' + i + ';" data-go="lb" data-i="' + i + '"><img src="' + esc(path) + '" alt="' + esc(name) + '" loading="lazy"><small>' + esc(name) + '</small></button>';
                        }
                        var ext = (String(f.file_path || '').split('.').pop() || '').toUpperCase();
                        return '<a class="ad-file is-doc" style="--i:' + i + ';" href="' + esc(path) + '" target="_blank" rel="noopener" download="' + esc(name) + '"><span class="ad-file-ic">' + esc(ext) + '</span><b>' + esc(name) + '</b><small>ดาวน์โหลด</small></a>';
                    }).join('') + '</div>';
                });
            },
            go: function (n, i) {
                var imgs = files.map(function (f, k) { return { f: f, k: k }; }).filter(function (x) { return x.f.kind !== 'link' && String(x.f.file_type || '').indexOf('image') === 0; });
                lbOpen(imgs.map(function (x) { return { src: EV_BASE + encodeURI(String(x.f.file_path)), name: x.f.original_name || x.f.file_path }; }), Math.max(0, imgs.findIndex(function (x) { return x.k === i; })));
            }
        };
    }

    // ── lightbox ──
    function lbOpen(list, i) { S.lb = { files: list, i: i }; var el = $('adLightbox'); if (!el) return; el.hidden = false; lbShow(); }
    function lbClose() { var el = $('adLightbox'); if (el) el.hidden = true; }
    function lbShow() {
        var L = S.lb, f = L.files[L.i]; if (!f) return;
        $('adLbContent').innerHTML = '<img src="' + esc(f.src) + '" alt="' + esc(f.name) + '">';
        $('adLbCaption').innerHTML = esc(f.name) + (L.files.length > 1 ? ' · ' + (L.i + 1) + ' / ' + L.files.length : '') + ' · <a href="' + esc(f.src) + '" download target="_blank" rel="noopener">ดาวน์โหลด</a>';
        $('adLbPrev').hidden = $('adLbNext').hidden = L.files.length < 2;
    }
    function lbStep(n) { var L = S.lb; if (L.files.length < 2) return; L.i = (L.i + n + L.files.length) % L.files.length; lbShow(); }

    // ── การ์ดบนหน้า ──
    // ── มุมมองของคณะ (Dashboard คณบดี, data.mode = 'faculty') — ข้อมูลฝังในหน้าทั้งหมด ไม่เรียก API ──
    /** รายการของคณะ: ทั้งหมด (scope 0) หรือรายขอบเขต · แถวรวมกิจกรรมกดต่อไปดูรายกิจกรรม */
    function itemsView(scope) {
        var D = S.data, rows = colorize(facultyRows(D.detailRows[scope])), total = scope ? D.scope[scope] : D.gross;
        var withData = rows.filter(function (r) { return r.value > 0; }).length;
        return {
            label: scope ? 'ขอบเขต ' + scope + ' · ' + D.scopeMeta[scope].name : 'การปล่อยทั้งหมดของคณะ — รายการ',
            title: D.affilName + ' · ปี ' + D.yearLabel, short: scope ? 'ขอบเขต ' + scope : 'รายการทั้งหมด',
            theme: scope ? scopeTheme(scope) : THEME.gross,
            render: function () {
                var dn = donut(rows, total, 'tCO₂e · ปี ' + D.yearLabel); this._after = dn.after;
                var trs = rows.map(function (r, i) {
                    var on = r.kind === 'event' && r.value > 0 && D.eventGroups.length > 0;
                    return '<tr class="ad-tr' + (on ? ' is-link' : '') + '"' + (on ? ' data-go="events" data-i="' + i + '" tabindex="0"' : '') + '>'
                        + '<td><span class="ad-name"><i style="background:' + r.color + ';"></i>' + esc(r.name) + kindTag(r) + '</span></td>'
                        + (scope ? '' : '<td class="ad-c">' + (r.scope ? scopePill(r.scope) : '<span class="ad-muted">หลายขอบเขต</span>') + '</td>')
                        + '<td class="ad-c ad-muted">' + esc(r.unit || '-') + '</td><td class="ad-r">' + dash(r.vol, 0, 4) + '</td>'
                        + '<td class="ad-r ad-num">' + dash(r.value, 4) + '</td>' + shareCell(pctOf(r.value, total), r.color) + goCell(on) + '</tr>';
                });
                var head = [{ t: 'รายการ' }].concat(scope ? [] : [{ t: 'ขอบเขต', cls: 'ad-c', w: '110px' }])
                    .concat([{ t: 'หน่วย', cls: 'ad-c', w: '90px' }, { t: 'ปริมาณ', cls: 'ad-r', w: '100px' }, { t: 'tCO₂e', cls: 'ad-r', w: '110px' }, { t: 'สัดส่วน', cls: 'ad-r', w: '160px' }, { t: '', w: '40px' }]);
                return stats([{ label: 'รวม (tCO₂e)', value: fmt(total, 2) }, { label: 'รายการที่มียอด', value: withData + ' / ' + rows.length }])
                    + dn.html + search('ค้นหารายการ…') + table(head, trs, 'ยังไม่มีรายการในปีนี้');
            },
            after: function (b) { this._after(b); },
            go: function () { push(eventsView()); }
        };
    }
    /** กิจกรรมที่คณะจัด: ยอดปล่อย / ดูดกลับ รายงาน (removal = true → เฉพาะงานที่มีดูดกลับ + โดนัทดูดกลับ) */
    function eventsView(removal) {
        var D = S.data;
        var list = D.eventGroups.filter(function (g) { return removal ? g.removal > 0 : true; });
        var total = removal ? D.removal : D.eventTotal;
        var slices = colorize(list.map(function (g) { return { kind: 'item', name: g.name, value: removal ? g.removal : g.emit }; }));
        return {
            label: removal ? 'การดูดกลับจากกิจกรรมที่คณะจัด' : 'กิจกรรมที่คณะจัด — การปล่อยและการดูดกลับ',
            title: D.affilName + ' · ปี ' + D.yearLabel, short: removal ? 'การดูดกลับ' : 'กิจกรรม',
            theme: removal ? THEME.removal : THEME.event,
            render: function () {
                var dn = donut(slices, total, removal ? 'tCO₂e · ดูดกลับ' : 'tCO₂e · กิจกรรม'); this._after = dn.after;
                var trs = list.map(function (g, i) {
                    var date = dmy(g.date) + (g.end_date && g.end_date !== g.date ? ' – ' + dmy(g.end_date) : '');
                    return '<tr class="ad-tr is-link" data-go="event" data-i="' + i + '" tabindex="0">'
                        + '<td><span class="ad-name"><i style="background:' + slices[i].color + ';"></i>' + esc(g.name) + '</span></td>'
                        + '<td class="ad-c ad-muted">' + date + '</td>'
                        + '<td class="ad-r ad-num">' + dash(g.emit, 4) + '</td><td class="ad-r ad-num is-green">' + dash(g.removal, 4) + '</td>' + goCell(true) + '</tr>';
                });
                var st = removal
                    ? [{ label: 'กิจกรรมที่มีการดูดกลับ', value: list.length + ' งาน' }, { label: 'รวมการดูดกลับ (tCO₂e)', value: fmt(D.removal, 4), tone: 'green' }]
                    : [{ label: 'กิจกรรมทั้งหมด', value: D.eventCount + ' งาน' }, { label: 'ปล่อย (tCO₂e)', value: fmt(D.eventTotal, 4) }, { label: 'ดูดกลับ (tCO₂e)', value: fmt(D.removal, 4), tone: 'green' }];
                return stats(st) + dn.html + search('ค้นหากิจกรรม…')
                    + table([{ t: 'กิจกรรม' }, { t: 'วันที่จัด', cls: 'ad-c', w: '170px' }, { t: 'ปล่อย (tCO₂e)', cls: 'ad-r', w: '120px' }, { t: 'ดูดกลับ (tCO₂e)', cls: 'ad-r', w: '120px' }, { t: '', w: '40px' }], trs, 'ยังไม่มีกิจกรรมในปีนี้');
            },
            after: function (b) { this._after(b); },
            go: function (n, i) { var g = list[i]; if (g) push(eventDetailView(g)); }
        };
    }
    /** รายการปล่อย + ดูดกลับของกิจกรรมหนึ่งงาน */
    function eventDetailView(g) {
        return {
            label: 'กิจกรรม · ' + dmy(g.date), title: g.name, short: g.name, theme: THEME.event,
            render: function () {
                var emit = g.emit_rows.map(function (r) {
                    return '<tr class="ad-tr"><td><b>' + esc(r.name) + '</b></td><td class="ad-c">' + scopePill(r.scope) + '</td><td class="ad-c ad-muted">' + esc(r.unit || '-') + '</td>'
                        + '<td class="ad-r">' + dash(r.qty, 0, 4) + '</td><td class="ad-r ad-num">' + dash(r.emission, 4) + '</td></tr>';
                });
                var rem = g.removal_rows.map(function (r) {
                    return '<tr class="ad-tr"><td><b>' + esc(r.name) + '</b></td><td class="ad-c ad-muted">' + esc(r.unit || '-') + '</td><td class="ad-r">' + dash(r.factor, 4) + '</td>'
                        + '<td class="ad-r">' + dash(r.qty, 0, 4) + '</td><td class="ad-r ad-num is-green">' + dash(r.emission, 4) + '</td></tr>';
                });
                return stats([{ label: 'ปล่อย (tCO₂e)', value: fmt(g.emit, 4) }, { label: 'ดูดกลับ (tCO₂e)', value: fmt(g.removal, 4), tone: 'green' }, { label: 'สุทธิ (tCO₂e)', value: fmt(g.emit - g.removal, 4) }])
                    + '<h4 class="ad-section-h">การปล่อย</h4>'
                    + table([{ t: 'รายการ' }, { t: 'ขอบเขต', cls: 'ad-c', w: '110px' }, { t: 'หน่วย', cls: 'ad-c', w: '90px' }, { t: 'ปริมาณ', cls: 'ad-r', w: '100px' }, { t: 'tCO₂e', cls: 'ad-r', w: '110px' }], emit, 'ไม่มีรายการปล่อย')
                    + '<h4 class="ad-section-h">การดูดกลับ</h4>'
                    + table([{ t: 'รายการ' }, { t: 'หน่วย', cls: 'ad-c', w: '90px' }, { t: 'kgCO₂e/หน่วย', cls: 'ad-r', w: '110px' }, { t: 'ปริมาณ', cls: 'ad-r', w: '100px' }, { t: 'tCO₂e', cls: 'ad-r', w: '110px' }], rem, 'ไม่มีรายการดูดกลับ');
            }
        };
    }

    /**
     * อันดับการปล่อยรายคณะ/หน่วยงานทั้งหมด (ข้อมูลจาก ranking ที่ฝังในหน้า)
     * คณบดี: แถวคณะตัวเองไฮไลต์ กดดูรายการของคณะ · admin: กดได้ทุกแถว → รายการที่หน่วยงานนั้นกรอก (API เดิม)
     */
    function rankingView() {
        var D = S.data, rows = D.ranking || [], M = D.scopeMeta, faculty = D.mode === 'faculty';
        var ownIdx = faculty ? rows.findIndex(function (r) { return Number(r.affil_id) === Number(D.ownAffil); }) : -1, own = rows[ownIdx];
        return {
            label: 'อันดับการปล่อยรายคณะ/หน่วยงาน · เฉพาะการดำเนินงาน', title: 'ปี ' + D.yearLabel, short: 'อันดับ', theme: THEME.reports,
            render: function () {
                var sumAll = sum(rows, 'total');
                var trs = rows.map(function (r, i) {
                    var mine = i === ownIdx, link = faculty ? mine : true;
                    var bar = '<span class="ad-rank-bar"><span class="ad-rank-fill" style="width:' + Math.max(1, Number(r.bar) || 0).toFixed(2) + '%;">'
                        + [1, 2, 3].map(function (s) { return '<i style="width:' + (Number(r.scope_pct[s]) || 0).toFixed(2) + '%;background:' + M[s].color + ';"></i>'; }).join('') + '</span></span>';
                    return '<tr class="ad-tr' + (link ? ' is-link' : '') + (mine ? ' is-own' : '') + '"' + (link ? ' data-go="rank" data-i="' + i + '" tabindex="0"' : '') + '>'
                        + '<td class="ad-c"><span class="ad-rank-no' + (r.rank <= 3 ? ' is-top' : '') + '">' + Number(r.rank) + '</span></td>'
                        + '<td><span class="ad-name">' + esc(r.name) + (mine ? ' <span class="ad-own-tag">คณะของฉัน</span>' : '') + '</span>' + bar + '</td>'
                        + '<td class="ad-r ad-num">' + fmt(r.total, 2) + '</td><td class="ad-r ad-muted">' + fmt(r.share, 1) + '%</td>' + goCell(link) + '</tr>';
                });
                var st = faculty
                    ? [{ label: 'อันดับของคณะ', value: own ? own.rank + ' / ' + rows.length : '-' }, { label: 'สัดส่วนของคณะ', value: own ? fmt(own.share, 1) + '%' : '-' }, { label: 'รวมทุกหน่วยงาน (tCO₂e)', value: fmt(sumAll, 2) }]
                    : [{ label: 'หน่วยงานที่มีข้อมูล', value: rows.length + ' / ' + (D.affilTotal || rows.length) }, { label: 'ปล่อยมากที่สุด', value: rows[0] ? esc(rows[0].name) : '-' }, { label: 'รวมทุกหน่วยงาน (tCO₂e)', value: fmt(sumAll, 2) }];
                return stats(st) + '<p class="ad-rank-legend">' + [1, 2, 3].map(function (s) { return '<i style="--c:' + M[s].color + ';">ขอบเขต ' + s + '</i>'; }).join('')
                    + '<span>สีในแถบคือสัดส่วนขอบเขตของหน่วยงานนั้น · ไม่รวมแบบสอบถามและกิจกรรม</span></p>'
                    + search('ค้นหาคณะ/หน่วยงาน…')
                    + table([{ t: '#', cls: 'ad-c', w: '52px' }, { t: 'คณะ/หน่วยงาน' }, { t: 'tCO₂e', cls: 'ad-r', w: '110px' }, { t: 'สัดส่วน', cls: 'ad-r', w: '80px' }, { t: '', w: '40px' }], trs, 'ยังไม่มีข้อมูลการดำเนินงานในปีนี้');
            },
            go: function (n, i) {
                if (faculty) { push(itemsView(0)); return; }
                var r = rows[i]; if (r) push(affilYearView(Number(r.affil_id), r.name, D.year, D.yearLabel));
            }
        };
    }

    function countUp() {
        if (reduce()) return;
        d.querySelectorAll('.ad-page [data-count]').forEach(function (el) {
            var to = Number(el.dataset.count) || 0, digits = el.dataset.digits != null ? Number(el.dataset.digits) : 2, t0 = null;
            // ตัวเลขจริงจาก PHP อยู่ในหน้าแล้ว — เริ่มนับจาก 0 เมื่อเฟรมแรกมาถึงเท่านั้น (ไม่มีเฟรม = ค้างที่ค่าจริง ไม่ใช่ 0)
            w.requestAnimationFrame(function frame(ts) {
                if (!el.isConnected) return;
                if (t0 === null) t0 = ts;
                var p = Math.min(1, (ts - t0) / 900), e = 1 - Math.pow(1 - p, 3);
                el.textContent = fmt(to * e, digits);
                if (p < 1) w.requestAnimationFrame(frame);
            });
        });
    }
    /** กราฟประวัติ · animate = แท่งโตจาก 0 (วาดภาพสุดท้ายไว้ก่อน เฟรมแรกมาถึงค่อยเริ่ม — แท็บที่ไม่แสดงผลไม่ค้างที่ 0) */
    function drawHistory(animate) {
        var c = $('adHistory'); if (!c || !S.data || !w.drawGhgGroupedBars) return;
        var width = Math.max(240, Math.floor(c.parentNode.clientWidth));
        c.width = width; c.style.width = width + 'px';
        var M = S.data.scopeMeta;
        var groups = histGroups(S.data, S.hist), series = [1, 2, 3].map(function (s) { return { label: 'ขอบเขต ' + s, color: M[s].color }; });
        var draw = function (p) { w.drawGhgGroupedBars(c, groups, series, 'tCO₂e', { labels: true, progress: p }); };
        var token = S.histToken = {};
        draw(1);
        if (!animate || reduce()) return;
        var t0 = null;
        w.requestAnimationFrame(function frame(ts) {
            if (!c.isConnected || S.histToken !== token) return;   // สลับชุดข้อมูลซ้ำระหว่างแอนิเมชัน → ตัวใหม่วาดแทน
            if (t0 === null) t0 = ts;
            var p = Math.min(1, (ts - t0) / 700);
            draw(1 - Math.pow(1 - p, 3));
            if (p < 1) w.requestAnimationFrame(frame);
        });
    }

    w.adInit = function (data) {
        S.data = data;
        var modal = $('adModal');
        // ปีงบประมาณ
        var yd = $('adYear');
        if (yd) yd.addEventListener('dd:change', function (e) { if (data && String(e.detail.value) !== String(data.year)) location.href = '?year=' + encodeURIComponent(e.detail.value); });
        if (!data || !modal) return;

        // admin: ทั้งมหาวิทยาลัย (เรียก API เมื่อกดลึก) · faculty: Dashboard คณบดี เฉพาะคณะตัวเอง (ข้อมูลฝังในหน้า)
        var faculty = data.mode === 'faculty';
        var views = faculty
            ? { items: function () { return itemsView(0); }, scope: function (n) { return itemsView(n); }, removal: function () { return eventsView(true); }, events: function () { return eventsView(false); }, ranking: rankingView }
            : { breakdown: function () { return breakdownView(0); }, scope: breakdownView, removal: removalView, cumulative: cumulativeView, reports: reportsView, ranking: rankingView };
        d.querySelectorAll('.ad-page [data-ad-open]').forEach(function (b) {
            b.addEventListener('click', function () {
                var k = b.dataset.adOpen;
                if (views[k]) open(views[k](Number(b.dataset.scope)));
            });
        });
        d.querySelectorAll('.ad-page [data-ad-affil]').forEach(function (b) {
            b.addEventListener('click', function () { open(affilYearView(Number(b.dataset.adAffil), b.dataset.name, data.year, data.yearLabel)); });
        });
        d.querySelectorAll('.ad-page [data-ad-source]').forEach(function (b) {
            b.addEventListener('click', function () { open(sourceYearView(b.dataset.adSource, data.year, data.yearLabel)); });
        });

        // ภายในหน้าต่าง: ตัวดักตัวเดียว (องค์ประกอบใหม่ทุกครั้งที่ SPA โหลดหน้า จึงผูกใหม่ได้)
        var body = $('adModalBody');
        var goFrom = function (t) {
            var v = S.stack[S.stack.length - 1]; if (!v || !v.go) return;
            v.go(t.dataset.go, Number(t.dataset.i));
        };
        body.addEventListener('click', function (e) { var t = e.target.closest('[data-go]'); if (t && body.contains(t)) { e.preventDefault(); goFrom(t); } });
        body.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('tr[data-go]')) { e.preventDefault(); goFrom(e.target); } });
        body.addEventListener('input', function (e) {
            if (!e.target.matches('[data-ad-search]')) return;
            var q = e.target.value.trim().toLowerCase();
            body.querySelectorAll('.ad-table tbody tr').forEach(function (tr) { tr.hidden = q !== '' && tr.textContent.toLowerCase().indexOf(q) === -1; });
        });
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        $('adClose').addEventListener('click', close);
        $('adBack').addEventListener('click', back);
        if ($('adLightbox')) {   // Dashboard คณบดีไม่มีหน้าดูไฟล์หลักฐาน
            $('adLbClose').addEventListener('click', lbClose);
            $('adLbPrev').addEventListener('click', function () { lbStep(-1); });
            $('adLbNext').addEventListener('click', function () { lbStep(1); });
            $('adLightbox').addEventListener('click', function (e) { if (e.target.id === 'adLightbox') lbClose(); });
        }

        // ตัวดักระดับ document/window ผูกครั้งเดียว — เดิมผูกซ้ำทุกครั้งที่เข้าหน้า และค้างไว้ error ในหน้าอื่น
        if (!w.__adBound) {
            w.__adBound = true;
            d.addEventListener('keydown', function (e) {
                var lb = $('adLightbox'), m = $('adModal');
                if (lb && !lb.hidden) {
                    if (e.key === 'Escape') lbClose(); else if (e.key === 'ArrowLeft') lbStep(-1); else if (e.key === 'ArrowRight') lbStep(1);
                    return;
                }
                if (e.key === 'Escape' && m && m.classList.contains('open')) { if (S.stack.length > 1) back(); else close(); }
            });
        }
        // กราฟประวัติของคณบดี: สลับ คณะของฉัน / ทั้งมหาวิทยาลัย (องค์ประกอบใหม่ทุกครั้งที่ SPA โหลดหน้า จึงผูกใหม่ได้)
        S.hist = 'faculty';
        d.querySelectorAll('.ad-page [data-ad-hist]').forEach(function (b) {
            b.addEventListener('click', function () {
                var k = b.dataset.adHist; if (k === S.hist) return;
                S.hist = k;
                var seg = b.closest('.ad-seg'); if (seg) seg.dataset.on = k;
                d.querySelectorAll('.ad-page [data-ad-hist]').forEach(function (x) { var on = x.dataset.adHist === k; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
                d.querySelectorAll('.ad-page [data-hist-panel]').forEach(function (pn) {
                    pn.hidden = pn.dataset.histPanel !== k;
                    if (!pn.hidden) { pn.classList.remove('ad-swap'); void pn.offsetWidth; pn.classList.add('ad-swap'); }
                });
                drawHistory(true);
            });
        });
        countUp();
        drawHistory(true);
        // วาดกราฟใหม่เมื่อกล่องเปลี่ยนขนาด (ย่อจอ / ยุบเมนูข้าง) — สังเกตกล่องตรง ๆ ไม่ใช่ window resize
        if (S.ro) S.ro.disconnect();
        var hc = $('adHistory'), rt = null, lastW = hc ? hc.parentNode.clientWidth : 0;
        if (hc && w.ResizeObserver) {
            S.ro = new w.ResizeObserver(function () {
                var cw = hc.parentNode.clientWidth;
                if (Math.abs(cw - lastW) < 2) return;
                lastW = cw; clearTimeout(rt); rt = setTimeout(drawHistory, 120);
            });
            S.ro.observe(hc.parentNode);
        }
    };
})(typeof window !== 'undefined' ? window : this);
