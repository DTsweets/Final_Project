/**
 * Session Timer — Auto logout countdown
 * ----------------------------------------
 * อ่านเวลาที่เหลือจาก PHP และ countdown
 * เมื่อเหลือน้อย → แสดง warning
 * เมื่อหมด → redirect login.php
 */

(function () {
    'use strict';

    const timerEl = document.getElementById('session-timer');
    if (!timerEl) return;

    // เวลาที่เหลือ (วินาที) จาก PHP data attribute
    let remaining = parseInt(timerEl.dataset.remaining || '3600', 10);
    const rootPath = timerEl.dataset.root || '';

    function formatTime(sec) {
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    }

    function tick() {
        remaining--;

        if (remaining <= 0) {
            // หมดเวลา → ออกจากระบบ
            window.location.href = rootPath + '/logout.php?timeout=1';
            return;
        }

        // อัพเดทข้อความ
        const timeEl = timerEl.querySelector('.timer-value');
        if (timeEl) timeEl.textContent = formatTime(remaining);

        // warning เมื่อเหลือน้อยกว่า 5 นาที
        if (remaining <= 300) {
            timerEl.classList.add('danger');
        } else {
            timerEl.classList.remove('danger');
        }

        // แสดง modal warning เมื่อเหลือ 2 นาที (1 ครั้ง)
        if (remaining === 120 && !timerEl.dataset.warned) {
            timerEl.dataset.warned = '1';
            showWarningModal(remaining);
        }

        setTimeout(tick, 1000);
    }

    function showWarningModal(sec) {
        // สร้าง overlay warning
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position: fixed; inset: 0; z-index: 9999;
            background: rgba(0,0,0,0.7); backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            animation: fadeIn 0.3s ease;
        `;
        overlay.innerHTML = `
            <div style="
                background: #1a1a35; border: 1px solid rgba(239,68,68,0.3);
                border-radius: 20px; padding: 2rem 2.5rem; max-width: 420px; width: 90%;
                text-align: center; box-shadow: 0 25px 50px rgba(0,0,0,0.5);
            ">
                <div style="font-size: 3rem; margin-bottom: 1rem;">⏰</div>
                <h3 style="color: #fca5a5; font-size: 1.2rem; margin-bottom: 0.5rem; font-family: \'Kanit\', sans-serif;">
                    Session ใกล้หมดอายุ
                </h3>
                <p style="color: rgba(241,245,249,0.7); font-size: 0.875rem; margin-bottom: 1.5rem; font-family: \'Kanit\', sans-serif;">
                    คุณจะถูกออกจากระบบอัตโนมัติใน <strong style="color:#fca5a5;"> 2 นาที</strong> <br>
                    กดปุ่มด้านล่างเพื่อต่ออายุ session
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button id="extend-session" style="
                        padding: 0.65rem 1.5rem; border: none; border-radius: 10px;
                        background: linear-gradient(135deg, #4f46e5, #7c3aed);
                        color: #fff; font-weight: 600; cursor: pointer;
                        font-family: \'Kanit\', sans-serif; font-size: 0.9rem;
                    ">ต่ออายุ Session</button>
                    <button id="logout-now" style="
                        padding: 0.65rem 1.5rem; border: 1px solid rgba(239,68,68,0.3);
                        border-radius: 10px; background: transparent;
                        color: #fca5a5; font-weight: 600; cursor: pointer;
                        font-family: \'Kanit\', sans-serif; font-size: 0.9rem;
                    ">ออกจากระบบ</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        document.getElementById('extend-session').addEventListener('click', function () {
            // Ping server เพื่อ refresh session
            fetch(rootPath + '/includes/ping.php', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.remaining) {
                        remaining = data.remaining;
                        timerEl.dataset.warned = '';
                    }
                })
                .catch(() => {});
            overlay.remove();
        });

        document.getElementById('logout-now').addEventListener('click', function () {
            window.location.href = rootPath + '/logout.php';
        });
    }

    // เริ่ม countdown
    setTimeout(tick, 1000);
})();
