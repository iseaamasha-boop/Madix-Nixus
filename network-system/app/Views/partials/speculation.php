<?php
/*
 * تحميل مسبق ذكي (Chrome وEdge وAndroid والتطبيق): عند مرور الماوس على رابط أو لمسه بالإصبع يجلب المتصفح الصفحة
 * في الخلفية، فتظهر فور الضغط. روابط القائمة والتبويبات وأقسام الصفحة، وروابط المحتوى (الجداول والبطاقات).
 * - روابط GET داخل النظام فقط؛ مستثنى كل رابط له أثر عند فتحه: الخروج واللغة والتصدير والتنزيل والطباعة والتذاكر
 *   والدخول والواجهات البرمجية، وأي رابط عليه data-no-prefetch أو يفتح نافذة أخرى. (والخادم لا يغيّر شيئاً لطلب تحميل مسبق.)
 * - لا تحميل مسبق في وضع توفير البيانات ولا على نت ضعيف (2G)، والمتصفح يوقفه أيضاً مع توفير الطاقة.
 * - يُجلب HTML الصفحة فقط ولا تُنفَّذ سكربتاتها، والصفحة الأقدم من 10 ثوانٍ تُطلب من جديد (partials/prefetch-fresh).
 */
$skip = ['/logout', '/lang/', 'export', 'download', '/print', '/auth/', '/login', '/api/', '/cron', '/support/', '/tickets/', '/impersonat', '/check/card', '/inbox/', '/admin/bot/', 'fragment=', '/setup/', '.rsc'];
$not = '';
foreach ($skip as $s) {
    $not .= ":not([href*='" . $s . "'])";
}
$not .= ':not([data-no-prefetch]):not([data-no-loading]):not([target]):not([download]):not([data-confirm])';
$areas = ['.sidebar a.nav-link', '.tabs a.tab', '.section-nav a', '.crumbs a', '.content a', '.admin-content a'];
$sel = implode(', ', array_map(static fn ($b) => $b . '[href^="/"]' . $not, $areas));
$rules = ['prefetch' => [['where' => ['selector_matches' => $sel], 'eagerness' => 'moderate']]];
?>
<script>
(function () {
  var c = navigator.connection || {};
  if (c.saveData || /2g$/.test(c.effectiveType || '')) { return; }
  if (!(window.HTMLScriptElement && HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules'))) { return; }
  var s = document.createElement('script');
  s.type = 'speculationrules';
  s.textContent = <?= json_encode(json_encode($rules, JSON_UNESCAPED_SLASHES), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  document.head.appendChild(s);
})();
</script>
