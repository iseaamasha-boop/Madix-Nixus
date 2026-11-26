<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\HttpException;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Platform\Demo;
use Throwable;

/**
 * البند 105 و105+: الشبكة التجريبية في لوحة المسؤول — التشغيل والإيقاف وإعادة التجهيز، وزوارها:
 * من فتحها وعدد مرات الفتح، وتفاصيل كل زيارة (الوقت، الجهاز والمتصفح والنظام، الدولة، المصدر، المدة) والصفحات التي زارها.
 */
final class AdminDemoController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $page = max(1, $request->int('page', 1));
        $since = date('Y-m-d H:i:s', time() - 30 * 86400);
        $stats = Platform::row(
            'SELECT COUNT(DISTINCT visitor) AS visitors, COUNT(*) AS opens, COALESCE(SUM(started_at >= ?), 0) AS today,
                    COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, last_at)), 0) AS duration, COALESCE(AVG(pages), 0) AS pages
             FROM demo_visits',
            [date('Y-m-d 00:00:00')]
        ) ?: [];
        $total = (int) ($stats['opens'] ?? 0);
        $visits = Platform::rows(
            'SELECT v.*, TIMESTAMPDIFF(SECOND, v.started_at, v.last_at) AS duration,
                    (SELECT COUNT(*) FROM demo_visits x WHERE x.visitor = v.visitor) AS opens
             FROM demo_visits v ORDER BY v.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE)
        );
        $group = static fn (string $col) => Platform::rows("SELECT $col AS k, COUNT(*) AS n FROM demo_visits WHERE started_at >= ? GROUP BY $col ORDER BY n DESC LIMIT 8", [$since]);
        return view('admin/demo', [
            'tenant' => Demo::tenant(),
            'enabled' => Demo::enabled(),
            'resetAt' => Platform::setting('demo.reset_at', ''),
            'stats' => $stats,
            'visits' => $visits,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'countries' => $group('country'),
            'sources' => $group('source'),
            'devices' => $group('device'),
            'topPages' => Platform::rows(
                'SELECT p.path, MAX(p.title) AS title, COUNT(*) AS n FROM demo_pageviews p WHERE p.created_at >= ? GROUP BY p.path ORDER BY n DESC LIMIT 10',
                [$since]
            ),
        ], 'admin');
    }

    public function visit(Request $request, int $id): Response
    {
        $visit = Platform::row('SELECT v.*, TIMESTAMPDIFF(SECOND, v.started_at, v.last_at) AS duration FROM demo_visits v WHERE v.id = ?', [$id]);
        if (!$visit) {
            throw new HttpException(404);
        }
        return view('admin/demo-visit', [
            'visit' => $visit,
            'views' => Platform::rows('SELECT path, title, created_at FROM demo_pageviews WHERE visit_id = ? ORDER BY created_at, id', [$id]),
            'others' => Platform::rows('SELECT id, started_at, pages, TIMESTAMPDIFF(SECOND, started_at, last_at) AS duration FROM demo_visits WHERE visitor = ? AND id <> ? ORDER BY id DESC LIMIT 20', [$visit['visitor'], $id]),
        ], 'admin');
    }

    public function toggle(Request $request): Response
    {
        $on = $request->bool('enabled');
        try {
            if ($on) {
                @set_time_limit(180);
                Demo::enable();
            } else {
                Demo::disable();
            }
        } catch (Throwable $e) {
            log_message('error', 'demo toggle: ' . $e->getMessage());
            return $this->backWithErrors($request, ['enabled' => 'تعذّر تجهيز الشبكة التجريبية.'], '/admin/demo');
        }
        Platform::log(null, 'admin', 'platform.demo', $on ? 'تشغيل الشبكة التجريبية' : 'إيقاف الشبكة التجريبية', AdminAuth::user()['name']);
        return $this->ok($on ? 'الشبكة التجريبية مفتوحة للزوار.' : 'الشبكة التجريبية موقوفة.', '/admin/demo');
    }

    public function reset(Request $request): Response
    {
        $t = Demo::tenant();
        if (!$t) {
            throw new HttpException(404);
        }
        @set_time_limit(180);
        Demo::build($t);
        Platform::log(null, 'admin', 'platform.demo', 'إعادة تجهيز الشبكة التجريبية', AdminAuth::user()['name']);
        return $this->ok('أُعيدت الشبكة التجريبية إلى أصلها.', '/admin/demo');
    }
}
