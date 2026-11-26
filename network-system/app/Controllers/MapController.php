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

    /** إحداثيات من الطلب، أو null إن كانت غير صحيحة */
    private static function latLng(Request $request): ?array
    {
        $lat = trim(latin_digits($request->str('lat')));
        $lng = trim(latin_digits($request->str('lng')));
        if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180) {
            return null;
        }
        return [round((float) $lat, 7), round((float) $lng, 7)];
    }

    /** راوتر على الخريطة باسمه (ليس جهاز MikroTik في النظام): إضافة أو تعديل اسمه وموقعه */
    public function nodeSave(Request $request, ?int $id = null): Response
    {
        $node = $id !== null ? DB::row('SELECT * FROM map_nodes WHERE id = ?', [$id]) : null;
        if ($id !== null && !$node) {
            throw new HttpException(404);
        }
        $name = trim($request->str('name'));
        if ($name === '') {
            return json_response(['ok' => false, 'message' => 'اسم الراوتر مطلوب.', 'errors' => ['name' => 'اسم الراوتر مطلوب.']], 422);
        }
        if (mb_strlen($name) > 120) {
            return json_response(['ok' => false, 'message' => 'الاسم طويل (120 حرفاً على الأكثر).', 'errors' => ['name' => 'الاسم طويل.']], 422);
        }
        $pos = self::latLng($request);
        if ($pos === null && $node === null) {
            return json_response(['ok' => false, 'message' => 'الإحداثيات غير صحيحة.'], 422);
        }
        $data = ['name' => $name, 'updated_at' => now()];
        if ($pos !== null) {
            [$data['lat'], $data['lng']] = $pos;
        }
        if ($node === null) {
            $id = DB::insert('map_nodes', $data + ['created_at' => now()]);
            Audit::log('map.node', 'إضافة الراوتر ' . $name . ' على الخريطة', 'map_node', $id);
            return json_response(['ok' => true, 'id' => $id, 'name' => $name, 'message' => 'تمت إضافة الراوتر إلى الخريطة.']);
        }
        DB::update('map_nodes', $data, 'id = ?', [$id]);
        Audit::log('map.node', 'تعديل الراوتر ' . $name . ($name !== $node['name'] ? ' (كان اسمه ' . $node['name'] . ')' : ''), 'map_node', $id);
        return json_response(['ok' => true, 'id' => $id, 'name' => $name, 'message' => 'تم حفظ الراوتر.']);
    }

    /** حذف راوتر من الخريطة مع روابطه، ويمكن التراجع عنه */
    public function nodeDestroy(Request $request, int $id): Response
    {
        $node = DB::row('SELECT * FROM map_nodes WHERE id = ?', [$id]);
        if (!$node) {
            throw new HttpException(404);
        }
        $where = "(a_type = 'node' AND a_id = ?) OR (b_type = 'node' AND b_id = ?)";
        $delete = static function () use ($id, $node, $where): void {
            DB::delete('map_links', $where, [$id, $id]);
            DB::delete('map_nodes', 'id = ?', [$id]);
            Audit::log('map.node', 'حذف الراوتر ' . $node['name'] . ' من الخريطة', 'map_node', $id);
        };
        $token = $this->undoable('map_nodes', $id, 'map.node', 'حذف الراوتر ' . $node['name'], 'map.manage', $delete, null, [['map_links', $where, [$id, $id]]]);
        return json_response(['ok' => true, 'message' => 'تم حذف الراوتر.', 'undo' => $token]);
    }

    /**
     * طرف رابط من نص «النوع:الرقم» (site:3، router:5، node:2): موجود وضمن نطاق الموظف لأجهزة MikroTik
     * @return array{0:string,1:int}|string النوع والرقم، أو رسالة الخطأ
     */
    private static function linkEnd(string $raw): array|string
    {
        if (!preg_match('/^(site|router|node):([1-9]\d{0,9})$/', $raw, $m)) {
            return 'اختر طرفي الرابط.';
        }
        $table = ['site' => 'sites', 'router' => 'routers', 'node' => 'map_nodes'][$m[1]];
        if (!DB::value("SELECT id FROM $table WHERE id = ?", [(int) $m[2]])) {
            return 'طرف الرابط غير موجود.';
        }
        if ($m[1] === 'router' && !Auth::canRouter((int) $m[2])) {
            throw new HttpException(403);
        }
        return [$m[1], (int) $m[2]];
    }

    /**
     * رابط بين أي نقطتين على الخريطة. بين موقعين: في روابط المواقع نفسها (تظهر في صفحة الموقع كما كانت)،
     * وما فيه جهاز MikroTik أو راوتر: في روابط الخريطة
     */
    public function linkStore(Request $request): Response
    {
        $a = self::linkEnd($request->str('a'));
        $b = self::linkEnd($request->str('b'));
        $type = $request->str('type', 'wireless');
        $capacity = trim($request->str('capacity'));
        $label = trim($request->str('label'));
        $error = null;
        if (is_string($a) || is_string($b)) {
            $error = is_string($a) ? $a : $b;
        } elseif ($a === $b) {
            $error = 'اختر طرفين مختلفين.';
        } elseif (!isset(Geo::LINK_TYPES[$type])) {
            $error = 'نوع الرابط غير صحيح.';
        } elseif (mb_strlen($capacity) > 40 || mb_strlen($label) > 120) {
            $error = 'النص طويل.';
        }
        if ($error === null && $a[0] === 'site' && $b[0] === 'site') {
            if (DB::value('SELECT id FROM site_links WHERE (site_a = ? AND site_b = ?) OR (site_a = ? AND site_b = ?)', [$a[1], $b[1], $b[1], $a[1]])) {
                $error = 'يوجد رابط بين هذين الطرفين.';
            } else {
                $id = DB::insert('site_links', ['site_a' => $a[1], 'site_b' => $b[1], 'type' => $type, 'capacity' => $capacity !== '' ? $capacity : null, 'label' => $label !== '' ? $label : null, 'created_at' => now()]);
                Audit::log('site.save', 'إضافة رابط بين موقعين', 'site_link', $id);
                return json_response(['ok' => true, 'id' => $id, 'message' => 'تمت إضافة الرابط.']);
            }
        }
        if ($error === null && DB::value(
            'SELECT id FROM map_links WHERE (a_type = ? AND a_id = ? AND b_type = ? AND b_id = ?) OR (a_type = ? AND a_id = ? AND b_type = ? AND b_id = ?)',
            [$a[0], $a[1], $b[0], $b[1], $b[0], $b[1], $a[0], $a[1]]
        )) {
            $error = 'يوجد رابط بين هذين الطرفين.';
        }
        if ($error !== null) {
            return json_response(['ok' => false, 'message' => $error, 'errors' => ['b' => $error]], 422);
        }
        $id = DB::insert('map_links', [
            'a_type' => $a[0], 'a_id' => $a[1], 'b_type' => $b[0], 'b_id' => $b[1], 'type' => $type,
            'capacity' => $capacity !== '' ? $capacity : null, 'label' => $label !== '' ? $label : null, 'created_at' => now(),
        ]);
        Audit::log('map.link', 'إضافة رابط على الخريطة: ' . self::endName($a) . ' ⟷ ' . self::endName($b), 'map_link', $id);
        return json_response(['ok' => true, 'id' => $id, 'message' => 'تمت إضافة الرابط.']);
    }

    private static function endName(array $end): string
    {
        $table = ['site' => 'sites', 'router' => 'routers', 'node' => 'map_nodes'][$end[0]];
        return (string) DB::value("SELECT name FROM $table WHERE id = ?", [$end[1]]);
    }

    public function linkDestroy(Request $request, int $id): Response
    {
        $link = DB::row('SELECT * FROM map_links WHERE id = ?', [$id]);
        if (!$link) {
            throw new HttpException(404);
        }
        foreach (['a', 'b'] as $k) {
            if ($link[$k . '_type'] === 'router' && !Auth::canRouter((int) $link[$k . '_id'])) {
                throw new HttpException(403);
            }
        }
        $delete = static function () use ($id): void {
            DB::delete('map_links', 'id = ?', [$id]);
            Audit::log('map.link', 'حذف رابط على الخريطة', 'map_link', $id);
        };
        $token = $this->undoable('map_links', $id, 'map.link', 'حذف رابط على الخريطة', 'map.manage', $delete);
        return json_response(['ok' => true, 'message' => 'تم حذف الرابط.', 'undo' => $token]);
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
            'a' => ['of' => 'site', 'id' => (int) $l['site_a'], 'name' => $l['a_name'], 'lat' => (float) $l['a_lat'], 'lng' => (float) $l['a_lng']],
            'b' => ['of' => 'site', 'id' => (int) $l['site_b'], 'name' => $l['b_name'], 'lat' => (float) $l['b_lat'], 'lng' => (float) $l['b_lng']],
        ], DB::rows(
            'SELECT l.*, a.name AS a_name, a.lat AS a_lat, a.lng AS a_lng, b.name AS b_name, b.lat AS b_lat, b.lng AS b_lng
             FROM site_links l JOIN sites a ON a.id = l.site_a JOIN sites b ON b.id = l.site_b
             WHERE a.lat IS NOT NULL AND b.lat IS NOT NULL'
        ));

        // راوترات الخريطة، وروابط أطرافها أي نقطة ظاهرة لهذا الموظف (جهاز MikroTik خارج نطاقه لا يظهر ولا روابطه)
        $nodes = array_map(static fn ($n) => ['id' => (int) $n['id'], 'name' => $n['name'], 'lat' => (float) $n['lat'], 'lng' => (float) $n['lng']],
            DB::rows('SELECT id, name, lat, lng FROM map_nodes ORDER BY name'));
        $at = ['site' => [], 'router' => [], 'node' => []];
        foreach (['site' => $sites, 'router' => $routers, 'node' => $nodes] as $of => $list) {
            foreach ($list as $p) {
                $at[$of][$p['id']] = ['of' => $of, 'id' => $p['id'], 'name' => $p['name'], 'lat' => $p['lat'], 'lng' => $p['lng']];
            }
        }
        foreach (DB::rows('SELECT * FROM map_links ORDER BY id') as $l) {
            $a = $at[$l['a_type']][(int) $l['a_id']] ?? null;
            $b = $at[$l['b_type']][(int) $l['b_id']] ?? null;
            if ($a !== null && $b !== null) {
                $links[] = ['id' => 'm' . $l['id'], 'type' => $l['type'], 'capacity' => $l['capacity'], 'label' => $l['label'], 'a' => $a, 'b' => $b];
            }
        }

        return json_response([
            'ok' => true, 'sites' => $sites, 'routers' => $routers, 'nodes' => $nodes, 'subscribers' => $subscribers, 'links' => $links,
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
        $table = ['site' => 'sites', 'router' => 'routers', 'subscriber' => 'subscribers', 'node' => 'map_nodes'][$type] ?? null;
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
