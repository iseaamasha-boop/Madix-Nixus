<?php
/**
 * البند 106: ملخص السنة — عرض قصصي متحرك من بيانات الشبكة الحقيقية، ويُحفظ صورة للنشر في المتصفح نفسه
 * (لوحة رسم، بلا خدمات خارجية) عليها شعار الشبكة وMADIX. كل الشرائح نص في الصفحة (ظاهرة بلا JavaScript).
 * @var App\Core\View $v @var array $summary @var array $years @var bool $money
 */
use App\Core\Brand;
use App\Services\YearSummary;

$v->style('css/pages/year.css');
$v->script('js/year.js');
$v->title = 'ملخص السنة';
$s = $summary;
$y = (int) $s['year'];
$v->subtitle = (string) $y;
$links = '';
foreach (array_slice($years, 0, 4) as $yy) {
    $links .= '<a class="btn btn-sm' . ($yy === $y ? ' btn-primary' : '') . '" href="' . e(url('/reports/year', ['year' => $yy])) . '">' . $yy . '</a>';
}
$v->actions = '<div class="year-years">' . $links . '</div>';
$network = Brand::networkName();
$bestMonth = $s['best_month'] ? YearSummary::MONTHS[$s['best_month'] - 1] : null;
$peak = max(1, ...array_map(static fn ($m) => (float) $m[1], $s['months']));
$logo = Brand::networkLogo();
$data = [
    'year' => $y, 'network' => $network, 'logo' => $logo !== '' ? upload_url($logo) : '', 'mark' => Brand::markSrc(),
    'lines' => array_values(array_filter([
        ['الكروت المباعة', num($s['cards_sold'])],
        $money ? ['المبيعات', money($s['revenue'])] : null,
        $bestMonth ? ['أقوى شهر', $bestMonth] : null,
        $s['tower'] ? ['أحسن برج', $s['tower']['name']] : null,
        ['مشتركون جدد', num($s['new_subscribers'])],
        ['الاستهلاك', fmt_bytes($s['bytes'])],
    ])),
    'file' => 'madix-' . $y . '.png',
];
?>
<div class="year-wrap">
  <div class="year-story" data-year-story aria-roledescription="عرض">
    <div class="year-progress" aria-hidden="true"><?= str_repeat('<i></i>', 6) ?></div>

    <section class="year-slide is-on year-s-intro" data-slide>
      <span class="year-logo"><?= Brand::networkMark('year-net') ?></span>
      <h2 class="year-net-name"><?= e($network) ?></h2>
      <div class="year-big" dir="ltr"><?= $y ?></div>
      <p class="year-cap">ملخص السنة</p>
    </section>

    <section class="year-slide" data-slide>
      <p class="year-cap"><?= icon('ticket') ?>الكروت المباعة</p>
      <div class="year-big" data-count="<?= (int) $s['cards_sold'] ?>"><?= num($s['cards_sold']) ?></div>
      <?php if ($money): ?><p class="year-line">المبيعات <b><?= e(money($s['revenue'])) ?></b></p><?php endif; ?>
      <?php if ($s['top_category']): ?><p class="year-line">الباقة الأكثر مبيعاً <b><?= e($s['top_category']['name']) ?></b> · <span dir="ltr"><?= num($s['top_category']['qty']) ?></span></p><?php endif; ?>
    </section>

    <section class="year-slide" data-slide>
      <p class="year-cap"><?= icon('trending-up') ?>أقوى شهر</p>
      <div class="year-mid"><?= e($bestMonth ?? '—') ?></div>
      <div class="year-bars" aria-hidden="true">
        <?php foreach ($s['months'] as $i => [$qty, $amount]): ?>
        <span class="<?= $s['best_month'] === $i + 1 ? 'is-best' : '' ?>" style="--h:<?= max(4, (int) round(100 * (float) $amount / $peak)) ?>%;--i:<?= $i ?>"><small dir="ltr"><?= $i + 1 ?></small></span>
        <?php endforeach; ?>
      </div>
      <?php if ($s['best_month']): ?><p class="year-line"><?= num($s['months'][$s['best_month'] - 1][0]) ?> كرت<?php if ($money): ?> · <b><?= e(money($s['months'][$s['best_month'] - 1][1])) ?></b><?php endif; ?></p><?php endif; ?>
    </section>

    <section class="year-slide" data-slide>
      <p class="year-cap"><?= icon('radio-tower') ?>أحسن برج</p>
      <div class="year-mid"><?= e($s['tower']['name'] ?? '—') ?></div>
      <?php if ($s['tower']): ?>
      <p class="year-line"><b data-count="<?= (int) $s['tower']['logins'] ?>"><?= num($s['tower']['logins']) ?></b> كرت دخل منه · <span dir="ltr"><?= e(fmt_bytes($s['tower']['bytes'])) ?></span></p>
      <?php endif; ?>
    </section>

    <section class="year-slide" data-slide>
      <p class="year-cap"><?= icon('users') ?>المشتركون</p>
      <div class="year-big" data-count="<?= (int) $s['new_subscribers'] ?>"><?= num($s['new_subscribers']) ?></div>
      <p class="year-line">مشترك جديد · <b data-count="<?= (int) $s['renewals'] ?>"><?= num($s['renewals']) ?></b> تجديد</p>
      <p class="year-line">الاستهلاك <b dir="ltr"><?= e(fmt_bytes($s['bytes'])) ?></b> · <span dir="ltr"><?= num($s['cards_used']) ?></span> كرت مستخدم</p>
    </section>

    <section class="year-slide year-s-end" data-slide>
      <span class="year-logo"><?= Brand::networkMark('year-net') ?></span>
      <h2 class="year-net-name"><?= e($network) ?> · <span dir="ltr"><?= $y ?></span></h2>
      <dl class="year-grid">
        <?php foreach ($data['lines'] as [$label, $value]): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
      </dl>
      <div class="year-brand"><img src="<?= e($data['mark']) ?>" alt="" width="22" height="22"><b dir="ltr">MADIX NEXUS</b></div>
    </section>

    <button type="button" class="year-nav is-prev" data-year-prev aria-label="السابق"><?= icon('chevron-right') ?></button>
    <button type="button" class="year-nav is-next" data-year-next aria-label="التالي"><?= icon('chevron-left') ?></button>
  </div>
  <div class="year-actions">
    <button type="button" class="btn btn-primary" data-year-save><?= icon('download') ?>حفظ صورة</button>
    <button type="button" class="btn" data-year-share hidden><?= icon('send') ?>مشاركة</button>
  </div>
</div>
<script type="application/json" id="year-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
