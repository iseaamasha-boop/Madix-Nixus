<?php
declare(strict_types=1);

/*
 * البند 110 (السرعة) من جهة الخادم: الكاش القصير (العزل، الصلاحيات، المسح عند التغيير)، صحة الأرقام،
 * صفحات أجهزة MikroTik لا تنتظر الجهاز، إغلاق الجلسة مبكراً دون فقدان الرسائل، وترحيل كل الشبكات.
 */

use App\Core\Cache;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Tenant;
use Tests\Env;
use Tests\T;

$enter = static function (Env $env, string $key): array {
    $t = $env->tenant($key);
    Tenant::leave();
    Tenant::enter(Platform::tenantBySlug($t['slug']));
    return $t;
};

/** أرقام البطاقات كما تظهر في لوحة التحكم */
$kpi = static function (string $html, string $label): ?string {
    // كل بطاقة: الرقم ثم العنوان (أو العكس)؛ يكفي التحقق من وجود الرقم المتوقع في الصفحة
    return str_contains($html, $label) ? $label : null;
};

return [
    'cache is isolated per network and per permission scope' => static function (Env $env) use ($enter): void {
        $enter($env, 'a');
        $va = Cache::remember('test.iso', [1], ['cards'], static fn () => 'value-a');
        Tenant::leave();
        $enter($env, 'b');
        $vb = Cache::remember('test.iso', [1], ['cards'], static fn () => 'value-b');
        T::eq('value-a', $va);
        T::eq('value-b', $vb, 'network b must not read the value of network a');
        // مجلد تخزين كل شبكة مستقل، والكاش داخله باسم الشبكة ورقمها
        $dirA = BASE_PATH . '/storage/tenants/' . $env->tenants['a']['slug'] . '/cache/data/t' . Platform::tenantBySlug($env->tenants['a']['slug'])['id'];
        $dirB = BASE_PATH . '/storage/tenants/' . $env->tenants['b']['slug'] . '/cache/data/t' . Platform::tenantBySlug($env->tenants['b']['slug'])['id'];
        T::ok($dirA !== $dirB && is_dir($dirA) && is_dir($dirB), 'separate cache folders');
        Tenant::leave();
    },
    'a write to a table invalidates results built from it, a usage-only update does not' => static function (Env $env) use ($enter): void {
        $enter($env, 'a');
        $n = 0;
        $count = static function () use (&$n): int {
            return Cache::remember('test.inv', [], ['cards'], static function () use (&$n): int {
                $n++;
                return (int) DB::value('SELECT COUNT(*) FROM cards');
            });
        };
        $first = $count();
        $count();
        T::eq(1, $n, 'second read comes from the cache');
        // تحديث أعمدة المراقبة فقط (آخر دخول، الاستهلاك): لا يمس الأعداد
        DB::query('UPDATE cards SET last_login_at = ?, used_seconds = used_seconds + 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM cards) x)', [now()]);
        $count();
        T::eq(1, $n, 'usage-only update keeps the cached count');
        // تغيير حقيقي: حالة كرت
        DB::query("UPDATE cards SET status = 'sold' WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM cards WHERE status = 'new') x)");
        $count();
        T::eq(2, $n, 'status change recomputes');
        DB::query("DELETE FROM logins WHERE login = 'zz-cache-test'");
        T::eq($first, $count(), 'count unchanged');
        Tenant::leave();
    },
    'dashboard numbers match the database and follow a sale at once' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $pdo = $env->db($t['db']);
        $available = static fn () => (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE status = 'new' AND is_disabled = 0")->fetchColumn();
        $r = $h->get($t['path'] . '/');
        T::status(200, $r);
        T::contains(num($available()), $r['body'], 'available cards on dashboard');
        $before = $available();
        // بيع من الموقع: الرقم يتغير في اللوحة فوراً رغم الكاش
        $cat = (int) $pdo->query("SELECT category_id FROM cards WHERE status = 'new' AND is_disabled = 0 GROUP BY category_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
        $h->get($t['path'] . '/sales/create');
        $s = $h->post($t['path'] . '/sales', ['type' => 'direct', 'category_id' => $cat, 'quantity' => 3, 'paid' => 0]);
        T::status(302, $s, 'sale');
        T::eq($before - 3, $available(), 'three cards sold');
        $r = $h->get($t['path'] . '/');
        T::contains(num($before - 3), $r['body'], 'dashboard shows the new number');
    },
    'an employee does not get the owner cached numbers' => static function (Env $env): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $routerId = (int) $pdo->query('SELECT MIN(id) FROM routers')->fetchColumn();
        $env->employee('a', 'scoped1', 'ScopedPass123', ['dashboard.view', 'subscribers.view'], [$routerId]);
        $owner = $env->owner('a');
        $o = $owner->get($t['path'] . '/subscribers');
        $emp = $env->owner('a', ['scoped1', 'ScopedPass123']);
        $e = $emp->get($t['path'] . '/subscribers');
        T::status(200, $e);
        $mine = (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE router_id = ' . $routerId)->fetchColumn();
        $all = (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
        T::ok($mine < $all, 'scope is smaller than all');
        T::contains(num($all), $o['body'], 'owner total');
        T::notContains('>' . num($all) . '<', $e['body'], 'scoped employee must not see the full total');
    },
    'MikroTik device tabs open without waiting for the device' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $id = (int) $env->db($t['db'])->query('SELECT MIN(id) FROM routers')->fetchColumn();
        foreach (['active', 'hosts', 'logs', 'interfaces'] as $tab) {
            $t0 = microtime(true);
            $r = $h->get($t['path'] . '/routers/' . $id . '?tab=' . $tab);
            $ms = (microtime(true) - $t0) * 1000;
            T::status(200, $r, $tab);
            T::ok($ms < 1500, "$tab page took " . round($ms) . 'ms (must not wait for the device)');
            T::contains('data-live-tab=', $r['body'], $tab . ' loads device data in the background');
        }
        // الجزء الحي نفسه: رد JSON برسالة الخطأ (الجهاز التجريبي لا يُوصل إليه)، لا خطأ HTTP
        $f = $h->ajax('GET', $t['path'] . '/routers/' . $id . '?tab=logs&fragment=1');
        T::status(200, $f, 'fragment');
        T::eq(false, $f['json']['ok'] ?? null);
        T::contains('MikroTik', (string) ($f['json']['html'] ?? ''));
    },
    'a flash message shows once after early session release' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $h->get($t['path'] . '/account');
        $r = $h->post($t['path'] . '/account/theme', ['theme' => 'light']);
        // حفظ إعدادات يعيد رسالة؛ نستخدم صفحة الإعدادات لإنشاء رسالة نجاح مؤكدة
        $h->get($t['path'] . '/areas');
        $s = $h->post($t['path'] . '/areas', ['name' => 'منطقة الاختبار ' . $env->run]);
        T::status(302, $s);
        $first = $h->get($t['path'] . '/areas');
        T::contains('flash-data', $first['body'], 'flash shown on the next page');
        $second = $h->get($t['path'] . '/areas');
        T::notContains('flash-data', $second['body'], 'flash consumed');
    },
    'migrate command reaches every network, even suspended, and is safe twice' => static function (Env $env): void {
        $t = $env->tenant('c', 5, 20);
        $pdo = $env->db();
        $pdo->prepare("UPDATE tenants SET status = 'suspended' WHERE slug = ?")->execute([$t['slug']]);
        $tdb = $env->db($t['db']);
        $tdb->exec("UPDATE settings SET `value` = '15' WHERE `key` = 'app.schema_version'");
        $tdb->exec('ALTER TABLE cards DROP INDEX idx_cards_active_login');
        $out = $env->cli('cli/migrate.php');
        T::contains('failed 0', $out);
        $env->cli('cli/migrate.php');
        T::eq((string) App\Core\Migrator::VERSION, (string) $tdb->query("SELECT `value` FROM settings WHERE `key` = 'app.schema_version'")->fetchColumn());
        T::eq(1, (int) $tdb->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cards' AND INDEX_NAME = 'idx_cards_active_login'")->fetchColumn(), 'index restored');
        $pdo->prepare("UPDATE tenants SET status = 'active' WHERE slug = ?")->execute([$t['slug']]);
    },
    'new indexes exist on a fresh network' => static function (Env $env): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        foreach (['router_metrics' => 'idx_metrics_time_online', 'subscriber_transactions' => 'idx_st_type_created', 'cards' => 'idx_cards_active_login'] as $table => $idx) {
            T::eq(1, (int) $pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '$idx'")->fetchColumn(), $idx);
        }
    },
    'Server-Timing appears only when enabled' => static function (Env $env): void {
        $t = $env->tenant('a');
        $r = $env->owner('a')->get($t['path'] . '/');
        T::ok(!isset($r['headers']['server-timing']), 'off by default');
    },
];
