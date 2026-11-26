<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Geo;
use App\Services\SubscriberRules;

final class MapController extends Controller
{
    public function index(Request $request): Response
    {
        [$rScope, $rParams] = Auth::routerScope('r.id');
        return view('map/index', [
            'view' => Geo::view(),
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
            'sites' => Geo::sitePairs(),
            // أجهزة MikroTik للإضافة على الخريطة: ما ليس له موقع أولاً
            'routers' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'placed' => (bool) $r['placed']], DB::rows(
                "SELECT r.id, r.name, (COALESCE(r.lat, s.lat) IS NOT NULL) AS placed FROM routers r LEFT JOIN sites s ON s.id = r.site_id WHERE 1 = 1$rScope ORDER BY placed, r.name",
                $rParams
            )),
        ]);
    }

    /** وضع جهاز MikroTik على الخريطة (أو نقله)، مع تعديل اسمه لمن يملك صلاحية إدارة الأجهزة */
    public function placeRouter(Request $request): Response
    {
        $id = $request->int('id');
        $router = DB::row('SELECT id, name FROM routers WHERE id = ?', [$id]);
        if (!$router) {
            return json_response(['ok' => false, 'message' => 'اختر جهاز MikroTik.'], 422);
        }
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        $lat = trim(latin_digits($request->str('lat')));
        $lng = trim(latin_digits($request->str('lng')));
        if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180) {
            return json_response(['ok' => false, 'message' => 'الإحداثيات غير صحيحة.'], 422);
        }
        $update = ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7), 'updated_at' => now()];
        $name = trim($request->str('name'));
        if ($name !== '' && $name !== $router['name'] && can('routers.manage')) {
            if (mb_strlen($name) > 100) {
                return json_response(['ok' => false, 'message' => 'الاسم طويل (100 حرف على الأكثر).'], 422);
            }
            $update['name'] = $name;
        }
        DB::update('routers', $update, 'id = ?', [$id]);
        Audit::log('map.move', 'وضع جهاز MikroTik ' . ($update['name'] ?? $router['name']) . ' على الخريطة' . (isset($update['name']) ? ' (كان اسمه ' . $router['name'] . ')' : ''), 'router', $id);
        return json_response(['ok' => true, 'id' => $id, 'name' => $update['name'] ?? $router['name'], 'message' => 'تمت إضافة الجهاز إلى الخريطة.']);
    }

    /** إزالة الموقع الخاص بجهاز MikroTik (يعود إلى موقع برجه إن كان له برج على الخريطة) */
    public function unplaceRouter(Request $request, int $id): Response
    {
        $router = DB::row('SELECT id, name FROM routers WHERE id = ?', [$id]);
        if (!$router) {
            throw new HttpException(404);
        }
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        DB::update('routers', ['lat' => null, 'lng' => null, 'updated_at' => now()], 'id = ?', [$id]);
        Audit::log('map.move', 'إزالة جهاز MikroTik ' . $router['name'] . ' من الخريطة', 'router', $id);
        return json_response(['ok' => true, 'message' => 'تمت إزالة الجهاز من الخريطة.']);
    }

    /** بيانات الخريطة: المواقع، أجهزة MikroTik بحالتها، المشتركون، الروابط */
    public function data(Request $request): Response
    {
        [$rScope, $rParams] = Auth::routerScope('r.id');
        [$sScope, $sParams] = Auth::routerScope('s.router_id', true);

        $sites = array_map(static fn ($s) => [
            'id' => (int) $s['id'], 'name' => $s['name'], 'type' => $s['type'], 'lat' => (float) $s['lat'], 'lng' => (float) $s['lng'],
            'area' => $s['area_name'], 'height' => $s['height_m'] !== null ? (int) $s['height_m'] : null, 'address' => $s['address'], 'notes' => $s['notes'],
            'area_id' => $s['area_id'] !== null ? (int) $s['area_id'] : null,
            'routers' => (int) $s['routers'], 'subscribers' => (int) $s['subscribers'], 'units' => (int) $s['units'],
        ], DB::rows(
            'SELECT s.*, a.name AS area_name,
                    (SELECT COUNT(*) FROM routers r WHERE r.site_id = s.id) AS routers,
                    (SELECT COUNT(*) FROM subscribers x WHERE x.site_id = s.id) AS subscribers,
                    (SELECT COUNT(*) FROM inventory_units u WHERE u.site_id = s.id AND u.status = \'installed\') AS units
             FROM sites s LEFT JOIN areas a ON a.id = s.area_id WHERE s.lat IS NOT NULL AND s.lng IS NOT NULL ORDER BY s.name'
        ));

        $routers = array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'status' => $r['status'],
            'lat' => (float) $r['plat'], 'lng' => (float) $r['plng'], 'own' => $r['lat'] !== null,
            'clients' => (int) $r['active_hotspot'] + (int) $r['active_ppp'], 'cpu' => $r['cpu_load'] !== null ? (int) $r['cpu_load'] : null,
            'site' => $r['site_name'], 'site_id' => $r['site_id'] !== null ? (int) $r['site_id'] : null,
            'bps' => (int) $r['rx_bps'] + (int) $r['tx_bps'],
        ], DB::rows(
            "SELECT r.id, r.name, r.status, r.lat, r.lng, r.active_hotspot, r.active_ppp, r.cpu_load, r.site_id, r.rx_bps, r.tx_bps, s.name AS site_name,
                    COALESCE(r.lat, s.lat) AS plat, COALESCE(r.lng, s.lng) AS plng
             FROM routers r LEFT JOIN sites s ON s.id = r.site_id
             WHERE COALESCE(r.lat, s.lat) IS NOT NULL AND COALESCE(r.lng, s.lng) IS NOT NULL$rScope ORDER BY r.name",
            $rParams
        ));

        $subscribers = [];
        if (can('subscribers.view')) {
            // المتصلون الآن مرة واحدة من فهرس الجلسات المفتوحة، بدل استعلام فرعي لكل مشترك
            // (كان يمر على كل الجلسات المفتوحة لكل مشترك: 5,000 × 1,500 صف ≈ 4.4 ثانية لبيانات الخريطة)
            $online = array_flip(array_map('intval', DB::column('SELECT DISTINCT subscriber_id FROM sessions WHERE stopped_at IS NULL AND subscriber_id IS NOT NULL')));
            foreach (DB::rows(
                "SELECT s.id, s.full_name, s.status, s.expires_at, s.lat, s.lng, p.name AS plan_name, st.name AS site_name,
                        COALESCE(s.lat, st.lat) AS plat, COALESCE(s.lng, st.lng) AS plng
                 FROM subscribers s JOIN plans p ON p.id = s.plan_id LEFT JOIN sites st ON st.id = s.site_id
                 WHERE s.lat IS NOT NULL AND s.lng IS NOT NULL$sScope ORDER BY s.full_name LIMIT 5000",
                $sParams
            ) as $s) {
                $subscribers[] = [
                    'id' => (int) $s['id'], 'name' => $s['full_name'], 'plan' => $s['plan_name'], 'site' => $s['site_name'],
                    'state' => SubscriberRules::displayState($s), 'online' => isset($online[(int) $s['id']]),
                    'lat' => (float) $s['plat'], 'lng' => (float) $s['plng'],
                ];
            }
        }

        $links = array_map(static fn ($l) => [
            'id' => (int) $l['id'], 'type' => $l['type'], 'capacity' => $l['capacity'], 'label' => $l['label'],
            'a' => ['id' => (int) $l['site_a'], 'name' => $l['a_name'], 'lat' => (float) $l['a_lat'], 'lng' => (float) $l['a_lng']],
            'b' => ['id' => (int) $l['site_b'], 'name' => $l['b_name'], 'lat' => (float) $l['b_lat'], 'lng' => (float) $l['b_lng']],
        ], DB::rows(
            'SELECT l.*, a.name AS a_name, a.lat AS a_lat, a.lng AS a_lng, b.name AS b_name, b.lat AS b_lat, b.lng AS b_lng
             FROM site_links l JOIN sites a ON a.id = l.site_a JOIN sites b ON b.id = l.site_b
             WHERE a.lat IS NOT NULL AND b.lat IS NOT NULL'
        ));

        return json_response([
            'ok' => true, 'sites' => $sites, 'routers' => $routers, 'subscribers' => $subscribers, 'links' => $links,
            'unplaced' => [
                'sites' => (int) DB::value('SELECT COUNT(*) FROM sites WHERE lat IS NULL OR lng IS NULL'),
                'routers' => (int) DB::value("SELECT COUNT(*) FROM routers r LEFT JOIN sites s ON s.id = r.site_id WHERE COALESCE(r.lat, s.lat) IS NULL$rScope", $rParams),
            ],
        ]);
    }

    /**
     * البندان 58 و59: حالة أجهزة MikroTik وحركتها الحالية للخريطة (داخل التحديث المجمّع /live كل 30 ثانية، ضمن نطاق الموظف)
     * @return list<array{id:int,status:string,clients:int,bps:int}>
     */
    public static function live(): array
    {
        [$scope, $params] = Auth::routerScope('r.id');
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'status' => (string) $r['status'], 'clients' => (int) $r['active_hotspot'] + (int) $r['active_ppp'],
            'bps' => (int) $r['rx_bps'] + (int) $r['tx_bps'],
        ], DB::rows('SELECT r.id, r.status, r.active_hotspot, r.active_ppp, r.rx_bps, r.tx_bps FROM routers r WHERE r.is_active = 1' . $scope, $params));
    }

    /**
     * البند 93: الاستهلاك الحقيقي لكل برج ولكل منطقة خلال آخر 24 ساعة (من الجلسات)، ضمن نطاق الموظف.
     * استعلامان بالفهرس (جلسات بدأت في آخر 24 ساعة + المفتوحة الأقدم) بدل المرور على كل الجلسات، ومخزّن 10 دقائق.
     */
    public function heat(Request $request): Response
    {
        \App\Core\Session::release();
        [$scope, $params] = Auth::routerScope('r.id');
        $since = date('Y-m-d H:i:s', time() - 86400);
        $out = \App\Core\Cache::remember('map.heat', [substr(now(), 0, 15)], ['sessions', 'routers', 'sites', 'areas'], static function () use ($scope, $params, $since): array {
            $rows = DB::rows(
                "SELECT s.id, s.lat, s.lng, a.name AS area, SUM(t.b) AS bytes FROM (
                    SELECT x.router_id, x.bytes_in + x.bytes_out AS b FROM sessions x WHERE x.started_at >= ?
                    UNION ALL
                    SELECT x.router_id, x.bytes_in + x.bytes_out AS b FROM sessions x WHERE x.stopped_at IS NULL AND x.started_at < ?
                 ) t JOIN routers r ON r.id = t.router_id JOIN sites s ON s.id = r.site_id LEFT JOIN areas a ON a.id = s.area_id
                 WHERE s.lat IS NOT NULL AND s.lng IS NOT NULL$scope GROUP BY s.id, s.lat, s.lng, a.name",
                array_merge([$since, $since], $params)
            );
            $areas = [];
            foreach ($rows as $r) {
                $k = (string) ($r['area'] ?? '');
                $areas[$k] = ($areas[$k] ?? 0) + (int) $r['bytes'];
            }
            arsort($areas);
            return [
                'sites' => array_map(static fn ($r) => [(int) $r['id'], (float) $r['lat'], (float) $r['lng'], (int) $r['bytes']], $rows),
                'areas' => array_map(static fn ($k, $v) => [$k === '' ? null : $k, $v], array_keys($areas), array_values($areas)),
            ];
        }, 600);
        return json_response(['ok' => true] + $out);
    }

    /**
     * البند 94: إعادة تشغيل يوم: لكل برج ولكل 15 دقيقة عدد أجهزة MikroTik المتصلة والمنقطعة والمتصلين،
     * من قراءات المراقبة المخزنة أصلاً (router_metrics، مدة الاحتفاظ retention.metrics_days) فلا جدول جديد.
     */
    public function replay(Request $request): Response
    {
        \App\Core\Session::release();
        $keep = max(1, Settings::int('retention.metrics_days', 30));
        $date = $request->str('date');
        $today = date('Y-m-d');
        $min = date('Y-m-d', time() - ($keep - 1) * 86400);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today || $date < $min || strtotime($date) === false) {
            $date = $today;
        }
        $start = strtotime($date . ' 00:00:00');
        [$scope, $params] = Auth::routerScope('r.id');
        $rows = DB::rows(
            "SELECT r.site_id, m.router_id, FLOOR((UNIX_TIMESTAMP(m.created_at) - ?) / 900) AS slot,
                    MAX(m.online) AS up, AVG(COALESCE(m.active_hotspot, 0) + COALESCE(m.active_ppp, 0)) AS clients
             FROM router_metrics m JOIN routers r ON r.id = m.router_id
             WHERE m.created_at >= ? AND m.created_at < ? AND r.site_id IS NOT NULL$scope
             GROUP BY r.site_id, m.router_id, slot",
            array_merge([$start, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start + 86400)], $params)
        );
        // لكل برج 96 خانة: 0 (لا قراءات) أو [متصلة، منقطعة، المتصلون]
        $sites = [];
        foreach ($rows as $r) {
            $sid = (int) $r['site_id'];
            $slot = (int) $r['slot'];
            if ($slot < 0 || $slot > 95) {
                continue;
            }
            $sites[$sid] ??= array_fill(0, 96, 0);
            $cell = $sites[$sid][$slot] ?: [0, 0, 0];
            if ((int) $r['up'] === 1) {
                $cell[0]++;
                $cell[2] += (int) round((float) $r['clients']);
            } else {
                $cell[1]++;
            }
            $sites[$sid][$slot] = $cell;
        }
        return json_response(['ok' => true, 'date' => $date, 'min' => $min, 'max' => $today, 'start' => $start, 'sites' => $sites]);
    }

    /** تحريك عنصر على الخريطة */
    public function position(Request $request): Response
    {
        $type = $request->str('type');
        $id = $request->int('id');
        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return json_response(['ok' => false, 'message' => 'الإحداثيات غير صحيحة.'], 422);
        }
        $table = ['site' => 'sites', 'router' => 'routers', 'subscriber' => 'subscribers'][$type] ?? null;
        if ($table === null || !DB::value("SELECT id FROM $table WHERE id = ?", [$id])) {
            throw new HttpException(404);
        }
        if ($type === 'router' && !Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        if ($type === 'subscriber') {
            $routerId = DB::value('SELECT router_id FROM subscribers WHERE id = ?', [$id]);
            if (!can('subscribers.manage') || ($routerId && !Auth::canRouter((int) $routerId))) {
                throw new HttpException(403);
            }
        }
        DB::update($table, ['lat' => round($lat, 7), 'lng' => round($lng, 7), 'updated_at' => now()], 'id = ?', [$id]);
        Audit::log('map.move', 'تحديث موقع على الخريطة', $type, $id);
        return json_response(['ok' => true]);
    }

    /** حفظ العرض الحالي كعرض افتراضي */
    public function saveView(Request $request): Response
    {
        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');
        $zoom = max(3, min(19, $request->int('zoom', 12)));
        Settings::setMany(['map.lat' => (string) round($lat, 6), 'map.lng' => (string) round($lng, 6), 'map.zoom' => (string) $zoom]);
        return json_response(['ok' => true, 'message' => 'تم حفظ العرض الافتراضي.']);
    }
}
