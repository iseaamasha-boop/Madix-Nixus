<?php
declare(strict_types=1);

/*
 * صفحة الهوتسبوت على أجهزة MikroTik: sohaibnet.js وsn-config.js وكل /api/hotspot/*.
 * الصفحات المنشورة على الأجهزة لا تتحدث مع التحديث، فشكل الردود وأسماء الدوال يجب أن تبقى كما هي.
 */

use Tests\Contract;
use Tests\Env;
use Tests\T;

$router = static function (Env $env): array {
    $t = $env->tenant('a');
    $pdo = $env->db($t['db']);
    $r = $pdo->query("SELECT id, router_key FROM routers ORDER BY id LIMIT 1")->fetch();
    return [$t, $r['router_key'], (int) $r['id'], $pdo];
};

return [
    'SDK file keeps its public functions' => static function (Env $env) use ($router): void {
        [$t] = $router($env);
        $r = $env->http()->get($t['path'] . '/hotspot/sdk/sohaibnet.js');
        T::status(200, $r);
        T::contains('javascript', $r['headers']['content-type'] ?? '');
        foreach (['SNHotspot', 'login', 'status', 'info', 'submitLogin', 'formatBytes', 'formatDuration', 'SN_CONFIG'] as $fn) {
            T::contains($fn, $r['body'], 'sohaibnet.js');
        }
        // الملف الثابت نفسه (الذي يُنشر على الأجهزة) موجود في مكانه
        T::ok(is_file(BASE_PATH . '/public/hotspot/sdk/sohaibnet.js'), 'public/hotspot/sdk/sohaibnet.js');
    },
    'sn-config.js keeps its format' => static function (Env $env) use ($router): void {
        [$t, $key] = $router($env);
        $r = $env->http()->get($t['path'] . '/hotspot/sdk/config/' . $key . '.js');
        T::status(200, $r);
        T::ok(preg_match('/window\.SN_CONFIG = (\{.*\});/', $r['body'], $m) === 1, 'window.SN_CONFIG = {...};');
        $cfg = json_decode($m[1], true);
        foreach (['panel', 'key', 'timeout', 'fallback', 'codeCase', 'router'] as $k) {
            T::ok(array_key_exists($k, $cfg), 'sn-config key ' . $k);
        }
        T::eq($key, $cfg['key']);
        T::status(404, $env->http()->get($t['path'] . '/hotspot/sdk/config/' . str_repeat('0', 32) . '.js'));
    },
    'info and CORS for pages served from the router' => static function (Env $env) use ($router): void {
        [$t, $key] = $router($env);
        $h = $env->http();
        $r = $h->get($t['path'] . '/api/hotspot/info?key=' . $key, ['Origin' => 'http://10.5.50.1']);
        T::status(200, $r);
        T::eq('*', $r['headers']['access-control-allow-origin'] ?? '', 'CORS');
        Contract::check('hotspot.info', $r['json']);
        $o = $h->request('OPTIONS', $t['path'] . '/api/hotspot/login', null, ['Origin' => 'http://10.5.50.1', 'Access-Control-Request-Method' => 'POST']);
        T::status(204, $o, 'preflight');
    },
    'login with a valid card, a wrong password and an unknown code' => static function (Env $env) use ($router): void {
        [$t, $key, , $pdo] = $router($env);
        $card = $pdo->query("SELECT username, password FROM cards WHERE status = 'new' AND is_disabled = 0 ORDER BY id LIMIT 1")->fetch();
        $h = $env->http();
        $post = static fn (array $d) => $h->request('POST', $t['path'] . '/api/hotspot/login', $d, ['Content-Type' => 'application/x-www-form-urlencoded', 'Origin' => 'http://10.5.50.1']);
        $ok = $post(['key' => $key, 'code' => $card['username'], 'password' => $card['password'], 'mac' => '02:AA:00:00:00:01', 'ip' => '10.5.50.10']);
        T::status(200, $ok);
        T::eq(true, $ok['json']['ok'] ?? null, 'valid card accepted: ' . json_encode($ok['json'], JSON_UNESCAPED_UNICODE));
        Contract::check('hotspot.login.ok', $ok['json']);
        // كلمة مرور خاطئة لكرت موجود: رفض
        $other = $pdo->query("SELECT username, password FROM cards WHERE status = 'new' AND is_disabled = 0 ORDER BY id LIMIT 1 OFFSET 5")->fetch();
        $bad = $post(['key' => $key, 'code' => $other['username'], 'password' => $other['password'] . '9', 'mac' => '02:AA:00:00:00:02', 'ip' => '10.5.50.11']);
        T::eq(false, $bad['json']['ok'] ?? null, 'wrong password: ' . json_encode($bad['json'], JSON_UNESCAPED_UNICODE));
        Contract::check('hotspot.login.fail', $bad['json']);
        // رمز غير معروف: يُمرَّر لمستخدمي جهاز MikroTik المحليين (hotspot.local_users)
        $local = $post(['key' => $key, 'code' => 'zz-local-user', 'password' => '1', 'mac' => '02:AA:00:00:00:03', 'ip' => '10.5.50.12']);
        T::eq(true, $local['json']['ok'] ?? null);
        T::eq('local', $local['json']['type'] ?? null);
        Contract::check('hotspot.login.local', $local['json']);
        $router = $post(['key' => str_repeat('f', 32), 'code' => 'x', 'password' => 'x']);
        T::eq(true, $router['json']['fallback'] ?? null, 'unknown router → fallback to MikroTik');
    },
    'status of a card' => static function (Env $env) use ($router): void {
        [$t, $key, , $pdo] = $router($env);
        $u = (string) $pdo->query("SELECT username FROM cards WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        $r = $env->http()->get($t['path'] . '/api/hotspot/status?key=' . $key . '&u=' . urlencode($u) . '&mac=02:00:00:00:00:01');
        T::status(200, $r);
        Contract::check('hotspot.status', $r['json']);
    },
    'ads and router events' => static function (Env $env) use ($router): void {
        [$t, $key] = $router($env);
        $r = $env->http()->get($t['path'] . '/api/hotspot/ads?key=' . $key . '&mac=02:00:00:00:00:01');
        T::status(200, $r);
        Contract::check('hotspot.ads', $r['json']);
        $e = $env->http()->get($t['path'] . '/api/router/event?k=' . $key . '&e=in&u=d0000001&m=02:00:00:00:00:09&a=10.5.50.9');
        T::status(200, $e);
        T::eq('ok', trim($e['body']));
    },
];
