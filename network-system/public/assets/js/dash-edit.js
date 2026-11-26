/* ==========================================================================
   تخصيص لوحة التحكم (البند 108): يُحمَّل فقط عند الضغط على «تخصيص».
   - كل قطعة [data-dash] داخل مجموعتها [data-dash-group] (بطاقات الأرقام، اللوحات): مقبض سحب وزر مقاس.
   - السحب بالفأرة واللمس (Pointer Events) في شبكة ثنائية الأبعاد، والقطع المجاورة تنزلق إلى أماكنها بـ transform فقط؛
     لوحة المفاتيح: Alt مع الأسهم على المقبض.
   - شريط عائم: حفظ (للموظف أو المسؤول نفسه)، إلغاء، إرجاع الأصل.
   - في استوديو التصميم ([data-dash-form]) يكتب الترتيب في حقل النموذج بدل الحفظ المباشر.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var SN = window.SN;
  var root = document.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var rtl = root.getAttribute('dir') === 'rtl';
  var SIZES = { stats: ['s', 'w'], panels: ['s', 'm', 'l', 'x'] };
  var SIZE_LABEL = { s: 'صغير', w: 'عريض', m: 'متوسط', l: 'كبير', x: 'كامل العرض' };

  function items(group) { return Array.prototype.filter.call(group.children, function (el) { return el.hasAttribute('data-dash'); }); }
  function sizeOf(el) { var m = /\bdsz-(\w)\b/.exec(el.className); return m ? m[1] : 's'; }
  function setSize(el, s) {
    el.className = el.className.replace(/\s*\bdsz-\w\b/g, '') + ' dsz-' + s;
    var b = el.querySelector('[data-dash-size]');
    if (b) { b.setAttribute('aria-label', 'المقاس: ' + SIZE_LABEL[s]); b.title = SIZE_LABEL[s]; }
  }

  // الترتيب الحالي: { stats: [[مفتاح، مقاس]...]، panels: [...] }
  function read(scope) {
    var out = { stats: [], panels: [] };
    Array.prototype.forEach.call(scope.querySelectorAll('[data-dash-group]'), function (g) {
      var name = g.getAttribute('data-dash-group');
      if (!out[name]) { return; }
      items(g).forEach(function (el) { out[name].push([el.getAttribute('data-dash'), sizeOf(el)]); });
    });
    return out;
  }

  // انزلاق FLIP: القطع التي تغيّر مكانها تنتقل إليه بـ transform فقط
  function flip(group, fn, skip) {
    var list = items(group), before = list.map(function (el) { return el.getBoundingClientRect(); });
    fn();
    if (reduce) { return; }
    list.forEach(function (el, i) {
      if (el === skip) { return; }
      var r = el.getBoundingClientRect(), dx = before[i].left - r.left, dy = before[i].top - r.top;
      if (!dx && !dy) { return; }
      el.style.transition = 'none';
      el.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
      requestAnimationFrame(function () {
        el.style.transition = 'transform 200ms cubic-bezier(.2, .8, .2, 1)';
        el.style.transform = '';
        setTimeout(function () { el.style.transition = ''; }, 220);
      });
    });
  }

  function tools(el, group) {
    if (el.querySelector(':scope > .dash-tools')) { return; }
    var name = group.getAttribute('data-dash-group');
    var box = document.createElement('div');
    box.className = 'dash-tools';
    box.innerHTML = '<button type="button" data-dash-drag aria-label="نقل">' + SN.icon('grip-vertical') + '</button>'
      + '<button type="button" data-dash-size>' + SN.icon('maximize-2') + '</button>';
    el.appendChild(box);
    setSize(el, sizeOf(el));
    box.querySelector('[data-dash-size]').addEventListener('click', function () {
      var list = SIZES[name] || ['s'], i = list.indexOf(sizeOf(el));
      flip(group, function () { setSize(el, list[(i + 1) % list.length]); });
      changed();
    });
  }

  var onChange = null;
  function changed() { if (onChange) { onChange(); } }

  // السحب: القطعة تتبع المؤشر (transform)، وحين يمر فوق قطعة أخرى في المجموعة نفسها تأخذ مكانها
  function drag(e, handle) {
    var el = handle.closest('[data-dash]'), group = el && el.parentNode;
    if (!group || !group.hasAttribute('data-dash-group') || e.button > 0) { return; }
    e.preventDefault();
    // مكان القطعة في الشبكة بلا إزاحة السحب
    function layout() { var t = el.style.transform; el.style.transform = 'none'; var r = el.getBoundingClientRect(); el.style.transform = t; return r; }
    var sx = e.clientX, sy = e.clientY, r0 = layout();
    try { handle.setPointerCapture(e.pointerId); } catch (x) { /* مؤشر غير نشط */ }
    el.classList.add('is-dash-dragging');
    root.classList.add('is-dash-sorting');
    // تبقى القطعة تحت المؤشر حيث أُمسكت، أياً كان مكانها الجديد في الشبكة
    function follow(ev) {
      var r = layout();
      el.style.transform = 'translate(' + (ev.clientX - sx + r0.left - r.left) + 'px,' + (ev.clientY - sy + r0.top - r.top) + 'px)';
    }
    // قرب حافة الشاشة العليا أو السفلى: الصفحة تتمرر وحدها أثناء السحب
    var last = null, scroller = 0;
    function edge() {
      scroller = 0;
      if (!last) { return; }
      var y = last.clientY, step = y < 70 ? -14 : (y > window.innerHeight - 70 ? 14 : 0);
      if (!step) { return; }
      window.scrollBy(0, step);
      move(last);
      scroller = requestAnimationFrame(edge);
    }
    function move(ev) {
      last = ev;
      if (!scroller) { scroller = requestAnimationFrame(edge); }
      el.style.visibility = 'hidden';
      var under = document.elementFromPoint(ev.clientX, ev.clientY);
      el.style.visibility = '';
      var target = under && under.closest('[data-dash]');
      if (target && target !== el && target.parentNode === group) {
        var list = items(group), from = list.indexOf(el), to = list.indexOf(target);
        flip(group, function () { group.insertBefore(el, to > from ? target.nextSibling : target); }, el);
        changed();
      }
      follow(ev);
    }
    function end() {
      cancelAnimationFrame(scroller);
      scroller = 0;
      last = null;
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', end);
      handle.removeEventListener('pointercancel', end);
      el.style.transition = reduce ? '' : 'transform 180ms cubic-bezier(.2, .8, .2, 1)';
      el.style.transform = '';
      setTimeout(function () { el.style.transition = ''; el.classList.remove('is-dash-dragging'); }, reduce ? 0 : 190);
      root.classList.remove('is-dash-sorting');
    }
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
  }

  // لوحة المفاتيح: Alt مع السهم يقدّم القطعة أو يؤخرها (بحسب اتجاه اللغة)
  function key(e, handle) {
    if (!e.altKey) { return; }
    var prev = e.key === 'ArrowUp' || e.key === (rtl ? 'ArrowRight' : 'ArrowLeft');
    var next = e.key === 'ArrowDown' || e.key === (rtl ? 'ArrowLeft' : 'ArrowRight');
    if (!prev && !next) { return; }
    var el = handle.closest('[data-dash]'), group = el && el.parentNode;
    if (!group || !group.hasAttribute('data-dash-group')) { return; }
    e.preventDefault();
    var list = items(group), i = list.indexOf(el), target = prev ? list[i - 1] : list[i + 1];
    if (!target) { return; }
    flip(group, function () { group.insertBefore(el, prev ? target : target.nextSibling); });
    handle.focus();
    changed();
  }

  var active = null;

  function stop(restore) {
    if (!active) { return; }
    var a = active;
    active = null;
    onChange = null;
    if (restore) {
      // إلغاء: كل قطعة إلى مكانها ومقاسها قبل التخصيص
      Object.keys(a.snapshot).forEach(function (name) {
        var g = a.scope.querySelector('[data-dash-group="' + name + '"]');
        if (!g) { return; }
        var map = {};
        items(g).forEach(function (el) { map[el.getAttribute('data-dash')] = el; });
        a.snapshot[name].forEach(function (p) { if (map[p[0]]) { setSize(map[p[0]], p[1]); g.appendChild(map[p[0]]); } });
      });
    }
    Array.prototype.forEach.call(a.scope.querySelectorAll('.dash-tools'), function (t) { t.remove(); });
    if (a.bar) { a.bar.remove(); }
    root.classList.remove('dash-editing');
    a.scope.removeEventListener('click', a.block, true);
    if (a.button) { a.button.setAttribute('aria-pressed', 'false'); a.button.focus(); }
  }

  function start(button) {
    if (active) { stop(true); return; }
    var scope = document.querySelector('.content') || document.body;
    active = { scope: scope, button: button, snapshot: read(scope) };
    root.classList.add('dash-editing');
    button.setAttribute('aria-pressed', 'true');
    Array.prototype.forEach.call(scope.querySelectorAll('[data-dash-group]'), function (g) { items(g).forEach(function (el) { tools(el, g); }); });
    // الروابط داخل القطع لا تعمل أثناء التخصيص
    active.block = function (e) { if (e.target.closest('[data-dash]') && !e.target.closest('.dash-tools')) { e.preventDefault(); } };
    scope.addEventListener('click', active.block, true);
    var bar = document.createElement('div');
    bar.className = 'dash-bar';
    bar.setAttribute('role', 'toolbar');
    bar.innerHTML = '<button type="button" class="btn btn-primary" data-dash-done>' + SN.icon('check') + 'حفظ</button>'
      + '<button type="button" class="btn" data-dash-cancel>إلغاء</button>'
      + '<button type="button" class="btn btn-ghost" data-dash-default>' + SN.icon('rotate-ccw') + 'الأصل</button>';
    document.body.appendChild(bar);
    active.bar = bar;
    bar.querySelector('[data-dash-cancel]').addEventListener('click', function () { stop(true); });
    bar.querySelector('[data-dash-done]').addEventListener('click', function () {
      var layout = read(scope), btn = this;
      btn.classList.add('is-loading');
      SN.api(button.getAttribute('data-dash-save'), { method: 'POST', data: { layout: JSON.stringify(layout) }, headers: { 'X-Instant': '1' } })
        .then(function (r) { SN.toast('success', (r && r.message) || 'تم الحفظ.'); button.setAttribute('data-dash-custom', ''); stop(false); })
        .catch(function (err) { btn.classList.remove('is-loading'); SN.toast('error', (err && err.message) || 'تعذّر الحفظ.'); });
    });
    bar.querySelector('[data-dash-default]').addEventListener('click', function () {
      var btn = this;
      btn.classList.add('is-loading');
      SN.api(button.getAttribute('data-dash-reset'), { method: 'POST', headers: { 'X-Instant': '1' } })
        .then(function () { window.location.reload(); })
        .catch(function (err) { btn.classList.remove('is-loading'); SN.toast('error', (err && err.message) || 'تعذّر الحفظ.'); });
    });
    var first = scope.querySelector('[data-dash-drag]');
    if (first) { first.focus(); }
  }

  // استوديو التصميم: الترتيب يُكتب في الحقل المخفي للنموذج مع كل تغيير
  function form(f) {
    var input = f.querySelector('input[name="layout"]');
    Array.prototype.forEach.call(f.querySelectorAll('[data-dash-group]'), function (g) { items(g).forEach(function (el) { tools(el, g); }); });
    onChange = function () { if (input) { input.value = JSON.stringify(read(f)); } };
    onChange();
  }

  document.addEventListener('pointerdown', function (e) {
    var h = e.target.closest && e.target.closest('[data-dash-drag]');
    if (h) { drag(e, h); }
  });
  document.addEventListener('keydown', function (e) {
    var h = e.target.closest && e.target.closest('[data-dash-drag]');
    if (h) { key(e, h); return; }
    if (e.key === 'Escape' && active) { stop(true); }
  });

  window.SNDash = { start: start, stop: stop, form: form, read: read };
})(window, document);
