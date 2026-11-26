<?php
/**
 * البند 105+: تفاصيل زيارة للشبكة التجريبية والصفحات التي زارها الزائر بالترتيب.
 * @var App\Core\View $v @var array $visit @var array $views @var array $others
 */
$v->style('css/pages/admin.css');
$v->title = 'زيارة للشبكة التجريبية';
$v->subtitle = fmt_dt($visit['started_at']);
$v->actions = '<a class="btn" href="' . e(url('/admin/demo')) . '">' . icon('chevron-right') . 'الزيارات</a>';
$dur = static fn (int $s): string => $s < 60 ? num(max(0, $s)) . ' ث' : ($s < 3600 ? num(intdiv($s, 60)) . ' د' : num(intdiv($s, 3600)) . ' س ' . num(intdiv($s % 3600, 60)) . ' د');
$deviceLabel = ['mobile' => 'جوال', 'tablet' => 'لوحي', 'desktop' => 'حاسوب'];
$cc = $visit['country'];
$countryName = $cc && class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $cc, \App\Core\Lang::isEn() ? 'en' : 'ar') : ($cc ?: '');
$start = strtotime($visit['started_at']) ?: 0;
?>
<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('file-text') ?>الصفحات</h3><span class="badge badge-outline"><?= num(count($views)) ?></span></div>
    <?php if (!$views): ?>
    <div class="empty"><?= icon('file-text') ?><div class="empty-title">لم تُفتح صفحات</div></div>
    <?php else: ?>
    <ol class="demo-path">
      <?php foreach ($views as $p): ?>
      <li><span class="demo-path-time mono" dir="ltr">+<?= e(gmdate('i:s', max(0, (strtotime($p['created_at']) ?: $start) - $start))) ?></span><span class="grow"><?= e($p['title'] ?: $p['path']) ?><span class="cell-sub mono" dir="ltr"><?= e($p['path']) ?></span></span></li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </div>
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('user') ?>الزائر</h3><span class="mono" dir="ltr">#<?= e(substr($visit['visitor'], 0, 6)) ?></span></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>الوقت</dt><dd><?= e(fmt_dt($visit['started_at'])) ?></dd>
          <dt>المدة</dt><dd><?= e($dur((int) $visit['duration'])) ?></dd>
          <dt>الجهاز</dt><dd><?= e($deviceLabel[$visit['device']] ?? '—') ?></dd>
          <dt>المتصفح</dt><dd dir="ltr"><?= e($visit['browser'] ?: '—') ?></dd>
          <dt>النظام</dt><dd dir="ltr"><?= e($visit['os'] ?: '—') ?></dd>
          <dt>الدولة</dt><dd><?= e($countryName !== '' ? $countryName : '—') ?></dd>
          <dt>المصدر</dt><dd><?= e($visit['source'] === null || $visit['source'] === '' ? 'مباشر' : ($visit['source'] === 'landing' ? 'صفحة الهبوط' : $visit['source'])) ?></dd>
          <dt>اللغة</dt><dd><?= e($visit['lang'] === 'en' ? 'English' : 'العربية') ?></dd>
        </dl>
      </div>
    </div>
    <?php if ($others): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('history') ?>زياراته الأخرى</h3><span class="badge badge-info"><?= num(count($others)) ?></span></div>
      <div class="panel-body stack" style="gap:6px">
        <?php foreach ($others as $o): ?>
        <a class="demo-row" href="<?= e(url('/admin/demo/visits/' . (int) $o['id'])) ?>"><span class="grow"><?= e(fmt_dt($o['started_at'])) ?></span><span class="text-sm text-muted"><?= e($dur((int) $o['duration'])) ?> · <?= num((int) $o['pages']) ?> صفحة</span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
