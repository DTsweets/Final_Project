/**
 * Unit Test — ตัวช่วยใน assets/js/admin-dashboard.js (ไม่แตะหน้าเว็บ)
 * รัน: node tests\admin_dashboard_js_test.js
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
function ck(name, ok, detail) {
  if (ok) { pass++; console.log('  [PASS] ' + name); }
  else    { fail++; console.log('  [FAIL] ' + name + (detail ? '\n         ' + detail : '')); }
}

const window = {};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/admin-dashboard.js', 'utf8'), { window });
const H = window.adHelpers;

ck('A1 esc: แท็ก / เครื่องหมายคำพูด / & ถูก escape, null → ว่าง',
  H.esc('<img src=x onerror="a">\'&') === '&lt;img src=x onerror=&quot;a&quot;&gt;&#39;&amp;' && H.esc(null) === '');
ck('A2 dash: 0 / ว่าง / ไม่ใช่ตัวเลข → "-" · ค่าอื่นจัดทศนิยม',
  H.dash(0, 2) === '-' && H.dash('', 2) === '-' && H.dash(null, 2) === '-' && H.dash('abc', 2) === '-' && H.dash(1234.5, 2) === '1,234.50');
ck('A3 pctOf: ยอดรวม 0 → 0 (ไม่หารศูนย์)', H.pctOf(5, 0) === 0 && H.pctOf(25, 100) === 25);
ck('A4 dmy: YYYY-MM-DD → DD/MM/YYYY, ค่าผิด → "-"', H.dmy('2569-03-07') === '07/03/2569' && H.dmy('') === '-' && H.dmy('7/3/2569') === '-');

const rows = H.colorize([
  { kind: 'faculty', name: 'ก', value: 10 }, { kind: 'survey', name: 'แบบสอบถาม', value: 3 },
  { kind: 'faculty', name: 'ข', value: 5 }, { kind: 'event', name: 'กิจกรรม', value: 0 }, { kind: 'faculty', name: 'ค', value: 0 },
]);
ck('A5 colorize: หน่วยงานไล่สีตามลำดับ, แบบสอบถามสีคงที่, ยอด 0 เป็นเทา, ไม่แก้ข้อมูลต้นฉบับ',
  rows[0].color === '#62368B' && rows[2].color === '#0EA5E9' && rows[1].color === '#7C3AED' && rows[3].color === '#E5E7EB' && rows[4].color === '#E5E7EB'
  && rows[0].name === 'ก', JSON.stringify(rows));
const many = H.colorize(Array.from({ length: 8 }, (_, i) => ({ kind: 'faculty', name: 'n' + i, value: 8 - i })));
const slices = H.pieSlices(many, 5);
ck('A6 pieSlices: 5 อันดับแรก + "อื่น ๆ" รวมที่เหลือ ผลรวมเท่าเดิม · ไม่มีส่วนเกิน → ไม่มี "อื่น ๆ"',
  slices.length === 6 && slices[5].name === 'อื่น ๆ' && slices[5].value === 3 + 2 + 1
  && slices.reduce((s, x) => s + x.value, 0) === 36 && H.pieSlices(many.slice(0, 3), 5).every(x => !x.other));
const g = H.groupBy([{ k: 'a', v: 1 }, { k: 'b', v: 2 }, { k: 'a', v: 3 }], r => r.k);
ck('A7 groupBy: คงลำดับที่พบครั้งแรก และรวมรายการในกลุ่ม', g.length === 2 && g[0].key === 'a' && g[0].items.length === 2 && g[1].first.v === 2);
ck('A8 safeUrl: รับเฉพาะ http(s) — javascript: / data: → ว่าง',
  H.safeUrl('https://x.com/a') === 'https://x.com/a' && H.safeUrl('javascript:alert(1)') === '' && H.safeUrl(' data:text/html,x') === '' && H.safeUrl(null) === '');
const fr = H.facultyRows([
  { scope: 1, name: 'ดีเซล', unit: 'ลิตร', vol: 0, emission: 0, source: 'officer' },
  { scope: 2, name: 'ไฟฟ้า', unit: 'kWh', vol: 900, emission: 5, source: 'officer' },
  { scope: 0, name: 'กิจกรรมที่คณะจัด (รวมทุกกิจกรรม)', unit: '-', vol: null, emission: 1, source: 'event_total' },
  { scope: 3, name: 'แบบสอบถาม (รวมทุกชุด)', unit: '-', vol: null, emission: 5, source: 'survey_total' },
  { scope: 1, name: 'LPG', unit: 'kg', vol: 0, emission: 0, source: 'officer' },
]);
ck('A9 facultyRows: แถวรวมกิจกรรม/แบบสอบถาม → kind event/survey, เรียงยอดมาก → น้อย, ยอดเท่ากันคงลำดับเดิม, ว่าง → []',
  fr.map(r => r.name).join('|') === 'ไฟฟ้า|แบบสอบถาม (รวมทุกชุด)|กิจกรรมที่คณะจัด (รวมทุกกิจกรรม)|ดีเซล|LPG'
  && fr[1].kind === 'survey' && fr[2].kind === 'event' && fr[0].kind === 'item' && fr[0].value === 5 && fr[2].scope === 0
  && H.facultyRows(null).length === 0, JSON.stringify(fr));
const src = fs.readFileSync(__dirname + '/../assets/js/admin-dashboard.js', 'utf8');
const facultySrc = src.slice(src.indexOf('function itemsView'), src.indexOf('function countUp'));
ck('A10 adInit: โหมด faculty มีมุมมอง items/scope/removal/events ไม่เรียก API · admin ยังมีมุมมองเดิมครบ · หน้าที่ไม่มี lightbox ไม่ error',
  /\? \{ items: .*scope: .*removal: .*events: /.test(src)
  && src.includes(': { breakdown: function () { return breakdownView(0); }, scope: breakdownView, removal: removalView, cumulative: cumulativeView, reports: reportsView')
  && facultySrc.length > 0 && !facultySrc.includes('getJSON(')
  && src.includes("if ($('adLightbox')) {"));
const hd = { history: [{ year: '2568', s1: '1.5', s2: 0, s3: null }], uniHistory: [{ year: '2568', s1: 10, s2: 2, s3: 3 }] };
ck('A11 histGroups: คณะ/ทั้งมหาวิทยาลัยเลือกชุดถูก, ค่าเป็นตัวเลข, ไม่มี uniHistory (admin) → ใช้ history, ไม่มีข้อมูล → []',
  JSON.stringify(H.histGroups(hd, 'faculty')) === JSON.stringify([{ label: '2568', values: [1.5, 0, 0] }])
  && JSON.stringify(H.histGroups(hd, 'uni')) === JSON.stringify([{ label: '2568', values: [10, 2, 3] }])
  && H.histGroups({ history: hd.history }, 'uni')[0].values[0] === 1.5 && H.histGroups(null, 'uni').length === 0);
const charts = require(__dirname + '/../assets/js/ghg-charts.js');
const measure = t => t.length * 6;   // สมมุติตัวอักษรละ 6px
const plan = charts.ghgBarLabelPlan([{ values: [0, 0, 0] }, { values: [1.2, 0, 3] }, { values: [374.94, 8.55, 131.11] }], 26, measure);
ck('A12 ghgBarLabelPlan: ปีไม่มีข้อมูล → "ไม่มีข้อมูล" · ตัวเลขพอดีช่อง → เหนือแต่ละแท่ง (แท่ง 0 ว่าง) · ไม่พอ → ยอดรวมของปี',
  plan[0].mode === 'empty' && plan[0].text === 'ไม่มีข้อมูล'
  && plan[1].mode === 'bars' && plan[1].text[0] === '1.20' && plan[1].text[1] === '' && plan[1].text[2] === '3.00'
  && plan[2].mode === 'total' && plan[2].text === 'รวม 514.60' && Math.abs(plan[2].total - 514.6) < 1e-9
  && charts.ghgBarLabelPlan(null, 26, measure).length === 0, JSON.stringify(plan));
ck('A13 หน้าต่างอันดับ (ranking) ทั้งคณบดีและ admin · admin กดแถวไปรายการของหน่วยงาน คณบดีกดได้เฉพาะคณะตัวเอง · ปุ่มสลับกราฟ · กราฟมีตัวเลขทั้งสองหน้า + แท่งโตแบบแอนิเมชัน (ปิดได้)',
  src.includes('removal: function () { return eventsView(true); }, events: function () { return eventsView(false); }, ranking: rankingView }')
  && src.includes('cumulative: cumulativeView, reports: reportsView, ranking: rankingView }')
  && src.includes("link = faculty ? mine : true") && src.includes('if (faculty) { push(itemsView(0)); return; }')
  && src.includes('push(affilYearView(Number(r.affil_id), r.name, D.year, D.yearLabel))')
  && src.includes("d.querySelectorAll('.ad-page [data-ad-hist]')") && src.includes("'tCO₂e', { labels: true, progress: p })")
  && src.includes('if (!animate || reduce()) return;'));

console.log(`\n==== PASS=${pass}  FAIL=${fail} ====`);
process.exit(fail ? 1 : 0);
