/**
 * ghg-charts.js — canvas chart helpers ใช้ร่วม (admin / dean)
 * ไม่พึ่ง library ภายนอก (วาดด้วย Canvas 2D เอง)
 *
 *   drawGhgDonut(canvas, [{label, value, color}, ...], centerText)
 *   drawGhgBars(canvas, [{label, value, color}, ...])
 *   drawGhgGroupedBars(canvas, groups, series, yLabel, opts)  — opts.labels = ตัวเลขกำกับแท่ง, opts.progress = แอนิเมชัน
 */
(function (w) {
  function _sum(d) { return d.reduce((s, x) => s + (Number(x.value) || 0), 0); }

  /**
   * คำนวณสเกลแกน Y ให้เส้นกริดเป็นเลขกลม
   * ------------------------------------------------------------------
   * เดิมใช้ Math.ceil(rawStep / mag) * mag ซึ่งได้ step แปลก ๆ เช่น 9,000
   * → แกนอ่านเป็น 0 / 9,000 / 18,000 / 27,000 / 36,000 ซึ่งเทียบค่าในใจยาก
   * ตอนนี้ปัดขึ้นหาชุดเลขกลมมาตรฐาน 1 / 2 / 2.5 / 5 / 10 (คูณกำลังสิบ)
   * → ได้ 0 / 10,000 / 20,000 / 30,000 / 40,000 แทน
   *
   * @param {number} maxV      ค่าสูงสุดในชุดข้อมูล
   * @param {number} divisions จำนวนช่วงของแกน (ค่าเริ่มต้น 4 = เส้นกริด 5 เส้น)
   * @returns {{step:number, top:number}} ระยะห่างต่อช่อง และค่าสูงสุดของแกน
   */
  function ghgNiceAxis(maxV, divisions) {
    const div = divisions > 0 ? divisions : 4;
    const v = Number(maxV);
    if (!isFinite(v) || v <= 0) return { step: 1, top: div };

    const raw = v / div;
    const mag = Math.pow(10, Math.floor(Math.log10(raw)));
    const norm = raw / mag;                                  // อยู่ในช่วง 1 ถึง <10 เสมอ
    const mul = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10;
    const step = mul * mag;
    return { step: step, top: step * div };
  }
  w.ghgNiceAxis = ghgNiceAxis;

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

  function ghgFmt(v) { return (Number(v) || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  /**
   * ตัวเลขกำกับแท่งของแต่ละกลุ่ม (ไม่แตะ canvas — เทสต์ได้ด้วย node)
   *   empty = ผลรวมกลุ่มเป็น 0 → เขียน "ไม่มีข้อมูล"
   *   bars  = ตัวเลขทุกแท่งกว้างไม่เกินช่องของแท่ง → เขียนเหนือแต่ละแท่ง
   *   total = ตัวเลขกว้างเกินช่อง (ทับกัน) → เขียนยอดรวมของกลุ่มเหนือแท่งที่สูงสุดแทน
   *
   * @param {Array}    groups  [{label, values:[...]}, ...]
   * @param {number}   slotW   ความกว้างที่ตัวเลขหนึ่งตัวใช้ได้ (px)
   * @param {Function} measure (text) => ความกว้าง px
   * @returns {Array} [{mode:'empty'|'bars'|'total', total:number, text:string|string[]}, ...]
   */
  function ghgBarLabelPlan(groups, slotW, measure) {
    return (groups || []).map(function (g) {
      const vals = (g.values || []).map(v => Number(v) || 0);
      const total = vals.reduce((s, v) => s + v, 0);
      if (total <= 0) return { mode: 'empty', total: 0, text: 'ไม่มีข้อมูล' };
      const texts = vals.map(v => v > 0 ? ghgFmt(v) : '');
      const fits = texts.every(t => t === '' || measure(t) <= slotW);
      return fits ? { mode: 'bars', total: total, text: texts } : { mode: 'total', total: total, text: 'รวม ' + ghgFmt(total) };
    });
  }
  w.ghgBarLabelPlan = ghgBarLabelPlan;

  /**
   * กราฟแท่งกลุ่ม: 1 กลุ่ม = 1 ปี, ในกลุ่มมีหลายแท่ง (ขอบเขต 1/2/3)
   *   drawGhgGroupedBars(canvas, [{label:'2567', values:[1.2, 0.4, 0.9]}, ...],
   *                      [{label:'ขอบเขต 1', color:'#F97316'}, ...], 'tCO₂e', {labels:true, progress:1})
   * วาดพร้อมแกน Y + เส้นกริด เพื่อให้เทียบความสูงข้ามปีได้
   * opts (ไม่ส่ง = แบบเดิม): labels = เขียนตัวเลขกำกับแท่ง / "ไม่มีข้อมูล" · progress 0–1 = ความสูงแท่งระหว่างแอนิเมชัน
   */
  w.drawGhgGroupedBars = function (canvas, groups, series, yLabel, opts) {
    if (!canvas || !canvas.getContext) return;
    const o = opts || {};
    const prog = o.progress == null ? 1 : Math.max(0, Math.min(1, o.progress));
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);

    const padL = 56, padR = 12, padT = o.labels ? 26 : 14, padB = 34;
    const base = H - padB, plotW = W - padL - padR, plotH = base - padT;

    // สเกลแกน Y: ปัดขึ้นเป็นเลขกลมๆ ให้เส้นกริดอ่านง่าย
    let maxV = 0;
    groups.forEach(g => g.values.forEach(v => { if (+v > maxV) maxV = +v; }));
    if (maxV <= 0) maxV = 1;
    const axis = ghgNiceAxis(maxV, 4);
    const step = axis.step, top = axis.top;

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
    ctx.font = '700 10px Sarabun, sans-serif';
    const plan = o.labels ? ghgBarLabelPlan(groups, bw + 2, t => ctx.measureText(t).width) : [];
    groups.forEach((g, gi) => {
      const cx = padL + gw * gi + gw / 2;
      const startX = cx - (bw * m) / 2;
      let tallest = 0;
      g.values.forEach((v, si) => {
        const val = Number(v) || 0;
        const full = (val / top) * plotH, h = full * prog;
        tallest = Math.max(tallest, full);
        ctx.fillStyle = series[si].color || '#9CA3AF';
        if (h > 0) ctx.fillRect(startX + bw * si, base - h, bw - 2, h);
        // ตัวเลขเหนือแท่ง (ค่อย ๆ ปรากฏตามแอนิเมชัน) — สีเดียวกับแท่ง
        const p = plan[gi];
        if (p && p.mode === 'bars' && p.text[si] && prog > 0.6) {
          ctx.globalAlpha = (prog - 0.6) / 0.4;
          ctx.font = '700 10px Sarabun, sans-serif';
          ctx.fillText(p.text[si], startX + bw * si + (bw - 2) / 2, base - h - 5);
          ctx.globalAlpha = 1;
        }
      });
      const p = plan[gi];
      if (p && p.mode !== 'bars' && prog > 0.6) {
        ctx.globalAlpha = (prog - 0.6) / 0.4;
        ctx.fillStyle = p.mode === 'empty' ? '#9CA3AF' : '#374151';
        ctx.font = (p.mode === 'empty' ? '600 ' : '700 ') + '11px Sarabun, sans-serif';
        ctx.fillText(p.text, cx, base - tallest * prog - (p.mode === 'empty' ? 10 : 6));
        ctx.globalAlpha = 1;
      }
      ctx.fillStyle = '#6B7280'; ctx.font = '500 11px Sarabun, sans-serif';
      ctx.fillText(String(g.label), cx, H - 12);
    });
  };
})(typeof window !== 'undefined' ? window : globalThis);

// ให้ unit test ฝั่ง node เรียก ghgNiceAxis ได้ (เบราว์เซอร์ไม่มี module จึงข้ามบรรทัดนี้)
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { ghgNiceAxis: globalThis.ghgNiceAxis, ghgBarLabelPlan: globalThis.ghgBarLabelPlan };
}
