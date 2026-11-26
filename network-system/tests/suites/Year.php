<?php
declare(strict_types=1);

/*
 * المرحلة 7، البند 106: ملخص السنة من بيانات الشبكة الحقيقية (الكروت المباعة، أقوى شهر، أحسن برج...)،
 * يُفتح من التقارير في أي وقت ويظهر على لوحة التحكم آخر السنة، والمبالغ لمن يملك التقارير المالية فقط.
 */

use Tests\Env;
use Tests\T;

return [
    'year summary numbers come from the real data, cancelled sales excluded (106)' => static function (Env $env): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $year = (int) date('Y');
        $range = [$year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00'];
        $q = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM sales WHERE status = 'completed' AND created_at >= ? AND created_at < ?");
        $q->execute($range);
        $sold = (int) $q->fetchColumn();
        $cat = (int) $pdo->query('SELECT MIN(id) FROM categories')->fetchColumn();
        $pdo->prepare("INSERT INTO sales (type, category_id, quantity, unit_price, total, paid, status, cancelled_at, created_at) VALUES ('direct', ?, 999, 1, 999, 999, 'cancelled', NOW(), NOW())")->execute([$cat]);
        $env->cli('cli/migrate.php');
        $r = $h->get($t['path'] . '/reports/year?year=' . $year);
        T::status(200, $r);
        T::cleanHtml($r);
        T::ok(preg_match('#<script type="application/json" id="year-data">(.*?)</script>#s', $r['body'], $m) === 1, 'poster data in the page');
        $data = json_decode($m[1], true);
        $lines = array_column($data['lines'], 1, 0);
        T::eq(number_format($sold), $lines['الكروت المباعة'] ?? null, 'cards sold = completed sales of the year (cancelled excluded)');
        T::eq($year, $data['year'], 'the chosen year');
        // أقوى شهر: أكبر مبيعات
        $m2 = $pdo->prepare("SELECT MONTH(created_at) FROM sales WHERE status = 'completed' AND created_at >= ? AND created_at < ? GROUP BY MONTH(created_at) ORDER BY SUM(total) DESC, SUM(quantity) DESC LIMIT 1");
        $m2->execute($range);
        $best = $m2->fetchColumn();
        if ($best) {
            T::eq(\App\Services\YearSummary::MONTHS[(int) $best - 1], $lines['أقوى شهر'] ?? null, 'best month by sales');
        }
        T::contains('data-year-story', $r['body'], 'story');
        T::eq(6, substr_count($r['body'], 'data-slide'), 'six slides, all as text in the page');
        T::contains('href="' . $t['path'] . '/reports/year"', $h->get($t['path'] . '/reports')['body'], 'opened from the reports page');
        $pdo->exec("DELETE FROM sales WHERE quantity = 999 AND status = 'cancelled'");
    },
    'year summary permissions: reports only, amounts for financial reports only (106)' => static function (Env $env): void {
        $t = $env->tenant('a');
        $env->employee('a', 'year1', 'YearPass12345', ['reports.view']);
        $v = $env->owner('a', ['year1', 'YearPass12345']);
        $r = $v->get($t['path'] . '/reports/year');
        T::status(200, $r);
        preg_match('#<script type="application/json" id="year-data">(.*?)</script>#s', $r['body'], $m);
        $labels = array_column(json_decode($m[1], true)['lines'], 0);
        T::ok(!in_array('المبيعات', $labels, true), 'no amounts without financial reports');
        T::notContains('class="year-line">المبيعات', $r['body'], 'no amounts in the slides either');
        $env->employee('a', 'year2', 'YearPass12345', ['subscribers.view']);
        T::eq(403, $env->owner('a', ['year2', 'YearPass12345'])->get($t['path'] . '/reports/year')['status'], 'no reports permission: no summary');
    },
    'year summary season on the dashboard and default year (106)' => static function (Env $env): void {
        $y = \App\Services\YearSummary::class;
        T::ok($y::season(mktime(12, 0, 0, 12, 20, 2026)), '20 December: shown');
        T::ok($y::season(mktime(12, 0, 0, 1, 10, 2027)), '10 January: shown');
        T::ok(!$y::season(mktime(12, 0, 0, 6, 10, 2026)), 'June: not shown');
        T::eq(2026, $y::defaultYear(mktime(12, 0, 0, 1, 10, 2027)), 'January: the year that ended');
        T::eq(2026, $y::defaultYear(mktime(12, 0, 0, 12, 20, 2026)), 'December: this year');
        $t = $env->tenant('a');
        $dash = $env->owner('a')->get($t['path'] . '/')['body'];
        T::eq($y::season(), str_contains($dash, 'class="panel year-banner"'), 'dashboard card only in season');
        $css = (string) file_get_contents(PUBLIC_PATH . '/assets/css/pages/year.css');
        T::contains('@media (prefers-reduced-motion: reduce)', $css, 'reduced motion');
        $js = (string) file_get_contents(PUBLIC_PATH . '/assets/js/year.js');
        T::contains("canvas.toBlob(resolve, 'image/png')", $js, 'image made in the browser');
        T::notContains('http://', $js . $css, 'no external service');
        T::notContains('https://', $js . $css, 'no external service');
    },
];
