/* ==========================================================================
   السحب والإفلات لإعادة الترتيب (البند 80): مقبض [.drag-handle] داخل صفوف [data-sortable] (جدول أو قائمة).
   الفأرة واللمس (Pointer Events)، والصفوف المجاورة تنزاح بحركة transform فقط؛ لوحة المفاتيح: Alt مع الأسهم على المقبض.
   الترتيب الجديد يُحفظ في الخلفية (بمفتاح منع التكرار)، والفشل يعيد الترتيب السابق مع رسالة.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var SN = window.SN;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function rows(box) { return Array.prototype.filter.call(box.children, function (r) { return r.hasAttribute('data-id'); }); }
  function ids(box) { return rows(box).map(function (r) { return r.getAttribute('data-id'); }); }

  // حركة FLIP: الصفوف التي تغيّر مكانها تنزلق إليه (transform فقط)
  function flip(box, fn) {
    var list = rows(box), before = list.map(function (r) { return r.getBoundingClientRect().top; });
    fn();
    if (reduce) { return; }
    list.forEach(function (r, i) {
      if (r.classList.contains('is-dragging')) { return; }
      var d = before[i] - r.getBoundingClientRect().top;
      if (!d) { return; }
      r.style.transition = 'none';
      r.style.transform = 'translateY(' + d + 'px)';
      requestAnimationFrame(function () {
        r.style.transition = 'transform 180ms cubic-bezier(.2, .8, .2, 1)';
        r.style.transform = '';
      });
    });
  }

  function save(box, previous) {
    var now = ids(box);
    if (now.join(',') === previous.join(',')) { return; }
    SN.api(box.getAttribute('data-sortable'), { method: 'POST', data: { ids: now }, silent: true, headers: { 'X-Instant': '1' } })
      .catch(function (err) {
        var map = {};
        rows(box).forEach(function (r) { map[r.getAttribute('data-id')] = r; });
        flip(box, function () { previous.forEach(function (id) { if (map[id]) { box.appendChild(map[id]); } }); });
        SN.toast('error', err.message || 'تعذّر حفظ الترتيب.');
      });
  }

  function drag(e, handle) {
    var row = handle.closest('[data-id]'), box = row && row.parentNode;
    if (!box || !box.hasAttribute('data-sortable') || e.button > 0) { return; }
    e.preventDefault();
    var previous = ids(box), startY = e.clientY, offset = 0;
    try { handle.setPointerCapture(e.pointerId); } catch (x) { /* مؤشر غير نشط: الحركة تُتابَع على المقبض نفسه */ }
    row.classList.add('is-dragging');
    document.documentElement.classList.add('is-sorting');

    function move(ev) {
      var dy = ev.clientY - startY - offset;
      row.style.transform = 'translateY(' + dy + 'px)';
      var list = rows(box), i = list.indexOf(row), r = row.getBoundingClientRect(), mid = r.top + r.height / 2;
      var next = list[i + 1], prev = list[i - 1];
      if (next && dy > 0 && mid > next.getBoundingClientRect().top + next.offsetHeight / 2) {
        var h = next.offsetHeight;
        flip(box, function () { box.insertBefore(next, row); });
        offset += h;
        row.style.transform = 'translateY(' + (ev.clientY - startY - offset) + 'px)';
      } else if (prev && dy < 0 && mid < prev.getBoundingClientRect().top + prev.offsetHeight / 2) {
        var h2 = prev.offsetHeight;
        flip(box, function () { box.insertBefore(row, prev); });
        offset -= h2;
        row.style.transform = 'translateY(' + (ev.clientY - startY - offset) + 'px)';
      }
    }
    function end() {
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', end);
      handle.removeEventListener('pointercancel', end);
      row.style.transition = reduce ? '' : 'transform 160ms cubic-bezier(.2, .8, .2, 1)';
      row.style.transform = '';
      setTimeout(function () { row.style.transition = ''; row.classList.remove('is-dragging'); }, reduce ? 0 : 170);
      document.documentElement.classList.remove('is-sorting');
      save(box, previous);
    }
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
  }

  // لوحة المفاتيح: Alt مع السهم يحرّك الصف خطوة، ويُحفظ بعد التوقف قليلاً
  var keyTimer = null, keyStart = null;
  function key(e, handle) {
    if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) { return; }
    var row = handle.closest('[data-id]'), box = row && row.parentNode;
    if (!box || !box.hasAttribute('data-sortable')) { return; }
    e.preventDefault();
    if (!keyStart) { keyStart = ids(box); }
    var list = rows(box), i = list.indexOf(row);
    var target = e.key === 'ArrowUp' ? list[i - 1] : list[i + 1];
    if (!target) { return; }
    flip(box, function () { box.insertBefore(row, e.key === 'ArrowUp' ? target : target.nextSibling); });
    handle.focus();
    clearTimeout(keyTimer);
    keyTimer = setTimeout(function () { var p = keyStart; keyStart = null; save(box, p); }, 600);
  }

  document.addEventListener('pointerdown', function (e) {
    var h = e.target.closest && e.target.closest('[data-sortable] .drag-handle');
    if (h) { drag(e, h); }
  });
  document.addEventListener('keydown', function (e) {
    var h = e.target.closest && e.target.closest('[data-sortable] .drag-handle');
    if (h) { key(e, h); }
  });

  window.SNSortable = true;
})(window, document);
