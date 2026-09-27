/**
 * ขนาดหน้าเว็บตามความกว้างจอ (ผู้ใช้กำหนด 28 ก.ย. 2569)
 * ------------------------------------------------------------------
 * ต้องการให้ทุกจอเห็น "สัดส่วนเดียวกัน" กับจอ 1536px ที่ตั้งไว้ 90%
 *   จอ 1536 → 90%  ·  จอ 1920 → ชนเพดาน 105%  ·  จอ 1366 → 80%  ·  จอ 1280 และแคบกว่า → 75%
 * เพดานบน-ล่างกันไม่ให้จอใหญ่มากดูเยอะเกินไป และจอเล็กมากตัวหนังสือเล็กจนอ่านไม่ออก
 *
 * ตั้งค่าเป็น 2 ที่เสมอ: --app-zoom (ให้ CSS เอาไปหาร 100vh) และ zoom ของ :root
 * ถ้าปิด JS ยังได้ 90% คงที่จาก assets/css/admin.css · login.css (ค่าตั้งต้นในไฟล์ CSS)
 * เทสต์: tests/app_zoom_test.js · tests/zoom_default_test.php
 */
(function (w, d) {
    var MIN = 0.75;      // จอแคบ: ไม่ย่อเกินนี้ (ตัวหนังสือเล็กเกินไป)
    var MAX = 1.05;      // จอกว้าง: ไม่ขยายเกินนี้ (เนื้อหาห่างจนดูโล่ง)
    var REF = 1707;      // จุดอ้างอิง: 1536 / 1707 = 0.90 → จอของผู้ใช้ได้ 90% เท่าเดิม

    /** ค่าซูมของความกว้างจอหนึ่ง ๆ (ปัดทศนิยม 2 ตำแหน่ง กันค่าเปลี่ยนถี่ตอนลากขอบหน้าต่าง) */
    function appZoomFor(width) {
        var z = Math.round((Number(width) || 0) / REF * 100) / 100;
        return Math.min(MAX, Math.max(MIN, z));
    }

    /**
     * ความกว้างจอจริง (หน่วยจุดภาพของอุปกรณ์)
     * documentElement.clientWidth ไม่ถูกย่อตาม zoom จึงอ่านซ้ำได้โดยค่าไม่วิ่งไปเรื่อย ๆ
     */
    function viewportWidth() {
        return (d.documentElement && d.documentElement.clientWidth) || w.innerWidth || 0;
    }

    function apply() {
        var width = viewportWidth();
        if (width <= 0) return;                           // แท็บซ่อนอยู่/ยังไม่วางเลย์เอาต์ — ปล่อยให้ใช้ค่าตั้งต้นใน CSS ไปก่อน
        var z = appZoomFor(width);
        var root = d.documentElement;
        if (root.style.zoom === String(z)) return;        // ไม่เปลี่ยน = ไม่ต้องสั่ง reflow
        root.style.setProperty('--app-zoom', z);
        root.style.zoom = z;
    }

    w.appZoomFor = appZoomFor;                            // เผื่อหน้าอื่น/เทสต์เรียกใช้
    if (typeof module !== 'undefined' && module.exports) module.exports = { appZoomFor: appZoomFor, MIN: MIN, MAX: MAX, REF: REF };

    if (d.documentElement && w.addEventListener) {        // ในเบราว์เซอร์เท่านั้น (เทสต์ฝั่ง Node เรียกแค่ appZoomFor)
        apply();                                          // ทำทันทีตั้งแต่ยังโหลดไม่เสร็จ กันภาพกระโดด
        w.addEventListener('resize', apply);
        w.addEventListener('load', apply);                // เผื่อตอนสคริปต์ทำงานยังวัดความกว้างไม่ได้ (แท็บซ่อน/เปิดในกรอบ)
        w.addEventListener('pageshow', apply);            // กลับมาจากแคช back/forward
    }
})(typeof window !== 'undefined' ? window : globalThis, typeof document !== 'undefined' ? document : { documentElement: null });
