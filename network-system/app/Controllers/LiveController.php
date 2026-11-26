<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
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
            // البند 57: رقم آخر تنبيه انقطاع (جهاز MikroTik أو انقطاع منطقة) ضمن نطاق الموظف؛ المتصفح يعزف تنبيهاً حين يزيد
            'sound' => ['alerts.view', static function (Request $r): array {
                [$scope, $params] = Auth::routerScope('a.router_id', true);
                return ['down' => (int) \App\Core\DB::value("SELECT MAX(a.id) FROM alerts a WHERE a.type IN ('router_down', 'outage')" . $scope, $params)];
            }],
            // البند 107: نبضة هذا التبويب، ومن غيره على الصفحة نفسها الآن (أي موظف داخل، في شبكته فقط)
            'presence' => [null, static function (Request $r): array {
                return \App\Services\Presence::enabled() ? \App\Services\Presence::ping((int) Auth::id(), $r->str('tab'), $r->str('p')) : [];
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
