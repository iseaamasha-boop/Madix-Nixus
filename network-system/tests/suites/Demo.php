<?php
declare(strict_types=1);

/*
 * المرحلة 7، البند 105 و105+: الشبكة التجريبية («جرّب الآن») وزوارها.
 * شبكة منفصلة بقاعدة خاصة وبيانات وهمية، يدخلها الزائر بلا تسجيل، ويُمنع فيها ما يخرج إلى العالم
 * (الرسائل، الذكاء الاصطناعي، أجهزة MikroTik) وكلمة المرور والمستخدمون، وتعود إلى أصلها كل ساعة،
 * ويشغّلها المسؤول ويطفئها ويرى زوارها وصفحاتهم.
 */

use App\Core\Platform;
use App\Core\Tenant;
use Tests\Env;
use Tests\T;

$json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

/** تشغيل الشبكة التجريبية من لوحة المسؤول (مرة واحدة للجناح) */
$enable = static function (Env $env): array {
    $t = Platform::row('SELECT * FROM tenants WHERE is_demo = 1 LIMIT 1');
    if (!$t || Platform::setting('demo.enabled', '0') !== '1') {
        $h = $env->admin();
        $h->get('/admin/demo');
        $r = $h->post('/admin/demo', ['enabled' => '1']);
        T::status(302, $r, 'enabled from the admin panel');
        Platform::flushSettings();
        $t = Platform::row('SELECT * FROM tenants WHERE is_demo = 1 LIMIT 1');
    }
    T::ok($t !== null, 'demo network created');
    // ملفاتها في مجلد الاختبار تُحذف مع ملفات شبكات الاختبار عند الانتهاء
    $env->tenants['demo-net'] = ['slug' => $t['slug'], 'db' => $t['db_name'], 'path' => '/' . $t['slug']];
    return $t;
};

/** زائر يدخل من «جرّب الآن» */
$visitor = static function (Env $env, array $t, array $headers = []): \Tests\Http {
    $h = $env->http();
    $r = $h->request('GET', '/' . $t['slug'] . '/start', null, $headers);
    T::status(302, $r, 'start redirects into the network');
    T::eq('/' . $t['slug'] . '/', (string) parse_url($r['location'], PHP_URL_PATH), 'to its dashboard');
    $h->get('/' . $t['slug'] . '/');
    return $h;
};

return [
    'admin turns the demo network on: own database, fake data, try now on the landing (105)' => static function (Env $env) use ($enable): void {
        $t = $enable($env);
        T::eq(1, (int) $t['is_demo'], 'flagged as demo');
        T::eq('active', $t['status'], 'open');
        $pdo = $env->db($t['db_name']);
        T::ok((int) $pdo->query('SELECT COUNT(*) FROM routers')->fetchColumn() > 5, 'MikroTik devices');
        T::ok((int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn() > 100, 'subscribers');
        T::ok((int) $pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn() > 1000, 'cards');
        T::ok((int) $pdo->query('SELECT COUNT(*) FROM sessions WHERE stopped_at IS NULL')->fetchColumn() > 50, 'online now');
        T::eq(0, (int) $pdo->query("SELECT COUNT(*) FROM routers WHERE host NOT LIKE '203.0.113.%'")->fetchColumn(), 'MikroTik devices on documentation addresses only (TEST-NET)');
        T::ok((int) $pdo->query("SELECT COUNT(*) FROM routers WHERE next_poll_at < NOW() + INTERVAL 1 YEAR")->fetchColumn() === 0, 'no polling');
        T::ok((int) $pdo->query("SELECT COUNT(*) FROM sales WHERE created_at < NOW() - INTERVAL 300 DAY")->fetchColumn() > 0, 'a year of sales');
        $page = $env->http()->get('/')['body'];
        T::contains('href="/' . $t['slug'] . '/start" rel="nofollow" data-lp-try', $page, 'try now on the landing page');
    },
    'visitor enters without signing up, demo badge, noindex, isolated from other networks (105)' => static function (Env $env) use ($enable, $visitor): void {
        $t = $enable($env);
        $h = $visitor($env, $t, ['Referer' => 'https://www.facebook.com/somepage', 'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1']);
        $r = $h->get('/' . $t['slug'] . '/');
        T::status(200, $r);
        T::cleanHtml($r);
        T::contains('demo-badge">تجريبي</span>', $r['body'], 'demo badge');
        T::contains('<meta name="robots" content="noindex, nofollow">', $r['body'], 'noindex in the page');
        T::eq('noindex, nofollow', $r['headers']['x-robots-tag'] ?? null, 'noindex header');
        // جلسة الشبكة التجريبية لا تفتح شبكة أخرى
        $a = $env->tenant('a');
        T::status(302, $h->get($a['path'] . '/'), 'other network asks for login');
        // صفحة الدخول تعيد الزائر إلى الدخول التلقائي
        $r = $h->get('/' . $t['slug'] . '/login');
        T::eq('/' . $t['slug'] . '/start', (string) parse_url($r['location'] ?? '', PHP_URL_PATH), 'login page enters the visitor');
    },
    'blocked in the demo: password, 2FA, users, MikroTik, messages, AI, server, billing, support (105)' => static function (Env $env) use ($enable, $visitor, $json): void {
        $t = $enable($env);
        $h = $visitor($env, $t);
        $paths = ['/account', '/account/2fa/start', '/employees', '/employees/1', '/routers', '/routers/test', '/routers/connect', '/routers/1/reboot',
            '/routers/1/action', '/tunnels', '/server/settings', '/cables/test', '/hotspot/deploy', '/sessions/1/disconnect', '/devices/block',
            '/usage/cap', '/sms', '/sms/test', '/telegram', '/telegram/test', '/inbox/1/reply', '/assistant', '/subscribers/import-smart/parse',
            '/settings/backup', '/settings/backup/restore', '/billing/transfer', '/support', '/networks'];
        foreach ($paths as $p) {
            $r = $h->ajax('POST', '/' . $t['slug'] . $p, ['x' => 1]);
            T::eq(403, $r['status'], 'POST ' . $p . ' blocked');
            T::eq('غير متاح في الشبكة التجريبية.', $r['json']['message'] ?? null, 'POST ' . $p . ' message');
        }
        $r = $h->ajax('POST', '/' . $t['slug'] . '/account/theme', ['theme' => 'dark']);
        T::status(200, $r, 'harmless changes still work');
        T::eq(0, (int) $env->db($t['db_name'])->query("SELECT COUNT(*) FROM employees WHERE username <> 'demo'")->fetchColumn(), 'no user added');
    },
    'nothing leaves the demo network: MikroTik, Telegram, SMS, email, WhatsApp, AI (105)' => static function (Env $env) use ($enable): void {
        $t = $enable($env);
        Tenant::enter(Platform::tenantBySlug($t['slug']));
        try {
            T::ok(\App\Platform\Demo::active(), 'inside the demo network');
            $threw = '';
            try {
                (new \App\MikroTik\Client('192.0.2.1', 8728, false, 2.0))->connect('admin', 'x');
            } catch (\App\MikroTik\MikroTikException $e) {
                $threw = $e->getMessage();
            }
            T::eq('غير متاح في الشبكة التجريبية.', $threw, 'no connection to any MikroTik device');
            T::eq(false, \App\Services\Telegram::call('getMe', [], 5, '123:abc')['ok'], 'no Telegram');
            T::eq(false, \App\Services\TweetSms::call('sendSMS', [], 'k')['ok'], 'no SMS');
            T::eq(false, \App\Services\Mailer::send(['host' => 'smtp.example.com', 'from' => 'a@example.com', 'port' => 587, 'secure' => 'tls', 'user' => '', 'pass' => ''], 'b@example.com', 's', 'h', 't')['ok'], 'no email');
            T::eq(false, \App\Services\MadixCloud::call('ping', [], null, ['base' => 'https://cloud.example.com', 'key' => 'k'])['ok'], 'no cloud calls');
            $src = (string) file_get_contents(APP_PATH . '/Services/Ai.php');
            T::eq(2, substr_count($src, "if (\\App\\Platform\\Demo::active()) {\n            return ['status' => 0, 'data' => null, 'error' => \\App\\Platform\\Demo::MESSAGE];"), 'no AI (both providers)');
            $wa = (string) file_get_contents(APP_PATH . '/Services/WhatsApp.php');
            T::contains('Demo::active()', $wa, 'no WhatsApp');
        } finally {
            Tenant::leave();
        }
        T::ok(!\App\Platform\Demo::active(), 'outside: normal networks unaffected');
    },
    'visitor analytics: opens, device, source, duration and pages in order (105+)' => static function (Env $env) use ($enable, $visitor): void {
        $t = $enable($env);
        $pdo = $env->db();
        $pdo->exec('DELETE FROM demo_visits');
        $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';
        $h = $visitor($env, $t, ['User-Agent' => $ua, 'Referer' => 'https://www.google.com/search?q=madix']);
        foreach (['/subscribers', '/cards', '/map'] as $p) {
            $h->request('GET', '/' . $t['slug'] . $p, null, ['User-Agent' => $ua]);
        }
        usleep(300000);
        $v = $pdo->query('SELECT * FROM demo_visits ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        T::ok($v !== false, 'visit recorded');
        T::eq(['mobile', 'Chrome', 'Android'], [$v['device'], $v['browser'], $v['os']], 'device, browser and system');
        T::eq('google.com', $v['source'], 'source from the referrer');
        T::eq(null, $v['country'], 'local address: no country');
        T::eq(4, (int) $v['pages'], 'pages counted');
        $paths = $pdo->query('SELECT path FROM demo_pageviews WHERE visit_id = ' . (int) $v['id'] . ' ORDER BY created_at, id')->fetchAll(PDO::FETCH_COLUMN);
        T::eq(['/', '/subscribers', '/cards', '/map'], $paths, 'pages in the order visited');
        // نفس الزائر يعود: زيارة جديدة باسمه نفسه بعد انتهاء الزيارة الأولى
        $pdo->exec("UPDATE demo_visits SET last_at = NOW() - INTERVAL 2 HOUR WHERE id = " . (int) $v['id']);
        $h->request('GET', '/' . $t['slug'] . '/start', null, ['User-Agent' => $ua]);
        T::eq(2, (int) $pdo->query('SELECT COUNT(*) FROM demo_visits WHERE visitor = ' . $pdo->quote($v['visitor']))->fetchColumn(), 'second open of the same visitor');
        $admin = $env->admin();
        $page = $admin->get('/admin/demo')['body'];
        T::contains('data-demo-visits', $page, 'visits in the admin panel');
        T::contains('2 مرات', $page, 'number of opens of the visitor');
        $detail = $admin->get('/admin/demo/visits/' . (int) $v['id']);
        T::status(200, $detail);
        T::ok(strpos($detail['body'], '/subscribers') < strpos($detail['body'], '/map'), 'pages listed in order');
        T::eq('US', \App\Support\IpCountry::of('8.8.8.8'), 'country from the local file');
        T::eq('PS', \App\Support\IpCountry::of('82.213.0.1'), 'Palestine');
        T::eq(null, \App\Support\IpCountry::of('192.168.1.10'), 'private address: no country');
        // ملف التعريف موقّع بمفتاح الشبكة: رقم زيارة مزوّر لا يُقبل
        Tenant::enter(Platform::tenantBySlug($t['slug']));
        try {
            $_COOKIE['mnx_dv'] = (int) $v['id'] . '.' . str_repeat('0', 32);
            T::eq(null, \App\Platform\Demo::visitId(), 'forged visit id rejected');
            $_COOKIE['mnx_dv'] = 'x';
            T::eq(null, \App\Platform\Demo::visitId(), 'malformed cookie rejected');
        } finally {
            unset($_COOKIE['mnx_dv']);
            Tenant::leave();
        }
    },
    'back to the original every hour and from the admin panel, off from the admin panel, admin permission (105)' => static function (Env $env) use ($enable, $visitor): void {
        $t = $enable($env);
        $db = $env->db($t['db_name']);
        $db->exec("INSERT INTO subscribers (full_name, phone, username, password, plan_id, status, created_at) VALUES ('زائر', '0590000001', 'visitor-made', '1', 1, 'active', NOW())");
        $env->db()->exec("UPDATE platform_settings SET `value` = '2000-01-01 00:00:00' WHERE `key` = 'demo.reset_at'");
        $env->cli('cli/cron.php', ['30']);
        T::eq(0, (int) $db->query("SELECT COUNT(*) FROM subscribers WHERE username = 'visitor-made'")->fetchColumn(), 'hourly reset removed visitor changes');
        T::ok(!glob(BASE_PATH . '/storage/tenants/' . $t['slug'] . '/backups/*'), 'no automatic backups for the demo network');
        T::ok(strtotime((string) $env->db()->query("SELECT `value` FROM platform_settings WHERE `key` = 'demo.reset_at'")->fetchColumn()) > time() - 120, 'reset time recorded');
        $db->exec("INSERT INTO subscribers (full_name, phone, username, password, plan_id, status, created_at) VALUES ('زائر', '0590000002', 'visitor-made-2', '1', 1, 'active', NOW())");
        $admin = $env->admin();
        $admin->get('/admin/demo');
        T::status(302, $admin->post('/admin/demo/reset'), 'reset from the admin panel');
        T::eq(0, (int) $db->query("SELECT COUNT(*) FROM subscribers WHERE username = 'visitor-made-2'")->fetchColumn(), 'reset now');
        T::ok((int) $env->db()->query("SELECT COUNT(*) FROM platform_activity WHERE action = 'platform.demo'")->fetchColumn() >= 2, 'in the platform activity log');
        // مسؤول بلا صلاحية التصاميم وصفحة الهبوط
        $env->db()->prepare('INSERT INTO platform_admins (name, username, password_hash, is_active, permissions, created_at) VALUES (?, ?, ?, 1, ?, NOW())')
            ->execute(['دعم', 'support1', password_hash('SupportPass123', PASSWORD_DEFAULT), json_encode(['support.manage'])]);
        $limited = $env->http();
        $limited->login('/admin/login', 'support1', 'SupportPass123');
        T::eq(403, $limited->get('/admin/demo')['status'], 'no permission: no demo page');
        // الإيقاف
        $admin->get('/admin/demo');
        T::status(302, $admin->post('/admin/demo', []), 'turned off');
        T::notContains('data-lp-try', $env->http()->get('/')['body'], 'try now gone from the landing page');
        $r = $env->http()->get('/' . $t['slug'] . '/start');
        T::ok($r['status'] !== 302, 'no entry while off');
        $admin->get('/admin/demo');
        $admin->post('/admin/demo', ['enabled' => '1']);
    },
    'a failed reset is not counted, deleting the demo network turns try now off (105)' => static function (Env $env) use ($enable): void {
        $t = $enable($env);
        // تجهيز يفشل في منتصفه: لا يُسجَّل وقت التجهيز فتعيد المهمة المجدولة المحاولة، ولا تبقى صفحة الانتظار
        $env->db()->exec("UPDATE platform_settings SET `value` = '2000-01-01 00:00:00' WHERE `key` = 'demo.reset_at'");
        $broken = $t;
        $broken['db_name'] = 'mnxtest_missing_db_' . bin2hex(random_bytes(3));
        $threw = false;
        try {
            \App\Platform\Demo::build($broken);
        } catch (\Throwable) {
            $threw = true;
        }
        Platform::flushSettings();
        T::ok($threw, 'reset failed');
        T::eq('2000-01-01 00:00:00', Platform::setting('demo.reset_at'), 'failed reset not recorded');
        T::eq('0', Platform::setting('demo.building_until'), 'no waiting page left');
        // حذف الشبكة التجريبية من قائمة الحسابات
        $admin = $env->admin();
        $admin->get('/admin/tenants/' . (int) $t['id']);
        T::status(302, $admin->post('/admin/tenants/' . (int) $t['id'] . '/delete', ['confirm' => $t['slug']]), 'demo network deleted');
        Platform::flushSettings();
        T::eq('0', Platform::setting('demo.enabled'), 'try now off with it');
        T::notContains('data-lp-try', $env->http()->get('/')['body'], 'no try now link to a deleted network');
        // تشغيلها من جديد ينشئها كاملة
        $admin->get('/admin/demo');
        T::status(302, $admin->post('/admin/demo', ['enabled' => '1']), 'turned on again');
        \App\Platform\Demo::forget();
        $again = Platform::row('SELECT * FROM tenants WHERE is_demo = 1 LIMIT 1');
        T::ok($again !== null && (int) $env->db($again['db_name'])->query('SELECT COUNT(*) FROM subscribers')->fetchColumn() > 100, 'created again with its data');
        $env->tenants['demo-net'] = ['slug' => $again['slug'], 'db' => $again['db_name'], 'path' => '/' . $again['slug']];
    },
];
