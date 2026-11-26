/* ==========================================================================
   أدوات الخريطة (تُحمَّل عند الطلب فقط):
   - البند 93: طبقة حرارية للاستهلاك الحقيقي حسب الأبراج (آخر 24 ساعة) على لوحة رسم واحدة، وأكثر المناطق استهلاكاً.
   - البند 94: إعادة تشغيل يوم: شريط زمني يُسحب كل 15 دقيقة: حالة كل برج (متصل/منقطع) والمتصلون عليه في ذلك الوقت.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var SN = window.SN, M = window.SNMap, L = window.L;
  if (!SN || !M || !L) { return; }
  var map = M.map;

  // ------------------------------------------------------------------------
  // الطبقة الحرارية: نقاط بشدة الاستهلاك تُرسم بتدرج شفافية ثم تُلوَّن بتدرج ألوان (أزرق ← أخضر ← أصفر ← أحمر)
  // ------------------------------------------------------------------------
  var palette = null;
  function gradient() {
    if (palette) { return palette; }
    var c = document.createElement('canvas'); c.width = 1; c.height = 256;
    var g = c.getContext('2d'), lg = g.createLinearGradient(0, 0, 0, 256);
    // ألوان كاملة (الشفافية تُحسب وحدها)، فلا رمادي عند الأطراف الخفيفة
    lg.addColorStop(0, '#3b82f6');
    lg.addColorStop(0.4, '#22c55e');
    lg.addColorStop(0.7, '#facc15');
    lg.addColorStop(1, '#ef4444');
    g.fillStyle = lg; g.fillRect(0, 0, 1, 256);
    palette = g.getImageData(0, 0, 1, 256).data;
    return palette;
  }

  var HeatLayer = L.Layer.extend({
    initialize: function (points) { this._points = points; },
    onAdd: function (m) {
      this._canvas = L.DomUtil.create('canvas', 'map-heat leaflet-zoom-hide');
      m.getPanes().overlayPane.appendChild(this._canvas);
      m.on('moveend zoomend resize', this._draw, this);
      this._draw();
    },
    onRemove: function (m) {
      L.DomUtil.remove(this._canvas);
      m.off('moveend zoomend resize', this._draw, this);
    },
    setPoints: function (points) { this._points = points; if (this._map) { this._draw(); } },
    _draw: function () {
      var m = this._map, size = m.getSize(), cv = this._canvas;
      L.DomUtil.setPosition(cv, m.containerPointToLayerPoint([0, 0]));
      cv.width = size.x; cv.height = size.y;
      var g = cv.getContext('2d');
      if (!this._points.length) { return; }
      var max = 0, min = Infinity;
      this._points.forEach(function (p) { if (p[2] > max) { max = p[2]; } if (p[2] < min) { min = p[2]; } });
      if (!max) { return; }
      var span = max - min;
      // نصف القطر يكبر مع التكبير (تغطية البرج قرابة 1.5 كم)، ولا يصغر تحت علامة البرج فيختفي خلفها
      var z = m.getZoom(), r = Math.max(40, Math.min(170, 1500 / (40075016 * Math.cos(m.getCenter().lat * Math.PI / 180) / Math.pow(2, z + 8))));
      this._points.forEach(function (p) {
        var c = m.latLngToContainerPoint([p[0], p[1]]);
        if (c.x < -r || c.y < -r || c.x > size.x + r || c.y > size.y + r) { return; }
        // الشدة بين الأقل والأكثر استهلاكاً (الأبراج المتقاربة تظهر فروقها)
        var a = span > 0 ? 0.3 + 0.7 * (p[2] - min) / span : 0.8;
        var rg = g.createRadialGradient(c.x, c.y, 0, c.x, c.y, r);
        rg.addColorStop(0, 'rgba(0, 0, 0, ' + a + ')');
        rg.addColorStop(1, 'rgba(0, 0, 0, 0)');
        g.fillStyle = rg;
        g.beginPath(); g.arc(c.x, c.y, r, 0, Math.PI * 2); g.fill();
      });
      var img = g.getImageData(0, 0, size.x, size.y), d = img.data, pal = gradient();
      for (var i = 3; i < d.length; i += 4) {
        var v = d[i];
        if (!v) { continue; }
        var o = v * 4;
        d[i - 3] = pal[o]; d[i - 2] = pal[o + 1]; d[i - 1] = pal[o + 2]; d[i] = Math.min(205, Math.round(v * 1.5));
      }
      g.putImageData(img, 0, 0);
    }
  });

  var heatLayer = null, heatData = null, heatBox = document.querySelector('[data-heat-areas]');
  function heat(on) {
    if (!on) {
      if (heatLayer) { map.removeLayer(heatLayer); }
      if (heatBox) { heatBox.hidden = true; }
      return;
    }
    var show = function () {
      var pts = heatData.sites.map(function (s) { return [s[1], s[2], s[3]]; });
      if (!heatLayer) { heatLayer = new HeatLayer(pts); } else { heatLayer.setPoints(pts); }
      heatLayer.addTo(map);
      if (heatBox) {
        var list = heatData.areas.slice(0, 6), max = list.length ? list[0][1] : 0;
        heatBox.innerHTML = list.map(function (a) {
          return '<div class="heat-row"><span>' + SN.esc(a[0] || 'منطقة غير محددة') + '</span><b>' + SN.fmtBytes(a[1]) + '</b>'
            + '<i style="--w-scale:' + (max ? Math.max(0.04, a[1] / max).toFixed(3) : 0) + '"></i></div>';
        }).join('');
        heatBox.hidden = !list.length;
      }
    };
    if (heatData) { show(); return; }
    SN.api(M.url('/map/heat'), { silent: true }).then(function (d) { heatData = d; show(); }).catch(function (e) { SN.toast('error', e.message); });
  }

  // ------------------------------------------------------------------------
  // إعادة تشغيل اليوم
  // ------------------------------------------------------------------------
  var bar = null, replay = null, timer = null;
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function slotLabel(i) { return pad(Math.floor(i / 4)) + ':' + pad((i % 4) * 15); }

  function apply(i) {
    if (!replay) { return; }
    var data = M.data(), downs = 0, users = 0;
    data.sites.forEach(function (s) {
      var m = M.markers.sites[s.id], cell = (replay.sites[s.id] || [])[i] || 0;
      var st = !cell ? 'is-idle' : (cell[1] ? 'is-down' : 'is-up');
      if (cell) { users += cell[2]; if (cell[1]) { downs++; } }
      var el = m && m.getElement && m.getElement(), mk = el && el.querySelector('.mk');
      if (mk) { ['is-up', 'is-down', 'is-idle'].forEach(function (c) { mk.classList.toggle(c, c === st); }); }
      if (m) { M.label(m, s.name + (cell ? ' · ' + SN.fmtNum(cell[2]) : ''), 'is-site', 20); }
    });
    bar.querySelector('[data-rp-time]').textContent = slotLabel(i);
    bar.querySelector('[data-rp-users]').textContent = SN.fmtNum(users);
    bar.querySelector('[data-rp-down]').textContent = SN.fmtNum(downs);
    bar.querySelector('[data-rp-slider]').setAttribute('aria-valuetext', slotLabel(i));
  }

  function load(date) {
    var q = date ? '?date=' + encodeURIComponent(date) : '';
    bar.classList.add('is-loading');
    return SN.api(M.url('/map/replay') + q, { silent: true }).then(function (d) {
      replay = d;
      var inp = bar.querySelector('[data-rp-date]');
      inp.min = d.min; inp.max = d.max; inp.value = d.date;
      // اليوم الحالي: يبدأ من آخر ربع ساعة؛ يوم سابق: من أوله
      var slider = bar.querySelector('[data-rp-slider]');
      var now = new Date(), isToday = d.date === d.max;
      slider.value = isToday ? Math.min(95, now.getHours() * 4 + Math.floor(now.getMinutes() / 15)) : 0;
      apply(parseInt(slider.value, 10));
    }).catch(function (e) { SN.toast('error', e.message); }).then(function () { bar.classList.remove('is-loading'); });
  }

  function stopPlay() {
    clearInterval(timer); timer = null;
    var b = bar && bar.querySelector('[data-rp-play]');
    if (b) { b.innerHTML = SN.icon('play'); b.setAttribute('aria-label', 'تشغيل'); }
  }

  function openReplay() {
    if (bar) { return; }
    M.replaying(true);
    bar = document.createElement('div');
    bar.className = 'map-replay';
    bar.innerHTML = '<div class="map-replay-head">'
      + '<input type="date" class="input input-sm" data-rp-date aria-label="اليوم">'
      + '<button type="button" class="icon-btn" data-rp-play aria-label="تشغيل">' + SN.icon('play') + '</button>'
      + '<b class="map-replay-time" data-rp-time>00:00</b>'
      + '<span class="map-replay-stat">' + SN.icon('users') + '<span data-rp-users>0</span></span>'
      + '<span class="map-replay-stat is-down">' + SN.icon('unplug') + '<span data-rp-down>0</span></span>'
      + '<button type="button" class="icon-btn" data-rp-close aria-label="إغلاق">' + SN.icon('x') + '</button></div>'
      + '<input type="range" min="0" max="95" step="1" value="0" data-rp-slider aria-label="الوقت">';
    map.getContainer().parentNode.appendChild(bar);
    var slider = bar.querySelector('[data-rp-slider]');
    slider.addEventListener('input', function () { stopPlay(); apply(parseInt(slider.value, 10)); });
    bar.querySelector('[data-rp-date]').addEventListener('change', function () { stopPlay(); load(this.value); });
    bar.querySelector('[data-rp-close]').addEventListener('click', closeReplay);
    bar.querySelector('[data-rp-play]').addEventListener('click', function () {
      if (timer) { stopPlay(); return; }
      this.innerHTML = SN.icon('pause'); this.setAttribute('aria-label', 'إيقاف');
      if (parseInt(slider.value, 10) >= 95) { slider.value = 0; }
      timer = setInterval(function () {
        var v = parseInt(slider.value, 10) + 1;
        if (v > 95) { stopPlay(); return; }
        slider.value = v; apply(v);
      }, 400);
    });
    load('');
  }

  function closeReplay() {
    stopPlay();
    if (bar) { bar.remove(); bar = null; }
    replay = null;
    M.replaying(false);
    var btn = document.querySelector('[data-map-replay]');
    if (btn) { btn.setAttribute('aria-pressed', 'false'); btn.focus(); }
  }

  window.SNMapTools = { heat: heat, replay: function (on) { if (on) { openReplay(); } else { closeReplay(); } } };
})(window, document);
