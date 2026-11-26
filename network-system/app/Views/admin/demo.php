<?php
/**
 * البند 105 و105+: الشبكة التجريبية («جرّب الآن») وزوارها.
 * @var App\Core\View $v @var ?array $tenant @var bool $enabled @var string $resetAt @var array $stats @var array $visits
 * @var int $page @var int $pages @var array $countries @var array $sources @var array $devices @var array $topPages
 */
use App\Platform\Demo;

$v->style('css/pages/admin.css');
$v->title = 'الشبكة التجريبية';
$dur = static function (int $s): string {
    if ($s < 60) {
        return num(max(0, $s)) . ' ث';
    }
    if ($s < 3600) {
        return num(intdiv($s, 60)) . ' د';
    }
    return num(intdiv($s, 3600)) . ' س ' . num(intdiv($s % 3600, 60)) . ' د';
};
$flag = static fn (?string $cc): string => $cc ? implode('', array_map(static fn ($c) => mb_chr(0x1F1E6 + ord($c) - 65), str_split($cc))) : '';
$country = static function (?string $cc) use ($flag): string {
    if (!$cc) {
        return '—';
    }
    $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $cc, \App\Core\Lang::isEn() ? 'en' : 'ar') : '';
    return $flag($cc) . ' ' . ($name !== '' && $name !== $cc ? $name : $cc);
};
$deviceLabel = ['mobile' => 'جوال', 'tablet' => 'لوحي', 'desktop' => 'حاسوب'];
$deviceIcon = ['mobile' => 'smartphone', 'tablet' => 'smartphone', 'desktop' => 'monitor'];
$source = static fn (?string $s): string => $s === null || $s === '' ? 'مباشر' : ($s === 'landing' ? 'صفحة الهبوط' : $s);
$next = $resetAt !== '' ? date('Y-m-d H:i', (strtotime($resetAt) ?: time()) + Demo::RESET_EVERY) : '—';
?>
<div class="grid grid-main-side">
  <div class="stack">
    <div class="stats demo-stats">
      <div class="stat"><div class="stat-icon"><?= icon('users') ?></div><div class="stat-body"><div class="stat-label">الزوار</div><div class="stat-value"><?= num((int) ($stats['visitors'] ?? 0)) ?></div></div></div>
      <div class="stat"><div class="stat-icon is-info"><?= icon('play') ?></div><div class="stat-body"><div class="stat-label">مرات الفتح</div><div class="stat-value"><?= num((int) ($stats['opens'] ?? 0)) ?></div><div class="stat-meta">اليوم <?= num((int) ($stats['today'] ?? 0)) ?></div></div></div>
      <div class="stat"><div class="stat-icon is-success"><?= icon('clock') ?></div><div class="stat-body"><div class="stat-label">متوسط المدة</div><div class="stat-value"><?= e($dur((int) round((float) ($stats['duration'] ?? 0)))) ?></div></div></div>
      <div class="stat"><div class="stat-icon is-warning"><?= icon('file-text') ?></div><div class="stat-body"><div class="stat-label">متوسط الصفحات</div><div class="stat-value"><?= e(number_format((float) ($stats['pages'] ?? 0), 1)) ?></div></div></div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><?= icon('history') ?>الزيارات</h3></div>
      <?php if (!$visits): ?>
      <div class="empty"><?= icon('users') ?><div class="empty-title">لا زيارات بعد</div></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table table-compact" data-demo-visits>
          <thead><tr><th>الوقت</th><th>الزائر</th><th>الجهاز</th><th>الدولة</th><th>المصدر</th><th class="num">المدة</th><th class="num">الصفحات</th></tr></thead>
          <tbody>
          <?php foreach ($visits as $r): ?>
            <tr>
              <td><a href="<?= e(url('/admin/demo/visits/' . (int) $r['id'])) ?>"><?= e(fmt_dt($r['started_at'])) ?></a></td>
              <td><span class="mono" dir="ltr">#<?= e(substr($r['visitor'], 0, 6)) ?></span><?php if ((int) $r['opens'] > 1): ?> <span class="badge badge-info"><?= num((int) $r['opens']) ?> مرات</span><?php endif; ?></td>
              <td><?= icon($deviceIcon[$r['device']] ?? 'monitor', 'icon-sm') ?> <?= e($deviceLabel[$r['device']] ?? '—') ?><div class="cell-sub" dir="ltr"><?= e(trim(($r['browser'] ?? '') . ' · ' . ($r['os'] ?? ''), ' ·')) ?></div></td>
              <td><?= e($country($r['country'])) ?></td>
              <td><?= e($source($r['source'])) ?></td>
              <td class="num"><?= e($dur((int) $r['duration'])) ?></td>
              <td class="num"><?= num((int) $r['pages']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pages > 1): ?>
      <div class="panel-foot pager-links">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('/admin/demo', ['page' => $page - 1])) ?>"><?= icon('chevron-right') ?>الأحدث</a><?php endif; ?>
        <span class="text-sm text-muted"><?= num($page) ?> / <?= num($pages) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('/admin/demo', ['page' => $page + 1])) ?>">الأقدم<?= icon('chevron-left') ?></a><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="stack">
    <form method="post" action="<?= e(url('/admin/demo')) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('play') ?>الشبكة التجريبية</h3><?= $enabled ? '<span class="badge badge-success">مفتوحة</span>' : '<span class="badge badge-outline">موقوفة</span>' ?></div>
      <div class="panel-body stack" style="gap:12px">
        <label class="switch"><input type="checkbox" name="enabled" value="1"<?= checked($enabled) ?>><span class="track"></span><span>«جرّب الآن» في صفحة الهبوط</span></label>
        <?= error_html('enabled') ?>
        <?php if ($tenant): ?>
        <dl class="kv">
          <dt>الرابط</dt><dd><a href="<?= e(Demo::url()) ?>" target="_blank" rel="noopener" dir="ltr">/<?= e($tenant['slug']) ?>/start</a></dd>
          <dt>آخر تجهيز</dt><dd><?= $resetAt !== '' ? e(fmt_dt($resetAt)) : '—' ?></dd>
          <dt>التجهيز التالي</dt><dd><?= $enabled ? e($next) : '—' ?></dd>
        </dl>
        <?php endif; ?>
        <div class="form-actions" style="margin:0"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
      </div>
    </form>
    <?php if ($tenant): ?>
    <form method="post" action="<?= e(url('/admin/demo/reset')) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-body"><button class="btn btn-block"><?= icon('refresh-cw') ?>إعادة التجهيز الآن</button></div>
    </form>
    <?php endif; ?>

    <?php foreach ([['الدول', 'globe', $countries, $country], ['المصادر', 'link', $sources, $source], ['الأجهزة', 'monitor', $devices, static fn ($d) => $deviceLabel[$d] ?? '—']] as [$label, $ic, $list, $fmt]): if (!$list) { continue; } ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon($ic) ?><?= e($label) ?></h3><span class="text-sm text-muted">30 يوماً</span></div>
      <div class="panel-body stack" style="gap:6px">
        <?php foreach ($list as $row): ?>
        <div class="demo-row"><span class="grow"><?= e($fmt($row['k'])) ?></span><b><?= num((int) $row['n']) ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($topPages): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('file-text') ?>أكثر الصفحات زيارة</h3><span class="text-sm text-muted">30 يوماً</span></div>
      <div class="panel-body stack" style="gap:6px">
        <?php foreach ($topPages as $row): ?>
        <div class="demo-row"><span class="grow"><?= e($row['title'] ?: $row['path']) ?><span class="cell-sub mono" dir="ltr"><?= e($row['path']) ?></span></span><b><?= num((int) $row['n']) ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
