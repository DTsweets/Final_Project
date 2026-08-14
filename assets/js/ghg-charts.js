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
      ctx.font = "700 " + Math.round(r * 0.28) + "px Sarabun, sans-serif";
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
    ctx.font = "500 11px Sarabun, sans-serif";
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

  /**
   * กราฟแท่งกลุ่ม: 1 กลุ่ม = 1 ปี, ในกลุ่มมีหลายแท่ง (ขอบเขต 1/2/3)
   *   drawGhgGroupedBars(canvas, [{label:'2567', values:[1.2, 0.4, 0.9]}, ...],
   *                      [{label:'ขอบเขต 1', color:'#F97316'}, ...], 'tCO₂e')
   * วาดพร้อมแกน Y + เส้นกริด เพื่อให้เทียบความสูงข้ามปีได้
   */
  w.drawGhgGroupedBars = function (canvas, groups, series, yLabel) {
    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);

    const padL = 56, padR = 12, padT = 14, padB = 34;
    const base = H - padB, plotW = W - padL - padR, plotH = base - padT;

    // สเกลแกน Y: ปัดขึ้นเป็นเลขกลมๆ ให้เส้นกริดอ่านง่าย
    let maxV = 0;
    groups.forEach(g => g.values.forEach(v => { if (+v > maxV) maxV = +v; }));
    if (maxV <= 0) maxV = 1;
    const rawStep = maxV / 4;
    const mag = Math.pow(10, Math.floor(Math.log10(rawStep)));
    const step = Math.ceil(rawStep / mag) * mag;
    const top = step * 4;

    // เส้นกริด + ตัวเลขแกน Y
    ctx.font = '500 11px Sarabun, sans-serif';
    ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
    for (let i = 0; i <= 4; i++) {
      const v = step * i, y = base - (v / top) * plotH;
      ctx.strokeStyle = i === 0 ? '#D1D5DB' : '#F1EFF4';
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(W - padR, y); ctx.stroke();
      ctx.fillStyle = '#9CA3AF';
      ctx.fillText(v.toLocaleString('th-TH', { maximumFractionDigits: 2 }), padL - 8, y);
    }

    // ชื่อแกน Y (หมุนแนวตั้ง)
    if (yLabel) {
      ctx.save();
      ctx.translate(12, padT + plotH / 2); ctx.rotate(-Math.PI / 2);
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = '#6B7280'; ctx.font = '600 11px Sarabun, sans-serif';
      ctx.fillText(yLabel, 0, 0);
      ctx.restore();
    }

    // แท่ง
    const n = groups.length || 1, m = series.length || 1;
    const gw = plotW / n;                                  // ความกว้างต่อกลุ่ม
    const bw = Math.max(4, Math.min(26, (gw * 0.62) / m));  // ความกว้างต่อแท่ง
    ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    groups.forEach((g, gi) => {
      const cx = padL + gw * gi + gw / 2;
      const startX = cx - (bw * m) / 2;
      g.values.forEach((v, si) => {
        const val = Number(v) || 0;
        const h = (val / top) * plotH;
        ctx.fillStyle = series[si].color || '#9CA3AF';
        if (h > 0) ctx.fillRect(startX + bw * si, base - h, bw - 2, h);
      });
      ctx.fillStyle = '#6B7280'; ctx.font = '500 11px Sarabun, sans-serif';
      ctx.fillText(String(g.label), cx, H - 12);
    });
  };
})(window);
