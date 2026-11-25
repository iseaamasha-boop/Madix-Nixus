<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * التحديثات المباشرة بطلب واحد مجمّع: كل جزء حي في الصفحة (أرقام اللوحة، الرسوم، من الموجود) يسجّل مفتاحه في
 * SN.live على المتصفح، فيُطلب كل ما تحتاجه الصفحة معاً كل فترة، ويتوقف الطلب ما دام التبويب مخفياً.
 * كل مفتاح بصلاحيته: ما لا يحق للموظف لا يُرجع أصلاً.
 */
final class LiveController extends Controller
{
    /** المفتاح => [الصلاحية (null = أي موظف داخل)، الدالة] */
    private static function providers(): array
    {
        return [
            'dashboard' => ['dashboard.view', static function (Request $r): array {
                DashboardController::scheduleCron();
                return DashboardController::liveData();
            }],
        ];
    }

    /** مفاتيح إضافية تسجّلها البنود الأخرى (الرسوم الحية، من الموجود الآن...) */
    private static array $extra = [];

    public static function register(string $key, ?string $perm, callable $fn): void
    {
        self::$extra[$key] = [$perm, $fn];
    }

    public function index(Request $request): Response
    {
        Session::release();
        $keys = array_slice(array_values(array_unique(array_filter(explode(',', $request->str('k'))))), 0, 10);
        $providers = self::providers() + self::$extra;
        $out = [];
        foreach ($keys as $key) {
            if (!isset($providers[$key])) {
                continue;
            }
            [$perm, $fn] = $providers[$key];
            if ($perm !== null && !can($perm)) {
                continue;
            }
            $out[$key] = $fn($request);
        }
        return json_response(['ok' => true, 'time' => date('H:i:s'), 'data' => $out]);
    }
}
