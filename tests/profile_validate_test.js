/**
 * Unit Test — ตรวจข้อมูลหน้าโปรไฟล์ฝั่งหน้าเว็บ (assets/js/profile.js) ต้องตรงกับ profile_validate ใน PHP
 * รัน: node tests\profile_validate_test.js
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
function ck(name, ok, detail) {
  if (ok) { pass++; console.log('  [PASS] ' + name); }
  else    { fail++; console.log('  [FAIL] ' + name + (detail ? '\n         ' + detail : '')); }
}

const window = {};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/profile.js', 'utf8'), { window });
const { pfValidate: v, pfCheckImage: img } = window;
const base = { firstname: 'สมชาย', lastname: 'ใจดี', username: 'somchai', email: '' };

ck('J1 ข้อมูลถูกต้อง ไม่เปลี่ยนรหัส / อีเมลว่าง → ไม่มีข้อผิดพลาด', Object.keys(v(base)).length === 0);
const e1 = v({ firstname: ' ', lastname: '', username: 'a b', email: 'bad' });
ck('J2 ชื่อ/นามสกุลว่าง, username มีช่องว่าง, อีเมลผิดรูปแบบ', e1.firstname && e1.lastname && e1.username === 'ชื่อผู้ใช้งานต้องไม่มีช่องว่าง' && e1.email, JSON.stringify(e1));
ck('J3 เปลี่ยนรหัส: ไม่ใส่รหัสเดิม / ยืนยันไม่ตรง / ยาวเกิน 50 / สั้นกว่า 8 / ใส่แค่ช่องยืนยัน',
  v({ ...base, password: 'n', password_confirm: 'n' }).old_password === 'กรุณากรอกรหัสผ่านเดิม'
  && v({ ...base, old_password: 'o', password: 'n', password_confirm: 'm' }).password_confirm === 'รหัสผ่านยืนยันไม่ตรงกัน'
  && v({ ...base, old_password: 'o', password: 'a'.repeat(51), password_confirm: 'a'.repeat(51) }).password
  && v({ ...base, old_password: 'o', password_confirm: 'x' }).password === 'กรุณากรอกรหัสผ่านใหม่'
  && v({ ...base, old_password: 'o', password: 'short7x', password_confirm: 'short7x' }).password === 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'
  && !v({ ...base, old_password: 'o', password: 'eight888', password_confirm: 'eight888' }).password);
ck('J4 เปลี่ยนรหัสถูกต้อง (50 ตัวพอดี) → ผ่าน', Object.keys(v({ ...base, old_password: 'o', password: 'a'.repeat(50), password_confirm: 'a'.repeat(50) })).length === 0);
ck('J5 รูป: ชนิดที่รองรับผ่าน / PDF หรือไฟล์อื่นไม่ผ่าน', img('image/png', 5000) === null && img('image/webp', 1) === null && img('application/pdf', 10) !== null && img('image/svg+xml', 10) !== null);

console.log(`\n==== PASS=${pass}  FAIL=${fail} ====`);
process.exit(fail ? 1 : 0);
