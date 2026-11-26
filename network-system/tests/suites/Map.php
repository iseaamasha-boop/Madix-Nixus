<?php
declare(strict_types=1);

/*
 * المرحلة 5 (الخريطة).
 * 58: حالة الأبراج من أجهزة MikroTik عليها؛ 59: حركة كل جهاز لسرعة نقاط الضوء؛ التحديث المجمّع للخريطة ضمن الصلاحية والنطاق.
 * وسرعة بيانات الخريطة: «متصل الآن» للمشتركين باستعلام واحد (كان استعلاماً فرعياً لكل مشترك ≈ 4.4 ثانية لـ 5,000 مشترك).
 */

use Tests\Env;
use Tests\T;

$json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

return [
    'map data: MikroTik site and traffic for tower status and light dots' => static function (Env $env) use ($json): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $site = (int) $pdo->query('SELECT MIN(id) FROM sites')->fetchColumn();
        if (!$site) {
            $pdo->exec("INSERT INTO sites (name, type, lat, lng, created_at) VALUES ('برج الاختبار', 'tower', 31.5, 34.46, NOW())");
            $site = (int) $pdo->lastInsertId();
        }
        $pdo->exec('UPDATE sites SET lat = 31.5, lng = 34.46 WHERE id = ' . $site);
        $rid = (int) $pdo->query('SELECT MIN(id) FROM routers')->fetchColumn();
        $pdo->prepare("UPDATE routers SET site_id = ?, status = 'online', rx_bps = 3000000, tx_bps = 1000000 WHERE id = ?")->execute([$site, $rid]);
        $r = $h->request('GET', $t['path'] . '/map/data', null, $json);
        T::status(200, $r);
        $row = array_values(array_filter($r['json']['routers'] ?? [], static fn ($x) => $x['id'] === $rid))[0] ?? null;
        T::ok($row !== null, 'MikroTik device on the map');
        T::eq($site, $row['site_id'], 'its tower');
        T::eq(4000000, $row['bps'], 'its current traffic (down + up)');
    },
    'map data: online subscribers from one query, still correct' => static function (Env $env) use ($json): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $sid = (int) $pdo->query('SELECT MIN(id) FROM subscribers')->fetchColumn();
        $pdo->prepare('UPDATE subscribers SET lat = 31.51, lng = 34.45 WHERE id = ?')->execute([$sid]);
        $rid = (int) $pdo->query('SELECT MIN(id) FROM routers')->fetchColumn();
        $pdo->prepare("INSERT INTO sessions (router_id, subscriber_id, service, username, mac, started_at, updated_at) VALUES (?, ?, 'ppp', 'map-online-test', '02:00:00:00:00:99', NOW(), NOW())")->execute([$rid, $sid]);
        $sess = (int) $pdo->lastInsertId();
        $find = static function (array $j) use ($sid) { return array_values(array_filter($j['subscribers'] ?? [], static fn ($x) => $x['id'] === $sid))[0] ?? null; };
        $s = $find($h->request('GET', $t['path'] . '/map/data', null, $json)['json']);
        T::ok($s !== null && $s['online'] === true, 'subscriber with an open session is online');
        $pdo->exec('UPDATE sessions SET stopped_at = NOW() WHERE id = ' . $sess);
        $s = $find($h->request('GET', $t['path'] . '/map/data', null, $json)['json']);
        T::ok($s !== null && $s['online'] === false, 'closed session: not online');
        $src = (string) file_get_contents(APP_PATH . '/Controllers/MapController.php');
        T::ok(!str_contains($src, 'EXISTS (SELECT 1 FROM sessions x WHERE x.subscriber_id = s.id'), 'no per-subscriber subquery on sessions');
        // واجهة التطبيق: الحقل نفسه بالقيمة نفسها (عدد الجلسات المفتوحة)
        $mob = (string) file_get_contents(APP_PATH . '/Controllers/MobileDataController.php');
        T::ok(!str_contains($mob, '(SELECT COUNT(*) FROM sessions x WHERE x.subscriber_id = s.id AND x.stopped_at IS NULL) AS online'), 'app list: no per-row subquery');
    },
    'live map key: permission and MikroTik scope' => static function (Env $env) use ($json): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $routers = array_map('intval', $pdo->query('SELECT id FROM routers WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        $live = static fn ($h) => $h->request('GET', $t['path'] . '/live?k=map', null, $json)['json']['data']['map'] ?? null;
        $all = $live($env->owner('a'));
        T::ok(is_array($all) && count($all) >= 2, 'owner gets every MikroTik device');
        T::ok(isset($all[0]['id'], $all[0]['status'], $all[0]['clients'], $all[0]['bps']), 'status, clients and traffic');
        $env->employee('a', 'map58', 'MapPass12345', ['map.view'], [$routers[0]]);
        $ids = array_column($live($env->owner('a', ['map58', 'MapPass12345'])), 'id');
        T::eq([$routers[0]], $ids, 'scoped employee: own MikroTik device only');
        $env->employee('a', 'map58b', 'MapPass12345', ['subscribers.view']);
        T::eq(null, $live($env->owner('a', ['map58b', 'MapPass12345'])), 'no map permission: nothing');
    },
    'map motion: transform and opacity, reduced motion, night map' => static function (Env $env): void {
        $css = (string) file_get_contents(PUBLIC_PATH . '/assets/css/pages/map.css');
        T::contains('@keyframes site-wave { from { transform: scale(1); opacity: .9; } to { transform: scale(2.1); opacity: 0; } }', $css, 'tower wave');
        T::contains('.mk-site.is-up::before, .mk-site.is-up::after, .mk-site.is-down::before, .mk-site.is-down::after { animation: none; opacity: 0; }', $css, 'wave off with reduced motion');
        T::contains('.flow-dot { display: none; }', $css, 'no light dots with reduced motion');
        T::contains('[data-theme="dark"] .tiles-street { filter:', $css, 'night map follows dark mode');
        T::contains('[data-theme-pref="black"] .tiles-street { filter:', $css, 'and black mode');
        $js = (string) file_get_contents(PUBLIC_PATH . '/assets/js/map.js');
        T::contains("transform: 'translate(' + a.x + 'px,' + a.y + 'px)'", $js, 'light dots move with transform');
        T::contains("SN.live.on('map'", $js, 'live updates through the combined request');
    },
];
