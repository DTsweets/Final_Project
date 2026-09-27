/**
 * Unit Test — ขนาดหน้าเว็บตามความกว้างจอ (assets/js/app-zoom.js)
 * รัน: node tests\app_zoom_test.js
 *
 * ล็อกกฎ (ผู้ใช้กำหนด 28 ก.ย. 2569):
 *   A1 จอ 1536 (จอของผู้ใช้) ต้องได้ 0.90 เท่าเดิม — ค่าที่ตกลงกันไว้ห้ามเพี้ยน
 *   A2 จอกว้างกว่าได้มากขึ้น จอแคบกว่าได้น้อยลง (ไม่ลดหลั่นสลับกัน)
 *   A3 มีเพดานบน 1.05 และล่าง 0.75 — จอใหญ่มาก/เล็กมากไม่หลุดกรอบ
 *   A4 ค่าที่ได้ปัดทศนิยม 2 ตำแหน่ง (ลากขอบหน้าต่างแล้วไม่สั่น) และไม่พังเมื่อได้ค่าประหลาด
 */
const path = require('path');
const { appZoomFor, MIN, MAX, REF } = require(path.join(__dirname, '..', 'assets', 'js', 'app-zoom.js'));

let pass = 0, fail = 0;
const ck = (name, ok, detail = '') => {
    if (ok) { pass++; console.log(`  [PASS] ${name}`); }
    else { fail++; console.log(`  [FAIL] ${name}${detail ? `\n         ${detail}` : ''}`); }
};

ck('A1 จอ 1536 ได้ 0.90 (ขนาดที่ผู้ใช้ตกลงไว้)', appZoomFor(1536) === 0.9, String(appZoomFor(1536)));

const widths = [1280, 1366, 1440, 1536, 1600, 1680, 1920];
const zooms = widths.map(appZoomFor);
ck('A2 จอกว้างขึ้น ขนาดไม่เล็กลง (เรียงไม่ลดหลั่นสลับกัน)',
    zooms.every((z, i) => i === 0 || z >= zooms[i - 1]), widths.map((w, i) => `${w}:${zooms[i]}`).join(' '));

ck('A3 เพดานบน/ล่างทำงาน (จอ 3840 ไม่เกิน MAX · จอ 800 ไม่ต่ำกว่า MIN)',
    appZoomFor(3840) === MAX && appZoomFor(800) === MIN && MIN === 0.75 && MAX === 1.05);
ck('A3b จุดอ้างอิงยังเป็น 1707 (1536 / 1707 = 0.90)', REF === 1707 && Math.round(1536 / REF * 100) / 100 === 0.9);

ck('A4 ปัดทศนิยม 2 ตำแหน่ง (ค่าไม่สั่นตอนลากขอบหน้าต่าง)',
    [1500, 1501, 1502, 1600, 1601].every(w => {
        const z = appZoomFor(w);
        return Math.round(z * 100) === z * 100;
    }));
ck('A4b ค่าประหลาดไม่ทำให้พัง (0 / ติดลบ / ไม่ใช่ตัวเลข → ค่าต่ำสุด)',
    appZoomFor(0) === MIN && appZoomFor(-100) === MIN && appZoomFor('abc') === MIN && appZoomFor(undefined) === MIN);

console.log(`\n==== PASS=${pass}  FAIL=${fail} ====`);
process.exit(fail ? 1 : 0);
