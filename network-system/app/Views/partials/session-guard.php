<?php
/**
 * الرجوع للخلف الفوري بأمان: الصفحة المستعادة من ذاكرة المتصفح (bfcache أو ذاكرة الرجوع) تُعرض فوراً،
 * إلا إذا خرج المستخدم أو دخل غيره بعدها (علامة الجلسة تغيّرت)، فتُخفى وتُطلب من الخادم (فيظهر الدخول).
 * والصفحة المستعادة بعد غياب طويل (أكثر من 5 دقائق) تُطلب من جديد حتى لا تظهر أرقام قديمة.
 * @var string $cookie اسم ملف العلامة: mnx_tag لنظام الشبكة، mnx_atag للوحة المسؤول
 */
$cookie = $cookie ?? 'mnx_tag';
$tag = \App\Core\Session::tag($cookie);
?>
<meta name="session-tag" content="<?= e($tag) ?>">
<script>
(function () {
  var tag = <?= json_encode($tag) ?>, name = <?= json_encode($cookie) ?>, born = Date.now(), d = document.documentElement;
  function current() { var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)')); return m ? m[1] : ''; }
  function stale(age) {
    if (current() === tag && age < 300000) { return false; }
    d.style.visibility = 'hidden';
    location.reload();
    return true;
  }
  try {
    var nav = performance.getEntriesByType('navigation')[0];
    if (nav && nav.type === 'back_forward') { stale(0); }
  } catch (e) {}
  addEventListener('pageshow', function (e) { if (e.persisted) { stale(Date.now() - born); } });
})();
</script>
