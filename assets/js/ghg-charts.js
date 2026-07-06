/**
 * ghg-charts.js — canvas chart helpers ใช้ร่วม (admin / dean)
 * ไม่พึ่ง library ภายนอก (วาดด้วย Canvas 2D เอง)
 *
 *   drawGhgDonut(canvas, [{label, value, color}, ...], centerText)
 *   drawGhgBars(canvas, [{label, value, color}, ...])
 */
(function (w) {
  function _sum(d) { return d.reduce((s, x) => s + (Number(x.value) || 0), 0); }

  w.drawGhgDonut = function (canvas, data, centerText) {
    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);
    const cx = W / 2, cy = H / 2, r = Math.min(W, H) / 2 - 8, inner = r * 0.62;
    const total = _sum(data);
    if (total <= 0) {
      ctx.fillStyle = '#E5E7EB';
      ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.arc(cx, cy, inner, 0, Math.PI * 2, true); ctx.fill();
    } else {
      let a = -Math.PI / 2;
      data.forEach(seg => {
        const v = Number(seg.value) || 0;
        if (v <= 0) return;
        const slice = (v / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, a, a + slice);
        ctx.closePath();
        ctx.fillStyle = seg.color || '#9CA3AF';
        ctx.fill();
        a += slice;
      });
      // เจาะรูตรงกลางเป็น donut
      ctx.globalCompositeOperation = 'destination-out';
      ctx.beginPath(); ctx.arc(cx, cy, inner, 0, Math.PI * 2); ctx.fill();
      ctx.globalCompositeOperation = 'source-over';
    }
    if (centerText) {
      ctx.fillStyle = '#374151';
      ctx.font = "700 " + Math.round(r * 0.28) + "px Kanit, sans-serif";
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(centerText, cx, cy);
    }
  };

  w.drawGhgBars = function (canvas, data) {
    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);
    const pad = 34, base = H - pad, top = 14;
    const max = Math.max(1, ...data.map(d => Number(d.value) || 0));
    const n = data.length || 1;
    const gap = 16;
    const bw = Math.max(10, (W - pad - gap * n) / n);
    let x = pad + gap / 2;
    ctx.font = "500 11px Kanit, sans-serif";
    ctx.textAlign = 'center';
    data.forEach(d => {
      const v = Number(d.value) || 0;
      const h = (v / max) * (base - top);
      ctx.fillStyle = d.color || '#62368B';
      ctx.fillRect(x, base - h, bw, h);
      ctx.fillStyle = '#6B7280';
      ctx.fillText(String(d.label), x + bw / 2, H - 12);
      ctx.fillStyle = '#374151';
      ctx.fillText(v.toLocaleString('th-TH', { maximumFractionDigits: 0 }), x + bw / 2, base - h - 6);
      x += bw + gap;
    });
    // แกน
    ctx.strokeStyle = '#E5E7EB'; ctx.beginPath();
    ctx.moveTo(pad, base); ctx.lineTo(W - 6, base); ctx.stroke();
  };
})(window);
