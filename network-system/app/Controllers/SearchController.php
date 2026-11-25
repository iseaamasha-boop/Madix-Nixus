<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Support\ArabicSearch;

final class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim(latin_digits($request->str('q')));
        // لوحة الأوامر (Ctrl K) تطلب نسخة JSON قصيرة أثناء الكتابة: البحث نفسه بالصلاحيات نفسها، وأسماء الكروت من بدايتها (بالفهرس)
        $json = $request->wantsJson();
        if ($json) {
            Session::release();
            $q = mb_substr($q, 0, 60);
        }
        $results = ['cards' => [], 'subscribers' => [], 'devices' => [], 'routers' => [], 'units' => [], 'sites' => []];
        if ($q !== '') {
            $like = DB::like($q);
            if (can('cards.view') || can('check.use')) {
                $params = [$q, $q, $q, $json ? addcslashes($q, '%_\\') . '%' : $like];
                $sql = 'SELECT c.id, c.username, c.short_code, c.status, c.is_disabled, cat.name AS category_name
                        FROM cards c JOIN categories cat ON cat.id = c.category_id
                        WHERE c.username = ? OR c.short_code = ? OR c.ext_serial = ? OR c.username LIKE ?';
                if (preg_match('/^#?(\d+)$/', $q, $m)) {
                    $sql .= ' OR c.id = ?';
                    $params[] = (int) $m[1];
                }
                $results['cards'] = DB::rows($sql . ' ORDER BY c.id DESC LIMIT 20', $params);
                // الإرسال المباشر عند تطابق واحد تام
                if (!$json && count($results['cards']) === 1 && in_array($q, [$results['cards'][0]['username'], $results['cards'][0]['short_code']], true) && can('check.use')) {
                    return redirect('/check/card/' . $results['cards'][0]['id']);
                }
            }
            if (can('subscribers.view')) {
                // نطاق أجهزة MikroTik المسموحة للموظف، والاسم بالبحث العربي الذكي
                [$scope, $sp] = Auth::routerScope('s.router_id', true);
                [$nameCond, $nameVal] = ArabicSearch::cond('s.full_name', $q);
                $results['subscribers'] = DB::rows(
                    'SELECT s.id, s.full_name, s.username, s.phone, s.expires_at, s.status, p.name AS plan_name
                     FROM subscribers s JOIN plans p ON p.id = s.plan_id
                     WHERE (s.username LIKE ? OR ' . $nameCond . ' OR s.phone LIKE ? OR s.phone2 LIKE ? OR s.national_id = ?)' . $scope . '
                     ORDER BY s.full_name LIMIT 20',
                    array_merge([$like, $nameVal, $like, $like, $q], $sp)
                );
            }
            if (can('devices.view') || can('sessions.view')) {
                $mac = normalize_mac($q);
                $results['devices'] = $mac
                    ? DB::rows('SELECT * FROM devices WHERE mac = ? LIMIT 20', [$mac])
                    : DB::rows('SELECT * FROM devices WHERE hostname LIKE ? OR last_ip = ? ORDER BY last_seen DESC LIMIT 20', [$like, $q]);
            }
            if (can('routers.view')) {
                [$scope, $sp] = Auth::routerScope('id');
                [$nameCond, $nameVal] = ArabicSearch::cond('name', $q);
                $results['routers'] = DB::rows('SELECT id, name, host, status FROM routers WHERE (' . $nameCond . ' OR host LIKE ? OR identity LIKE ?)' . $scope . ' LIMIT 10', array_merge([$nameVal, $like, $like], $sp));
            }
            if (can('inventory.view')) {
                $mac = normalize_mac($q);
                $results['units'] = DB::rows(
                    'SELECT u.id, u.serial, u.mac, u.status, i.name AS item_name FROM inventory_units u JOIN inventory_items i ON i.id = u.item_id
                     WHERE u.serial LIKE ?' . ($mac ? ' OR u.mac = ?' : '') . ' ORDER BY u.id DESC LIMIT 10',
                    $mac ? [$like, $mac] : [$like]
                );
            }
            if (can('map.view')) {
                [$nameCond, $nameVal] = ArabicSearch::cond('name', $q);
                [$addrCond, $addrVal] = ArabicSearch::cond('address', $q);
                $results['sites'] = DB::rows('SELECT id, name, type, address FROM sites WHERE ' . $nameCond . ' OR ' . $addrCond . ' ORDER BY name LIMIT 10', [$nameVal, $addrVal]);
            }
        }
        if ($json) {
            $out = ['ok' => true, 'q' => $q, 'items' => self::items($results)];
            if ($request->str('actions') === '1') {
                $out['actions'] = self::actions();
            }
            return json_response($out);
        }
        return view('search/index', compact('q', 'results'));
    }

    /** إجراءات سريعة للوحة الأوامر: ما يسمح به دور الموظف فقط */
    private static function actions(): array
    {
        $list = [
            ['مشترك جديد', 'user-plus', '/subscribers/create', 'subscribers.manage'],
            ['توليد كروت', 'wand-sparkles', '/cards/generate', 'cards.generate'],
            ['بيع كروت', 'shopping-cart', '/sales/create', 'sales.create'],
            ['فحص كرت', 'scan-line', '/check', 'check.use'],
            ['إضافة جهاز MikroTik', 'router', '/routers/create', 'routers.manage'],
            ['باقة كروت جديدة', 'layers', '/categories/create', 'categories.manage'],
            ['باقة اشتراك جديدة', 'package', '/plans/create', 'plans.manage'],
            ['موزع جديد', 'store', '/distributors/create', 'distributors.manage'],
            ['مهمة صيانة جديدة', 'wrench', '/maintenance/create', 'maintenance.manage'],
            ['موظف جديد', 'user-cog', '/employees/create', 'employees.manage'],
            ['إرسال رسالة نصية', 'send', '/sms', 'sms.send'],
        ];
        $out = [];
        foreach ($list as [$label, $icon, $path, $perm]) {
            if (can($perm)) {
                $out[] = ['type' => 'action', 'icon' => $icon, 'title' => $label, 'sub' => '', 'url' => url($path)];
            }
        }
        return $out;
    }

    /** نتائج مختصرة للوحة الأوامر: خمس من كل نوع */
    private static function items(array $r): array
    {
        $out = [];
        $add = static function (string $type, string $icon, string $title, string $sub, string $url) use (&$out): void {
            $out[] = ['type' => $type, 'icon' => $icon, 'title' => $title, 'sub' => $sub, 'url' => $url];
        };
        foreach (array_slice($r['subscribers'], 0, 5) as $s) {
            $add('subscriber', 'user', (string) $s['full_name'], $s['username'] . ($s['phone'] ? ' · ' . $s['phone'] : '') . ' · ' . $s['plan_name'], url('/subscribers/' . $s['id']));
        }
        foreach (array_slice($r['cards'], 0, 5) as $c) {
            $add('card', 'ticket', (string) $c['username'], (string) $c['category_name'], url('/check/card/' . $c['id']));
        }
        foreach (array_slice($r['routers'], 0, 5) as $x) {
            $add('router', 'router', (string) $x['name'], (string) $x['host'], url('/routers/' . $x['id']));
        }
        foreach (array_slice($r['sites'], 0, 5) as $x) {
            $add('site', 'map-pin', (string) $x['name'], (string) ($x['address'] ?? ''), url('/sites/' . $x['id']));
        }
        foreach (array_slice($r['devices'], 0, 5) as $d) {
            $add('device', 'smartphone', (string) ($d['hostname'] ?: $d['mac']), (string) ($d['last_ip'] ?? $d['mac']), url('/sessions/history', ['q' => $d['mac']]));
        }
        foreach (array_slice($r['units'], 0, 5) as $u) {
            $add('unit', 'hard-drive', (string) $u['item_name'], (string) ($u['serial'] ?: $u['mac']), url('/inventory/units/' . $u['id']));
        }
        return $out;
    }
}
