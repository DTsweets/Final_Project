<?php
/**
 * Toast Notification (มุมขวาล่าง + icon + progress bar + auto-dismiss)
 * component กลาง — ใช้ได้ทุกหน้า (วางไว้ก่อนปิด </main> หรือที่ไหนก็ได้)
 * ผู้เรียกกำหนดก่อน include:
 *   $toast_msg  = ข้อความ (ถ้าว่าง = ไม่แสดง)
 *   $toast_type = 'success' | 'danger'
 */
$toast_msg  = $toast_msg ?? '';
$toast_type = (($toast_type ?? 'success') === 'danger') ? 'danger' : 'success';
if ($toast_msg !== ''):
    $t_danger = ($toast_type === 'danger');
    $t_accent = $t_danger ? '#EF4444' : '#10B981';
    $t_bg     = $t_danger ? '#FEE2E2' : '#D1FAE5';
    $t_head   = $t_danger ? 'เกิดข้อผิดพลาด' : 'สำเร็จ';
?>
<div id="toast-notification" style="position:fixed;bottom:28px;right:28px;z-index:999999;min-width:320px;max-width:420px;background:#FFFFFF;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.15),0 4px 16px rgba(0,0,0,0.08);overflow:hidden;animation:toastSlideIn 0.45s cubic-bezier(0.16,1,0.3,1);border-left:5px solid <?= $t_accent ?>;">
    <div style="display:flex;align-items:flex-start;gap:14px;padding:18px 18px 14px 18px;">
        <div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:<?= $t_bg ?>;color:<?= $t_accent ?>;">
            <?php if ($t_danger): ?>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" /><line x1="15" y1="9" x2="9" y2="15" /><line x1="9" y1="9" x2="15" y2="15" /></svg>
            <?php else: ?>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" /><path d="M9 12l2 2 4-4" /></svg>
            <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0;">
            <p style="margin:0 0 2px 0;font-size:0.8rem;font-weight:700;color:#9CA3AF;text-transform:uppercase;letter-spacing:0.05em;"><?= $t_head ?></p>
            <p style="margin:0;font-size:0.95rem;font-weight:600;color:#1F2937;line-height:1.4;"><?= htmlspecialchars($toast_msg) ?></p>
        </div>
        <button onclick="closeToast()" style="background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:1.3rem;line-height:1;padding:0;flex-shrink:0;transition:color 0.2s;" onmouseenter="this.style.color='#374151'" onmouseleave="this.style.color='#9CA3AF'">&times;</button>
    </div>
    <div id="toast-progress" style="height:3px;background:<?= $t_accent ?>;width:100%;transform-origin:left;animation:toastProgress 4s linear forwards;"></div>
</div>
<style>
    @keyframes toastSlideIn { from { opacity:0; transform:translateX(60px) scale(0.95); } to { opacity:1; transform:translateX(0) scale(1); } }
    @keyframes toastSlideOut { from { opacity:1; transform:translateX(0) scale(1); } to { opacity:0; transform:translateX(60px) scale(0.95); } }
    @keyframes toastProgress { from { width:100%; } to { width:0%; } }
</style>
<script>
    var _toastTimer = setTimeout(function () { closeToast(); }, 4000);
    function closeToast() {
        clearTimeout(_toastTimer);
        var t = document.getElementById('toast-notification');
        if (t) {
            t.style.animation = 'toastSlideOut 0.35s cubic-bezier(0.16,1,0.3,1) forwards';
            setTimeout(function () { if (t) t.remove(); }, 350);
        }
    }
</script>
<?php endif; ?>
