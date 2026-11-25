<?php
declare(strict_types=1);

/*
 * البند 110 من جهة الواجهة: الخط الأساسي محمّل مسبقاً، التحميل المسبق الذكي، الرجوع للخلف الفوري بأمان،
 * التحديثات المباشرة المجمّعة بالصلاحيات، والقوائم الكبيرة بالبحث في الخادم.
 */

use App\Core\Platform;
use Tests\Env;
use Tests\T;

return [
    'primary font is preloaded and other weights are deferred' => static function (Env $env): void {
        $t = $env->tenant('a');
        $r = $env->owner('a')->get($t['path'] . '/subscribers');
        T::ok(preg_match('/<link rel="preload" href="[^"]+arabic-400-normal\.woff2[^"]*" as="font" type="font\/woff2" crossorigin>/', $r['body']) === 1, 'preload of the primary font');
        T::contains('font-display:swap', $r['body']);
        T::contains('mnx-fonts', $r['body'], 'deferred weights script');
        T::notContains('@font-face{font-family:"Plex Arabic";font-weight:700', $r['body'], 'bold weight must not be declared before the page loads');
    },
    'scripts are deferred and prefetch respects data saver' => static function (Env $env): void {
        $t = $env->tenant('a');
        $r = $env->owner('a')->get($t['path'] . '/');
        T::ok(preg_match('#<script src="[^"]+js/app\.js\?v=[^"]+" defer></script>#', $r['body']) === 1, 'app.js deferred');
        T::contains('speculationrules', $r['body']);
        T::contains('saveData', $r['body'], 'no prefetch with data saver');
        T::contains('/logout', $r['body']);
    },
    'chart library loads only when a chart comes into view' => static function (Env $env): void {
        $t = $env->tenant('a');
        $r = $env->owner('a')->get($t['path'] . '/');
        T::status(200, $r);
        T::ok(preg_match('#<script src="[^"]*chart\.umd\.min\.js#', $r['body']) === 0, 'no chart script in the page head');
        T::ok(preg_match('#<canvas id="days-chart" data-lib="[^"]+chart\.umd\.min\.js[^"]*">#', $r['body']) === 1, 'chart canvas carries the library');
        T::contains('SN.chartWhenSeen(', $r['body']);
    },
    'view pages allow instant back, sensitive pages are never stored' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        T::eq('private, no-cache', $h->get($t['path'] . '/subscribers')['headers']['cache-control'] ?? '');
        foreach (['/account', '/settings', '/login'] as $p) {
            $cc = (string) ($h->get($t['path'] . $p)['headers']['cache-control'] ?? '');
            T::contains('no-store', $cc, $p);
        }
        $j = $h->ajax('GET', $t['path'] . '/dashboard/data');
        T::contains('no-store', (string) ($j['headers']['cache-control'] ?? ''), 'JSON');
    },
    'session tag guards pages restored after logout' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $r = $h->get($t['path'] . '/subscribers');
        T::ok(preg_match('/<meta name="session-tag" content="([a-f0-9]{16})">/', $r['body'], $m) === 1, 'meta tag');
        $out = $h->post($t['path'] . '/logout');
        T::contains('"cache"', (string) ($out['headers']['clear-site-data'] ?? ''), 'logout clears the browser cache');
        T::contains('mnx_tag=deleted', strtolower((string) ($out['headers']['set-cookie'] ?? '')), 'tag cookie removed');
    },
    'one live request returns only what the employee may see' => static function (Env $env): void {
        $t = $env->tenant('a');
        $owner = $env->owner('a');
        $r = $owner->ajax('GET', $t['path'] . '/live?k=dashboard,unknown');
        T::status(200, $r);
        T::ok(isset($r['json']['data']['dashboard']['online']), 'dashboard numbers');
        T::ok(!isset($r['json']['data']['unknown']), 'unknown key ignored');
        $env->employee('a', 'nodash', 'NoDashPass123', ['cards.view']);
        $e = $env->owner('a', ['nodash', 'NoDashPass123']);
        $r = $e->ajax('GET', $t['path'] . '/live?k=dashboard');
        T::status(200, $r);
        T::eq([], $r['json']['data'] ?? null, 'no dashboard data without dashboard.view');
    },
    'subscriber lookup is short, scoped and replaces the big inventory list' => static function (Env $env): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $r = $h->ajax('GET', $t['path'] . '/subscribers/lookup?q=demo');
        T::status(200, $r);
        T::ok(count($r['json']['items'] ?? []) <= 20 && count($r['json']['items']) > 0, 'up to 20 results');
        $rid = (int) $pdo->query('SELECT MIN(id) FROM routers')->fetchColumn();
        $env->employee('a', 'scoped2', 'ScopedPass123', ['subscribers.view'], [$rid]);
        $e = $env->owner('a', ['scoped2', 'ScopedPass123']);
        $items = $e->ajax('GET', $t['path'] . '/subscribers/lookup?q=demo')['json']['items'] ?? [];
        $ids = array_column($items, 'id');
        if ($ids) {
            $outside = (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') AND (router_id IS NULL OR router_id <> ' . $rid . ')')->fetchColumn();
            T::eq(0, $outside, 'only subscribers of the allowed MikroTik');
        }
        $inv = $h->get($t['path'] . '/inventory');
        T::status(200, $inv);
        T::contains('data-remote-select', $inv['body']);
        T::ok(substr_count($inv['body'], '<option') < 200, 'no list of every subscriber in the page');
    },
    'opening a ticket by prefetch does not mark it read' => static function (Env $env): void {
        $t = $env->tenant('a');
        $tid = (int) Platform::tenantBySlug($t['slug'])['id'];
        $pdo = $env->db();
        $pdo->prepare("INSERT INTO support_tickets (tenant_id, subject, status, admin_unread, tenant_unread, last_message_at, created_at) VALUES (?, 'اختبار', 'answered', 0, 1, NOW(), NOW())")->execute([$tid]);
        $id = (int) $pdo->lastInsertId();
        $h = $env->owner('a');
        $h->get($t['path'] . '/support/' . $id, ['Sec-Purpose' => 'prefetch']);
        T::eq(1, (int) $pdo->query('SELECT tenant_unread FROM support_tickets WHERE id = ' . $id)->fetchColumn(), 'still unread after prefetch');
        $h->get($t['path'] . '/support/' . $id);
        T::eq(0, (int) $pdo->query('SELECT tenant_unread FROM support_tickets WHERE id = ' . $id)->fetchColumn(), 'read after a real visit');
    },
];
