<?php
declare(strict_types=1);

/*
 * البند 111: مفتاح منع التكرار. إعادة الطلب نفسه (ضغط مزدوج، إعادة تحميل بعد انقطاع، إعادة محاولة تلقائية)
 * تُرجع نتيجة المرة الأولى ولا تُنفَّذ العملية مرتين، من المتصفح ومن التطبيق، ولا يتشارك مستخدمان مفتاحاً.
 */

use Tests\Env;
use Tests\T;

$count = static function (Env $env, int $sid): int {
    $t = $env->tenant('a');
    return (int) $env->db($t['db'])->query('SELECT COUNT(*) FROM subscriber_transactions WHERE subscriber_id = ' . $sid)->fetchColumn();
};
$sub = static function (Env $env): int {
    $t = $env->tenant('a');
    return (int) $env->db($t['db'])->query('SELECT MIN(id) FROM subscribers')->fetchColumn();
};
$key = static fn (): string => bin2hex(random_bytes(16));

return [
    'same key replays the first result: one payment only' => static function (Env $env) use ($count, $sub, $key): void {
        $t = $env->tenant('a');
        $sid = $sub($env);
        $h = $env->owner('a');
        $h->get($t['path'] . '/subscribers/' . $sid);
        $before = $count($env, $sid);
        $k = $key();
        $data = ['type' => 'payment', 'amount' => '7', '_idem' => $k];
        $first = $h->post($t['path'] . '/subscribers/' . $sid . '/transaction', $data);
        T::status(302, $first);
        T::eq($before + 1, $count($env, $sid), 'first request recorded');
        $h->get($first['location']);
        $again = $h->post($t['path'] . '/subscribers/' . $sid . '/transaction', $data);
        T::status(302, $again);
        T::eq('1', $again['headers']['x-idempotent-replay'] ?? '', 'replayed');
        T::eq($first['location'], $again['location'], 'same destination');
        T::eq($before + 1, $count($env, $sid), 'no second payment');
        T::contains('تم تسجيل القيد', $h->get($again['location'])['body'], 'same message shown again');
    },
    'other data, another user or no key is a new operation' => static function (Env $env) use ($count, $sub, $key): void {
        $t = $env->tenant('a');
        $sid = $sub($env);
        $h = $env->owner('a');
        $h->get($t['path'] . '/subscribers/' . $sid);
        $k = $key();
        $before = $count($env, $sid);
        $h->post($t['path'] . '/subscribers/' . $sid . '/transaction', ['type' => 'payment', 'amount' => '3', '_idem' => $k]);
        $h->post($t['path'] . '/subscribers/' . $sid . '/transaction', ['type' => 'payment', 'amount' => '4', '_idem' => $k]);
        T::eq($before + 2, $count($env, $sid), 'different amount with the same key is another payment');
        $env->employee('a', 'cashier1', 'CashierPass123', ['subscribers.view', 'subscribers.payments']);
        $e = $env->owner('a', ['cashier1', 'CashierPass123']);
        $e->get($t['path'] . '/subscribers/' . $sid);
        $r = $e->post($t['path'] . '/subscribers/' . $sid . '/transaction', ['type' => 'payment', 'amount' => '3', '_idem' => $k]);
        T::ok(!isset($r['headers']['x-idempotent-replay']), 'never the result of another user');
        T::eq($before + 3, $count($env, $sid), 'employee operation executed');
        $h->post($t['path'] . '/subscribers/' . $sid . '/transaction', ['type' => 'payment', 'amount' => '2', '_idem' => 'bad key!']);
        $h->post($t['path'] . '/subscribers/' . $sid . '/transaction', ['type' => 'payment', 'amount' => '2']);
        T::eq($before + 5, $count($env, $sid), 'invalid or missing key: normal behaviour');
    },
    'browser JSON requests use the header' => static function (Env $env) use ($count, $sub, $key): void {
        $t = $env->tenant('a');
        $sid = $sub($env);
        $h = $env->owner('a');
        $h->get($t['path'] . '/subscribers/' . $sid);
        $before = $count($env, $sid);
        $k = $key();
        $hdr = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'Content-Type' => 'application/json', 'X-CSRF-Token' => $h->csrf, 'Idempotency-Key' => $k];
        $a = $h->request('POST', $t['path'] . '/subscribers/' . $sid . '/transaction', ['type' => 'payment', 'amount' => '6'], $hdr);
        $b = $h->request('POST', $t['path'] . '/subscribers/' . $sid . '/transaction', ['type' => 'payment', 'amount' => '6'], $hdr);
        T::eq($a['status'], $b['status']);
        T::eq($a['body'], $b['body'], 'identical answer');
        T::eq('1', $b['headers']['x-idempotent-replay'] ?? '');
        T::eq($before + 1, $count($env, $sid));
    },
    'mobile app: header key, and parallel duplicates run once' => static function (Env $env) use ($count, $sub, $key): void {
        $t = $env->tenant('a');
        $sid = $sub($env);
        $h = $env->http();
        $r = $h->request('POST', $t['path'] . '/api/app/v1/login', ['username' => Env::OWNER[0], 'password' => Env::OWNER[1], 'device' => 'test-phone', 'platform' => 'android'], ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json']);
        $token = (string) ($r['json']['token'] ?? '');
        T::ok($token !== '', 'app token');
        $url = $h->base . $t['path'] . '/api/app/v1/subscribers/' . $sid . '/transaction';
        $before = $count($env, $sid);
        $k = $key();
        $mh = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 4; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
                CURLOPT_POSTFIELDS => http_build_query(['type' => 'payment', 'amount' => '9']),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Idempotency-Key: ' . $k],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);
        $codes = [];
        foreach ($handles as $ch) {
            $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        T::eq($before + 1, $count($env, $sid), 'one payment from four identical requests');
        T::ok(count(array_filter($codes, static fn ($c) => $c === 200)) >= 1, 'answered: ' . implode(',', $codes));
        T::ok(!array_filter($codes, static fn ($c) => $c >= 500), 'no server error');
    },
];
