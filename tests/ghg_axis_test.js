/**
 * Unit Test — สเกลแกน Y ของกราฟ (ghgNiceAxis)
 * รัน: node tests\ghg_axis_test.js
 *
 * ล็อกกฎ:
 *   A. เส้นกริดต้องเป็นเลขกลม (1/2/2.5/5/10 คูณกำลังสิบ)
 *      บั๊กเดิม: 34,257 ได้ step 9,000 → แกนอ่านเป็น 0/9,000/18,000/27,000/36,000
 *   B. แกนต้องครอบคลุมค่าสูงสุดเสมอ (ไม่มีแท่งทะลุกรอบ)
 *   C. กันเคสขอบ — 0, ติดลบ, NaN, ค่าน้อยมาก
 */
const { ghgNiceAxis } = require('../assets/js/ghg-charts.js');

let pass = 0, fail = 0;
function ck(name, ok, detail) {
  if (ok) { pass++; console.log('  [PASS] ' + name); }
  else    { fail++; console.log('  [FAIL] ' + name + (detail ? '\n         ' + detail : '')); }
}
const NICE = [1, 2, 2.5, 5, 10];
function isNice(step) {
  if (!(step > 0)) return false;
  const mag = Math.pow(10, Math.floor(Math.log10(step)));
  const n = step / mag;
  return NICE.some(x => Math.abs(x - n) < 1e-9);
}

console.log('A. เส้นกริดเป็นเลขกลม');
[
  [34257, 10000, 40000, 'ค่าจริงที่ทำให้เจอบั๊ก (ปี 2568)'],
  [46.32,    20,    80, 'ยอดปี 2569 หลังลบข้อมูลขยะ'],
  [1,      0.25,     1, 'ค่าน้อยมาก'],
  [100,      25,   100, 'ค่ากลม 100'],
  [1000,    250,  1000, 'ค่ากลม 1000'],
].forEach(([input, step, top, note]) => {
  const a = ghgNiceAxis(input, 4);
  ck(`A1 max=${input} → step=${step} top=${top}  (${note})`,
     a.step === step && a.top === top, `ได้ step=${a.step} top=${a.top}`);
});

console.log('\nA2 สุ่ม 500 ค่า — step ต้องเป็นเลขกลมเสมอ');
let bad = null;
for (let i = 0; i < 500; i++) {
  const v = Math.pow(10, (i % 100) / 12) * (1 + (i % 9));
  const a = ghgNiceAxis(v, 4);
  if (!isNice(a.step)) { bad = `max=${v} → step=${a.step}`; break; }
}
ck('A2 step เป็นเลขกลมทุกเคส', bad === null, bad || '');

console.log('\nB. แกนครอบคลุมค่าสูงสุด (ไม่มีแท่งทะลุกรอบ)');
bad = null;
for (let i = 0; i < 500; i++) {
  const v = Math.pow(10, (i % 100) / 12) * (1 + (i % 9));
  const a = ghgNiceAxis(v, 4);
  if (a.top < v - 1e-9) { bad = `max=${v} → top=${a.top} (เตี้ยกว่าข้อมูล)`; break; }
}
ck('B1 top >= ค่าสูงสุดเสมอ', bad === null, bad || '');

// แกนต้องไม่สูงเกินจำเป็น — ไม่งั้นแท่งเตี้ยจนดูไม่ออก (top ไม่ควรเกิน 2.5 เท่าของข้อมูล)
bad = null;
for (let i = 0; i < 500; i++) {
  const v = Math.pow(10, (i % 100) / 12) * (1 + (i % 9));
  const a = ghgNiceAxis(v, 4);
  if (a.top > v * 2.5 + 1e-9) { bad = `max=${v} → top=${a.top} (สูงเกิน 2.5 เท่า)`; break; }
}
ck('B2 top ไม่สูงเกิน 2.5 เท่าของข้อมูล', bad === null, bad || '');

console.log('\nC. เคสขอบ');
ck('C1 max=0 → ไม่หารด้วยศูนย์', ghgNiceAxis(0, 4).top > 0, JSON.stringify(ghgNiceAxis(0, 4)));
ck('C2 max ติดลบ → ไม่พัง',      ghgNiceAxis(-5, 4).top > 0, JSON.stringify(ghgNiceAxis(-5, 4)));
ck('C3 max=NaN → ไม่พัง',        ghgNiceAxis(NaN, 4).top > 0, JSON.stringify(ghgNiceAxis(NaN, 4)));
ck('C4 divisions ไม่ระบุ → ใช้ 4', ghgNiceAxis(100).top === ghgNiceAxis(100, 4).top);
ck('C5 divisions=5 → ได้ 5 ช่อง',  ghgNiceAxis(34257, 5).top / ghgNiceAxis(34257, 5).step === 5);

console.log('\n─────────────────────────\nผ่าน ' + pass + ' | ไม่ผ่าน ' + fail);
process.exit(fail > 0 ? 1 : 0);
