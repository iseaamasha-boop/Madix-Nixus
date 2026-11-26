/* ==========================================================================
   التعديل المباشر في الجدول (البند 79): الخلية [data-edit] تصبح حقلاً في مكانها؛ Enter أو مغادرة الحقل تحفظ
   في الخلفية (بمفتاح منع التكرار)، وEscape يلغي، والخطأ يعيد القيمة السابقة مع رسالته. يُحمَّل عند أول استعمال.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var SN = window.SN;

  function start(cell) {
    if (cell.classList.contains('is-editing')) { return; }
    var oldHtml = cell.innerHTML, oldValue = cell.getAttribute('data-value') || '';
    var type = cell.getAttribute('data-type') || 'text';
    var input = document.createElement('input');
    input.className = 'input input-sm inline-input';
    input.value = oldValue;
    input.setAttribute('aria-label', cell.getAttribute('data-label') || '');
    if (type === 'decimal') { input.inputMode = 'decimal'; input.dir = 'ltr'; }
    if (type === 'tel') { input.type = 'tel'; input.inputMode = 'tel'; input.dir = 'ltr'; }
    cell.classList.add('is-editing');
    cell.innerHTML = '';
    cell.appendChild(input);
    input.focus();
    input.select();
    var done = false;

    function restore(html) {
      cell.classList.remove('is-editing', 'is-saving');
      cell.innerHTML = html;
    }
    function shown(text) {
      var empty = cell.getAttribute('data-empty');
      return text === '' && empty !== null ? '<span class="text-muted">' + SN.esc(empty) + '</span>' : SN.esc(text);
    }
    function save() {
      if (done) { return; }
      done = true;
      var value = input.value.trim();
      if (value === oldValue) { restore(oldHtml); return; }
      // الواجهة فوراً، والحفظ في الخلفية
      cell.classList.add('is-saving');
      restore(shown(value));
      cell.classList.add('is-saving');
      SN.api(cell.getAttribute('data-edit'), { method: 'POST', data: { field: cell.getAttribute('data-field'), value: value }, silent: true, headers: { 'X-Instant': '1' } })
        .then(function (d) {
          cell.classList.remove('is-saving');
          cell.setAttribute('data-value', d.value !== undefined ? d.value : value);
          cell.innerHTML = shown(d.display !== undefined ? d.display : value);
          cell.classList.add('is-saved');
          setTimeout(function () { cell.classList.remove('is-saved'); }, 900);
        }, function (err) {
          cell.classList.remove('is-saving');
          cell.innerHTML = oldHtml;
          SN.toast('error', err.message || 'تعذّر الحفظ.');
        });
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); save(); cell.focus(); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done = true; restore(oldHtml); cell.focus(); }
    });
    input.addEventListener('blur', save);
    input.addEventListener('click', function (e) { e.stopPropagation(); });
  }

  window.SNInline = { start: start };
})(window, document);
