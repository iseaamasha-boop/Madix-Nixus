<?php
declare(strict_types=1);

/*
 * واجهة تطبيق الأندرويد /api/app/v1: الدخول بالرمز، الصلاحيات، وعقد الردود (النسخ المثبتة عند الناس يجب أن تبقى تعمل).
 */

use Tests\Contract;
use Tests\Env;
use Tests\T;

$app = static function (Env $env, ?array $cred = null): array {
    $t = $env->tenant('a');
    $h = $env->http();
    $cred ??= Env::OWNER;
    $r = $h->request('POST', $t['path'] . '/api/app/v1/login', ['username' => $cred[0], 'password' => $cred[1], 'device' => 'test-phone', 'platform' => 'android'], ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json']);
    T::status(200, $r, 'app login');
    T::ok(($r['json']['ok'] ?? false) === true && preg_match('/^[a-f0-9]{64}$/', (string) ($r['json']['token'] ?? '')), 'token issued');
    $h->headers['Authorization'] = 'Bearer ' . $r['json']['token'];
    $h->headers['Accept'] = 'application/json';
    return [$h, $t, $r['json']];
};

return [
    'info answers without login' => static function (Env $env): void {
        $t = $env->tenant('a');
        $r = $env->http()->get($t['path'] . '/api/app/v1/info');
        T::status(200, $r);
        Contract::check('app.info', $r['json']);
        $r = $env->http()->get('/api/app/v1/info');
        T::status(200, $r);
        Contract::check('app.info.platform', $r['json']);
    },
    'login returns token, user and network' => static function (Env $env) use ($app): void {
        [, , $json] = $app($env);
        Contract::check('app.login', $json);
    },
    'bad credentials and missing token are refused' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->http();
        $r = $h->request('POST', $t['path'] . '/api/app/v1/login', ['username' => 'owner', 'password' => 'nope'], ['Content-Type' => 'application/x-www-form-urlencoded']);
        T::status(422, $r);
        T::eq(false, $r['json']['ok'] ?? null);
        T::status(401, $h->get($t['path'] . '/api/app/v1/me', ['Accept' => 'application/json']));
        T::status(401, $h->get($t['path'] . '/api/app/v1/me', ['Authorization' => 'Bearer ' . str_repeat('a', 64), 'Accept' => 'application/json']));
    },
    'read endpoints keep their response shape' => static function (Env $env) use ($app): void {
        [$h, $t] = $app($env);
        $pdo = $env->db($t['db']);
        $id = static fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
        $card = (string) $pdo->query("SELECT username FROM cards WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        $paths = [
            'me' => '/me', 'devices' => '/devices', 'dashboard' => '/dashboard', 'routers' => '/routers',
            'router' => '/routers/' . $id('SELECT MIN(id) FROM routers'), 'sessions' => '/sessions', 'alerts' => '/alerts',
            'reports' => '/reports', 'cards' => '/cards', 'cards.lookup' => '/cards/lookup?q=' . $card, 'cards.options' => '/cards/options',
            'card' => '/cards/' . $id('SELECT MIN(id) FROM cards'), 'categories' => '/categories', 'batches' => '/batches',
            'subscribers' => '/subscribers', 'subscribers.options' => '/subscribers/options',
            'subscriber' => '/subscribers/' . $id('SELECT MIN(id) FROM subscribers'), 'sales' => '/sales', 'sales.options' => '/sales/options',
            'sale' => '/sales/' . $id('SELECT MIN(id) FROM sales'), 'distributors' => '/distributors', 'sms' => '/sms', 'sms.log' => '/sms/log',
            'inventory' => '/inventory',
        ];
        foreach ($paths as $name => $p) {
            $r = $h->get($t['path'] . '/api/app/v1' . $p);
            T::status(200, $r, $name);
            T::eq(true, $r['json']['ok'] ?? null, $name . ' ok');
            Contract::check('app.' . $name, $r['json']);
        }
        // صفحات القوائم: page و has_more
        $r = $h->get($t['path'] . '/api/app/v1/subscribers?page=2');
        T::eq(2, $r['json']['page'] ?? null, 'paging');
        $r = $h->get($t['path'] . '/api/app/v1/cards?q=d000');
        T::status(200, $r, 'cards search');
    },
    'selling from the app works and answers like before' => static function (Env $env) use ($app): void {
        [$h, $t] = $app($env);
        $pdo = $env->db($t['db']);
        $cat = (int) $pdo->query("SELECT category_id FROM cards WHERE status = 'new' AND is_disabled = 0 GROUP BY category_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        $r = $h->request('POST', $t['path'] . '/api/app/v1/sales', ['type' => 'direct', 'category_id' => $cat, 'quantity' => 2, 'paid' => 0], ['Content-Type' => 'application/x-www-form-urlencoded']);
        T::status(200, $r, 'sale');
        T::eq(true, $r['json']['ok'] ?? null, 'sale ok');
        Contract::check('app.sales.store', $r['json']);
        T::eq($before + 1, (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn(), 'one sale recorded');
    },
    'renewing a subscriber from the app' => static function (Env $env) use ($app): void {
        [$h, $t] = $app($env);
        $pdo = $env->db($t['db']);
        $sid = (int) $pdo->query("SELECT id FROM subscribers WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        $r = $h->request('POST', $t['path'] . '/api/app/v1/subscribers/' . $sid . '/renew', ['months' => 1, 'paid' => 0], ['Content-Type' => 'application/x-www-form-urlencoded']);
        T::status([200, 422], $r, 'renew');
        Contract::check('app.subscribers.renew', $r['json']);
    },
    'app permissions follow the employee' => static function (Env $env) use ($app): void {
        $env->employee('a', 'seller2', 'SellerPass123', ['dashboard.view', 'cards.view']);
        [$h, $t] = $app($env, ['seller2', 'SellerPass123']);
        T::status(200, $h->get($t['path'] . '/api/app/v1/cards'));
        T::status(403, $h->get($t['path'] . '/api/app/v1/subscribers'), 'no subscribers.view');
        T::status(403, $h->get($t['path'] . '/api/app/v1/routers'), 'no routers.view');
    },
    'logout revokes the token' => static function (Env $env) use ($app): void {
        [$h, $t] = $app($env);
        T::status(200, $h->request('POST', $t['path'] . '/api/app/v1/logout', [], ['Content-Type' => 'application/x-www-form-urlencoded']));
        T::status(401, $h->get($t['path'] . '/api/app/v1/me'));
    },
];
