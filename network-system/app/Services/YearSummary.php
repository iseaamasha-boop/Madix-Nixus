<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\DB;

/**
 * البند 106: ملخص السنة من بيانات الشبكة الحقيقية — الكروت المباعة، أقوى شهر، أحسن برج، الباقة الأكثر مبيعاً،
 * المشتركون الجدد والتجديدات والاستهلاك. يُحسب مرة كل ساعة (كاش)، والمبالغ لمن يملك صلاحية التقارير المالية،
 * وأحسن برج ضمن أجهزة MikroTik المسموحة للموظف.
 */
final class YearSummary
{
    public const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    /** السنة الافتراضية: السابقة في يناير (ملخص سنة مكتملة)، وإلا الحالية حتى اليوم */
    public static function defaultYear(?int $now = null): int
    {
        $now ??= time();
        return (int) date('n', $now) === 1 ? (int) date('Y', $now) - 1 : (int) date('Y', $now);
    }

    /** يظهر على لوحة التحكم من 15 ديسمبر حتى 15 يناير */
    public static function season(?int $now = null): bool
    {
        $now ??= time();
        $md = (int) date('nd', $now);
        return $md >= 1215 || $md <= 115;
    }

    /** السنوات التي فيها مبيعات أو مشتركون */
    public static function years(): array
    {
        $first = DB::value("SELECT MIN(y) FROM (SELECT YEAR(MIN(created_at)) AS y FROM sales UNION ALL SELECT YEAR(MIN(created_at)) FROM subscribers) t");
        $from = $first ? (int) $first : (int) date('Y');
        return range((int) date('Y'), max($from, (int) date('Y') - 9));
    }

    public static function build(int $year): array
    {
        [$scope, $params] = Auth::routerScope('r.id');
        return Cache::remember('year.summary', [$year], ['sales', 'cards', 'categories', 'sites', 'routers', 'subscribers', 'subscriber_transactions'],
            static function () use ($year, $scope, $params): array {
                $from = sprintf('%04d-01-01 00:00:00', $year);
                $to = sprintf('%04d-01-01 00:00:00', $year + 1);
                $range = [$from, $to];
                $sales = "status = 'completed' AND created_at >= ? AND created_at < ?";
                $total = DB::row("SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(total), 0) AS amount, COUNT(*) AS n FROM sales WHERE $sales", $range);
                $months = array_fill(1, 12, ['qty' => 0, 'amount' => 0.0]);
                foreach (DB::rows("SELECT MONTH(created_at) AS m, SUM(quantity) AS qty, SUM(total) AS amount FROM sales WHERE $sales GROUP BY MONTH(created_at)", $range) as $r) {
                    $months[(int) $r['m']] = ['qty' => (int) $r['qty'], 'amount' => (float) $r['amount']];
                }
                $best = 0;
                foreach ($months as $m => $v) {
                    if ($v['qty'] > 0 && ($best === 0 || $v['amount'] > $months[$best]['amount'] || ($v['amount'] == $months[$best]['amount'] && $v['qty'] > $months[$best]['qty']))) {
                        $best = $m;
                    }
                }
                $topCategory = DB::row("SELECT c.name, SUM(s.quantity) AS qty FROM sales s JOIN categories c ON c.id = s.category_id
                    WHERE s.status = 'completed' AND s.created_at >= ? AND s.created_at < ? GROUP BY s.category_id, c.name ORDER BY qty DESC LIMIT 1", $range);
                // أحسن برج: أكثر دخول للكروت أول مرة على أجهزة MikroTik فيه خلال السنة، ومعه استهلاكها
                $tower = DB::row("SELECT st.name, COUNT(*) AS logins, COALESCE(SUM(c.used_bytes_in + c.used_bytes_out), 0) AS bytes
                    FROM cards c JOIN routers r ON r.id = c.last_router_id JOIN sites st ON st.id = r.site_id
                    WHERE c.first_login_at >= ? AND c.first_login_at < ?$scope GROUP BY st.id, st.name ORDER BY logins DESC LIMIT 1", array_merge($range, $params));
                $usage = DB::row('SELECT COUNT(*) AS cards, COALESCE(SUM(used_bytes_in + used_bytes_out), 0) AS bytes FROM cards WHERE first_login_at >= ? AND first_login_at < ?', $range);
                $renew = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM subscriber_transactions WHERE type = 'renewal' AND created_at >= ? AND created_at < ?", $range);
                return [
                    'year' => $year,
                    'cards_sold' => (int) $total['qty'],
                    'revenue' => (float) $total['amount'],
                    'months' => array_values(array_map(static fn ($v) => [$v['qty'], round($v['amount'], 2)], $months)),
                    'best_month' => $best ?: null,
                    'top_category' => $topCategory ? ['name' => $topCategory['name'], 'qty' => (int) $topCategory['qty']] : null,
                    'tower' => $tower ? ['name' => $tower['name'], 'logins' => (int) $tower['logins'], 'bytes' => (int) $tower['bytes']] : null,
                    'cards_used' => (int) $usage['cards'],
                    'bytes' => (int) $usage['bytes'],
                    'new_subscribers' => (int) DB::value('SELECT COUNT(*) FROM subscribers WHERE created_at >= ? AND created_at < ?', $range),
                    'renewals' => (int) $renew['n'],
                    'renewals_amount' => (float) $renew['amount'],
                ];
            }, 3600);
    }
}
