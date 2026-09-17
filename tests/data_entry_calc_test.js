/**
 * Unit Test — คำนวณ/ตรวจค่าบนหน้ากรอกปริมาณ (assets/js/data-entry.js)
 * รัน: node tests\data_entry_calc_test.js
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
function ck(name, ok, detail) {
  if (ok) { pass++; console.log('  [PASS] ' + name); }
  else    { fail++; console.log('  [FAIL] ' + name + (detail ? '\n         ' + detail : '')); }
}

const window = {};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/data-entry.js', 'utf8'), { window });
const { deParseVol: parse, deRowEmission: emis, deFormat: fmt, deSummarize: sum } = window;

ck('D1 ว่าง = 0 ไม่ถือว่าผิด', parse('').value === 0 && parse('').error === null && parse('  ').error === null);
ck('D2 ตัวเลขปกติ / ทศนิยม / คั่นหลักพัน', parse('6140').value === 6140 && parse('0.25').value === 0.25 && parse('1,250.5').value === 1250.5);
ck('D3 ไม่ใช่ตัวเลข / ติดลบ / เกิน 1,000,000 → มีข้อความผิด',
  parse('abc').error !== null && parse('-1').error !== null && parse('1000001').error !== null && parse('1000000').error === null);
ck('D4 tCO₂e = ปริมาณ × EF ÷ 1000', Math.abs(emis(6140, 2.7076) - 16.624664) < 1e-9 && emis(5, 0) === 0);
ck('D5 แสดง 0 เป็น "-", ยอดรวมทศนิยม 2 ตำแหน่ง (ตรงกับ PHP ตอนโหลด), รายแถว 4 ตำแหน่ง',
  fmt(0) === '-' && fmt(486.7219) === (486.72).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  && fmt(16.6247, 4) === (16.6247).toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 }));

const s = sum([
  { group: '1', raw: '100', ef: 10 },   // 1 tCO₂e
  { group: '1', raw: '', ef: 10 },      // ยังไม่กรอก
  { group: '4', raw: '2,000', ef: 0.5 },// 1 tCO₂e
  { group: '4', raw: '-3', ef: 10 },    // ผิด — ไม่นับยอด ไม่นับว่ากรอก
]);
ck('D6 สรุปรวม: ยอด / กรอกแล้ว / จำนวน / ช่องผิด', Math.abs(s.total - 2) < 1e-9 && s.filled === 2 && s.count === 4 && s.errors === 1, JSON.stringify(s));
ck('D7 สรุปรายหมวด', s.groups['1'].filled === 1 && s.groups['1'].count === 2 && Math.abs(s.groups['4'].total - 1) < 1e-9 && s.groups['4'].filled === 1);

// แถวหลายช่อง (แบบสอบถาม: จำนวนผู้ตอบ × ค่าเฉลี่ย)
const { deRowValue: rv } = window;
ck('D8 แถวหลายช่อง: ค่า = ผลคูณ, ว่างทั้งแถว = 0 ไม่ผิด',
  rv({ raws: [{ raw: '120', int: true }, { raw: '2.5' }] }).value === 300 && rv({ raws: [{ raw: '' }, { raw: '' }] }).error === null);
ck('D9 กรอกไม่ครบทุกช่อง = ผิด (เซิร์ฟเวอร์ไม่บันทึกแถวที่ไม่ครบ)',
  rv({ raws: [{ raw: '120', int: true }, { raw: '' }] }).error !== null && rv({ raws: [{ raw: '' }, { raw: '0' }] }).error === null);
ck('D10 ช่องจำนวนเต็ม: ทศนิยม = ผิด, ช่องปกติรับทศนิยม',
  parse('10.5', { int: true }).error !== null && parse('10', { int: true }).error === null && parse('10.5').error === null);
const s2 = sum([{ group: 'survey', ef: 1000, raws: [{ raw: '10', int: true }, { raw: '0.5' }] }, { group: 'survey', ef: 1000, raws: [{ raw: '3', int: true }, { raw: '' }] }]);
ck('D11 สรุปแถวหลายช่อง: แถวไม่ครบนับเป็นช่องผิด ไม่นับยอด', s2.total === 5 && s2.filled === 1 && s2.errors === 1, JSON.stringify(s2));

// ผู้ตอบ × ค่าเฉลี่ย เก็บใน user_item.Vol decimal(13,4) ได้ไม่เกิน 999,999,999.9999 — เดิมเกินแล้วถูกตัดเงียบ การ์ดกับรายงานไม่ตรงกัน
ck('D12 แถวหลายช่อง: ผลคูณเกิน 999,999,999.9999 = ผิด · เท่าขอบบนยังผ่าน · แถวช่องเดียว 1,000,000 ไม่กระทบ',
  /999,999,999/.test(rv({ raws: [{ raw: '1000000', int: true }, { raw: '5000' }] }).error || '')
  && rv({ raws: [{ raw: '1000000', int: true }, { raw: '5000' }] }).over === true   // หน้าเว็บใช้ทำเครื่องหมายทุกช่องของแถวว่าผิด
  && !rv({ raws: [{ raw: '10', int: true }, { raw: '' }] }).over
  && rv({ raws: [{ raw: '1,000,000', int: true }, { raw: '999.9999' }] }).error === null
  && rv({ raws: [{ raw: '1000000', int: true }, { raw: '999.9999' }] }).value === 999999900
  && rv({ raw: '1000000' }).error === null);
const s3 = sum([{ group: 'survey', ef: 1, raws: [{ raw: '1000000', int: true }, { raw: '5000' }] }, { group: 'survey', ef: 1000, raws: [{ raw: '10', int: true }, { raw: '2' }] }]);
ck('D13 สรุป: แถวที่ผลคูณเกินนับเป็นช่องผิด ไม่นับยอด ไม่นับว่ากรอก', s3.errors === 1 && s3.filled === 1 && s3.total === 20, JSON.stringify(s3));

console.log(`\n==== PASS=${pass}  FAIL=${fail} ====`);
process.exit(fail ? 1 : 0);
