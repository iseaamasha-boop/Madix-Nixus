/* ==========================================================================
   رؤوس الجداول الطويلة تبقى ظاهرة أثناء التمرير (البند 76).
   الجدول داخل حاوية تمرير أفقي، فـ position: sticky لا يثبت الرأس على الشاشة؛ نسخة خفيفة من الرأس تظهر
   تحت الشريط العلوي حين يختفي الرأس الأصلي، بعرض الأعمدة نفسه، وتتبع التمرير الأفقي. تُحمَّل في الصفحات
   التي فيها جدول طويل فقط.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var MIN_ROWS = 20;
  var topbar = document.querySelector('.topbar');

  function topOffset() {
    if (!topbar) { return 0; }
    var r = topbar.getBoundingClientRect();
    return r.bottom > 0 ? r.bottom : 0;
  }

  function setup(wrap) {
    var table = wrap.querySelector(':scope > table.table');
    var thead = table && table.tHead;
    if (!thead || !table.tBodies[0] || table.tBodies[0].rows.length < MIN_ROWS || !thead.rows[0]) { return; }

    var bar = document.createElement('div');
    bar.className = 'table-float';
    var clone = document.createElement('table');
    clone.className = table.className;
    clone.appendChild(thead.cloneNode(true));
    Array.prototype.forEach.call(clone.querySelectorAll('[id]'), function (n) { n.removeAttribute('id'); });
    Array.prototype.forEach.call(clone.querySelectorAll('input, select, textarea, button'), function (n) { n.removeAttribute('name'); n.tabIndex = -1; });
    bar.appendChild(clone);
    document.body.appendChild(bar);

    var shown = false, queued = false;

    function widths() {
      var src = thead.rows[0].cells, dst = clone.tHead.rows[0].cells;
      clone.style.width = table.getBoundingClientRect().width + 'px';
      for (var i = 0; i < src.length && i < dst.length; i++) {
        var w = src[i].getBoundingClientRect().width + 'px';
        dst[i].style.width = w; dst[i].style.minWidth = w; dst[i].style.maxWidth = w;
      }
    }
    function syncChecks() {
      var a = thead.querySelectorAll('input[type=checkbox]'), b = clone.querySelectorAll('input[type=checkbox]');
      for (var i = 0; i < a.length && i < b.length; i++) { b[i].checked = a[i].checked; b[i].indeterminate = a[i].indeterminate; }
    }
    function place() {
      var w = wrap.getBoundingClientRect();
      bar.style.top = topOffset() + 'px';
      bar.style.left = w.left + 'px';
      bar.style.width = w.width + 'px';
      bar.scrollLeft = wrap.scrollLeft;
    }
    function update() {
      queued = false;
      var top = topOffset(), t = table.getBoundingClientRect(), h = thead.getBoundingClientRect();
      var show = h.top < top && t.bottom > top + h.height * 2 && t.width > 0;
      if (show !== shown) {
        shown = show;
        if (show) { widths(); syncChecks(); }
        bar.classList.toggle('is-on', show);
      }
      if (show) { place(); }
    }
    function soon() {
      if (!queued) { queued = true; requestAnimationFrame(update); }
    }

    window.addEventListener('scroll', soon, { passive: true });
    window.addEventListener('resize', function () { if (shown) { widths(); } soon(); });
    wrap.addEventListener('scroll', function () { if (shown) { bar.scrollLeft = wrap.scrollLeft; } }, { passive: true });
    document.addEventListener('change', function () { if (shown) { syncChecks(); } });
    // مربع «تحديد الكل» في النسخة يضغط الأصلي
    bar.addEventListener('click', function (e) {
      var cb = e.target.closest('input[type=checkbox]');
      if (!cb) { return; }
      e.preventDefault();
      var list = Array.prototype.indexOf.call(clone.querySelectorAll('input[type=checkbox]'), cb);
      var orig = thead.querySelectorAll('input[type=checkbox]')[list];
      if (orig) { orig.click(); setTimeout(syncChecks, 0); }
    });
    update();
  }

  Array.prototype.forEach.call(document.querySelectorAll('.table-wrap'), setup);
})(window, document);
