<?php
declare(strict_types=1);

/*
 * بيانات تجريبية كبيرة لقياس السرعة وتجربة الواجهة.
 *
 *   php tests/seed-demo.php [--slug=demo] [--subscribers=5000] [--cards=100000] [--create] [--user=owner] [--password=...]
 *
 * وضع المنصة: --slug اسم الحساب (يُنشأ مع --create إن لم يكن موجوداً).
 * شبكة واحدة: بلا --slug، تُضاف البيانات إلى قاعدة النظام.
 * أجهزة MikroTik التجريبية بعناوين لا تُستخدم (TEST-NET) ومراقبتها متوقفة، فلا يتصل النظام بأي جهاز حقيقي.
 * يُعاد تشغيله بأمان: يحذف بيانات التجربة السابقة (بادئة demo-) قبل الإدراج.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Tenant;

$opts = ['slug' => '', 'subscribers' => '5000', 'cards' => '100000', 'user' => 'owner', 'password' => 'OwnerPass123', 'name' => 'شبكة التجربة'];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)=(.*)$/s', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z_]+)$/', $a, $m)) {
        $opts[$m[1]] = '1';
    }
}
$nSubs = max(0, (int) $opts['subscribers']);
$nCards = max(0, (int) $opts['cards']);
$t0 = microtime(true);
$say = static function (string $m) use ($t0): void {
    fwrite(STDOUT, sprintf("[%5.1fs] %s\n", microtime(true) - $t0, $m));
};

if (Platform::enabled()) {
    Platform::boot();
    Platform::migrate();
    $slug = strtolower(trim($opts['slug'] ?: 'demo'));
    $tenant = Platform::tenantBySlug($slug);
    if (!$tenant) {
        if (empty($opts['create'])) {
            fwrite(STDERR, "الحساب {$slug} غير موجود (أضف --create).\n");
            exit(1);
        }
        $tenant = App\Platform\TenantProvisioner::create([
            'slug' => $slug, 'name' => $opts['name'], 'owner_name' => 'مالك التجربة', 'phone' => '0590000000', 'email' => null,
            'username' => $opts['user'], 'password' => $opts['password'], 'status' => 'active',
        ]);
        DB::update('tenants', ['paid_until' => date('Y-m-d H:i:s', time() + 365 * 86400), 'activated_at' => now()], 'id = ?', [$tenant['id']]);
        $tenant = Platform::tenantBySlug($slug);
        $say("أُنشئ الحساب {$slug} (المالك {$opts['user']})");
    }
    Tenant::enter($tenant);
}
App\Core\Migrator::run();

$pdo = DB::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('SET unique_checks = 0');

// --------------------------------------------------------------- تنظيف بيانات تجربة سابقة
$demoRouters = DB::column("SELECT id FROM routers WHERE name LIKE 'demo-%'");
foreach (['cards' => "username LIKE 'd%' AND batch_id IN (SELECT id FROM batches WHERE name LIKE 'demo-%')"] as $tbl => $where) {
    $ids = DB::column("SELECT id FROM $tbl WHERE $where");
    foreach (array_chunk($ids, 5000) as $ch) {
        DB::query("DELETE FROM logins WHERE owner_type IN ('card','card_short') AND owner_id IN (" . DB::in($ch) . ')', $ch);
        DB::query('DELETE FROM card_routers WHERE card_id IN (' . DB::in($ch) . ')', $ch);
        DB::query('DELETE FROM cards WHERE id IN (' . DB::in($ch) . ')', $ch);
    }
}
$subIds = DB::column("SELECT id FROM subscribers WHERE username LIKE 'demo%'");
foreach (array_chunk($subIds, 5000) as $ch) {
    DB::query("DELETE FROM logins WHERE owner_type = 'subscriber' AND owner_id IN (" . DB::in($ch) . ')', $ch);
    DB::query('DELETE FROM subscriber_transactions WHERE subscriber_id IN (' . DB::in($ch) . ')', $ch);
    DB::query('DELETE FROM subscriber_routers WHERE subscriber_id IN (' . DB::in($ch) . ')', $ch);
    DB::query('DELETE FROM subscribers WHERE id IN (' . DB::in($ch) . ')', $ch);
}
DB::query("DELETE FROM sales WHERE notes = 'demo'");
DB::query("DELETE FROM batches WHERE name LIKE 'demo-%'");
if ($demoRouters) {
    foreach (['router_metrics', 'router_power', 'sessions', 'login_log', 'alerts', 'outages'] as $tbl) {
        DB::query("DELETE FROM $tbl WHERE router_id IN (" . DB::in($demoRouters) . ')', $demoRouters);
    }
    DB::query('DELETE FROM routers WHERE id IN (' . DB::in($demoRouters) . ')', $demoRouters);
}
DB::query("DELETE FROM categories WHERE name LIKE 'demo-%'");
DB::query("DELETE FROM plans WHERE name LIKE 'demo-%'");
DB::query("DELETE FROM distributors WHERE notes = 'demo'");
DB::query("DELETE FROM site_links WHERE notes = 'demo'");
DB::query("DELETE FROM sites WHERE notes = 'demo'");
DB::query("DELETE FROM areas WHERE notes = 'demo'");
DB::query("DELETE FROM audit_log WHERE action = 'demo.seed'");
$say('نُظّفت بيانات التجربة السابقة');

mt_srand(20261125);
$now = time();
$dt = static fn (int $ts): string => date('Y-m-d H:i:s', $ts);
$rand = static fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$owner = (int) DB::value('SELECT id FROM employees WHERE is_owner = 1 ORDER BY id LIMIT 1');

// --------------------------------------------------------------- المناطق والأبراج والأجهزة
$areaNames = ['الرمال', 'الشجاعية', 'تل الهوى', 'النصر', 'الشيخ رضوان', 'الزيتون', 'الدرج', 'التفاح', 'الصبرة', 'الكرامة'];
$areas = [];
foreach ($areaNames as $n) {
    $areas[] = DB::insert('areas', ['name' => 'demo-' . $n, 'notes' => 'demo', 'created_at' => $dt($now)]);
}
$sites = [];
for ($i = 1; $i <= 20; $i++) {
    $sites[] = DB::insert('sites', [
        'name' => 'demo-برج ' . $i, 'type' => $i % 7 === 0 ? 'station' : 'tower', 'area_id' => $areas[$i % count($areas)],
        'lat' => 31.50 + mt_rand(0, 900) / 10000, 'lng' => 34.42 + mt_rand(0, 900) / 10000, 'height_m' => mt_rand(12, 40),
        'notes' => 'demo', 'created_at' => $dt($now),
    ]);
}
for ($i = 1; $i < count($sites); $i++) {
    DB::insert('site_links', ['site_a' => $sites[$i - 1], 'site_b' => $sites[$i], 'type' => $i % 3 ? 'wireless' : 'fiber', 'capacity' => '300M', 'notes' => 'demo', 'created_at' => $dt($now)]);
}
$routers = [];
$secret = Crypto::encrypt('demo-password');
for ($i = 1; $i <= 30; $i++) {
    $online = $i % 9 !== 0;
    $routers[] = DB::insert('routers', [
        'name' => 'demo-MikroTik ' . $i, 'area_id' => $areas[$i % count($areas)], 'site_id' => $sites[$i % count($sites)],
        'lat' => 31.50 + mt_rand(0, 900) / 10000, 'lng' => 34.42 + mt_rand(0, 900) / 10000,
        // TEST-NET-3: عنوان توثيقي لا يصل إلى أي جهاز
        'host' => '203.0.113.' . $i, 'api_port' => 8728, 'api_username' => 'madix', 'api_password' => $secret,
        'router_key' => bin2hex(random_bytes(16)), 'is_active' => 1,
        'status' => $online ? 'online' : 'offline', 'status_changed_at' => $dt($now - mt_rand(600, 86400)),
        'last_seen_at' => $dt($online ? $now - 30 : $now - 7200), 'last_polled_at' => $dt($now - 30),
        // المراقبة متوقفة لهذه الأجهزة: لا محاولات اتصال أثناء القياس
        'next_poll_at' => $dt($now + 10 * 365 * 86400),
        'identity' => 'demo-' . $i, 'board_name' => $rand(['hAP ac2', 'RB4011', 'CCR2004', 'hEX S']), 'model' => 'RB' . mt_rand(700, 5009),
        'version' => '7.' . mt_rand(10, 16) . '.' . mt_rand(0, 3), 'ros_major' => 7, 'architecture' => 'arm',
        'uptime_seconds' => mt_rand(3600, 3000000), 'cpu_load' => mt_rand(2, 85), 'cpu_count' => 4,
        'memory_total' => 1073741824, 'memory_free' => mt_rand(200, 900) * 1048576, 'hdd_total' => 134217728, 'hdd_free' => 90000000,
        'temperature' => mt_rand(380, 650) / 10, 'voltage' => mt_rand(230, 270) / 10,
        'active_hotspot' => $online ? mt_rand(10, 140) : 0, 'active_ppp' => $online ? mt_rand(5, 60) : 0,
        'rx_bps' => $online ? mt_rand(1, 90) * 1000000 : 0, 'tx_bps' => $online ? mt_rand(1, 30) * 1000000 : 0,
        'metrics_at' => $dt($now - 60), 'info_synced_at' => $dt($now - 600),
        'created_at' => $dt($now - 200 * 86400),
    ]);
}
$say('30 جهاز MikroTik و20 برجاً و10 مناطق');

// --------------------------------------------------------------- قراءات المراقبة: 7 أيام كل 5 دقائق
$rows = [];
$from = $now - 7 * 86400;
foreach ($routers as $k => $rid) {
    for ($ts = $from; $ts <= $now; $ts += 300) {
        $hour = (int) date('G', $ts);
        $load = 0.35 + 0.65 * max(0, sin(($hour - 6) / 24 * M_PI * 2));
        $up = !($k % 9 === 8 && $ts > $now - 7200);
        $rows[] = [
            'router_id' => $rid, 'created_at' => $dt($ts), 'online' => $up ? 1 : 0, 'cpu_load' => $up ? (int) (10 + 60 * $load) : null,
            'memory_used_pct' => $up ? mt_rand(30, 70) : null, 'active_hotspot' => $up ? (int) (20 + 120 * $load) : null,
            'active_ppp' => $up ? (int) (5 + 50 * $load) : null, 'rx_bps' => $up ? (int) (80000000 * $load) : null, 'tx_bps' => $up ? (int) (20000000 * $load) : null,
        ];
        if (count($rows) >= 4000) {
            DB::insertMany('router_metrics', $rows);
            $rows = [];
        }
    }
}
DB::insertMany('router_metrics', $rows);
$rows = [];
foreach (array_slice($routers, 0, 10) as $rid) {
    for ($ts = $from; $ts <= $now; $ts += 300) {
        $rows[] = ['router_id' => $rid, 'created_at' => $dt($ts), 'voltage' => 24 + mt_rand(-20, 30) / 10, 'temperature' => mt_rand(300, 550) / 10];
        if (count($rows) >= 4000) {
            DB::insertMany('router_power', $rows);
            $rows = [];
        }
    }
}
DB::insertMany('router_power', $rows);
$say('قراءات المراقبة والطاقة لسبعة أيام');

// --------------------------------------------------------------- الباقات والموزعون والكروت
$catDefs = [['ساعة', 1, 3600, 86400], ['ساعتين', 2, 7200, 86400], ['يوم', 5, 86400, 86400], ['أسبوع', 20, null, 604800],
    ['شهر', 60, null, 2592000], ['5 جيجا', 10, null, 2592000], ['10 جيجا', 18, null, 2592000], ['ليلي', 3, 28800, 43200]];
$cats = [];
foreach ($catDefs as $i => [$n, $price, $time, $valid]) {
    $cats[] = ['id' => DB::insert('categories', [
        'name' => 'demo-' . $n, 'price' => $price, 'color' => $rand(['#2453d6', '#0e7490', '#047857', '#c2410c', '#6d28d9', '#be123c']),
        'time_limit' => $time, 'validity' => $valid, 'rate_up' => '1M', 'rate_down' => '4M', 'shared_users' => 1, 'sort' => $i,
        'low_stock' => 50, 'created_at' => $dt($now - 300 * 86400),
    ]), 'price' => $price];
}
$dists = [];
for ($i = 1; $i <= 30; $i++) {
    $dists[] = DB::insert('distributors', ['name' => 'موزع ' . $i, 'phone' => '059' . mt_rand(1000000, 9999999), 'area_id' => $areas[$i % count($areas)],
        'discount_percent' => $rand([0, 5, 10, 15]), 'balance' => mt_rand(-500, 2000), 'notes' => 'demo', 'created_at' => $dt($now - 300 * 86400)]);
}
$batchSize = 2000;
$cardNo = 0;
while ($cardNo < $nCards) {
    $cat = $cats[intdiv($cardNo, $batchSize) % count($cats)];
    $qty = min($batchSize, $nCards - $cardNo);
    $created = $now - mt_rand(1, 300) * 86400;
    $batch = DB::insert('batches', ['name' => 'demo-دفعة ' . (intdiv($cardNo, $batchSize) + 1), 'category_id' => $cat['id'], 'source' => 'generated',
        'quantity' => $qty, 'created_by' => $owner ?: null, 'created_at' => $dt($created)]);
    // نصف كروت الدفعة تُباع لموزع في عملية بيع واحدة
    $sold = intdiv($qty, 2);
    $dist = $rand($dists);
    $soldAt = $created + mt_rand(1, 20) * 86400;
    $soldAt = min($soldAt, $now - 3600);
    $sale = DB::insert('sales', ['type' => 'distributor', 'distributor_id' => $dist, 'category_id' => $cat['id'], 'quantity' => $sold, 'unit_price' => $cat['price'],
        'total' => $cat['price'] * $sold, 'paid' => $cat['price'] * $sold, 'notes' => 'demo', 'employee_id' => $owner ?: null, 'created_at' => $dt($soldAt)]);
    $rows = [];
    for ($j = 0; $j < $qty; $j++) {
        $cardNo++;
        $isSold = $j < $sold;
        $status = 'new';
        $first = $exp = $ended = null;
        if ($isSold) {
            $r = mt_rand(1, 100);
            $status = $r <= 30 ? 'sold' : ($r <= 55 ? 'active' : ($r <= 92 ? 'expired' : 'depleted'));
            if ($status !== 'sold') {
                $first = $soldAt + mt_rand(60, 5 * 86400);
                $exp = $status === 'active' ? $now + mt_rand(600, 20 * 86400) : $first + 86400;
                $ended = $status === 'active' ? null : $exp;
            }
        }
        $user = sprintf('d%07d', $cardNo);
        $rows[] = [
            'batch_id' => $batch, 'category_id' => $cat['id'], 'username' => $user, 'password' => (string) mt_rand(1000, 9999),
            'status' => $status, 'distributor_id' => $isSold ? $dist : null, 'sale_id' => $isSold ? $sale : null,
            'sold_at' => $isSold ? $dt($soldAt) : null, 'sale_price' => $isSold ? $cat['price'] : null,
            'first_login_at' => $first ? $dt($first) : null, 'last_login_at' => $first ? $dt(min($now, $first + 3600)) : null,
            'expires_at' => $exp ? $dt($exp) : null, 'ended_at' => $ended ? $dt($ended) : null,
            'used_seconds' => $first ? mt_rand(60, 86400) : 0, 'used_bytes_in' => $first ? mt_rand(1, 4000) * 1048576 : 0, 'used_bytes_out' => $first ? mt_rand(1, 400) * 1048576 : 0,
            'last_mac' => $first ? sprintf('02:00:%02X:%02X:%02X:%02X', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)) : null,
            'last_router_id' => $first ? $rand($routers) : null, 'created_at' => $dt($created),
        ];
    }
    foreach (array_chunk($rows, 1000) as $ch) {
        DB::insertMany('cards', $ch);
    }
}
// سجل أسماء الدخول (المعرّف الحقيقي لكل كرت)
DB::query("INSERT IGNORE INTO logins (login, owner_type, owner_id) SELECT c.username, 'card', c.id FROM cards c JOIN batches b ON b.id = c.batch_id WHERE b.name LIKE 'demo-%'");
$say(number_format($nCards) . ' كرت في ' . count($cats) . ' باقات');

// --------------------------------------------------------------- الباقات الشهرية والمشتركون
$planDefs = [['4 ميجا', 50], ['8 ميجا', 80], ['16 ميجا', 120], ['25 ميجا', 160], ['50 ميجا', 250], ['منزلي اقتصادي', 35]];
$plans = [];
foreach ($planDefs as $i => [$n, $p]) {
    $plans[] = ['id' => DB::insert('plans', ['name' => 'demo-' . $n, 'service' => 'ppp', 'price' => $p, 'duration_days' => 30, 'rate_up' => '2M', 'rate_down' => '8M',
        'sort' => $i, 'created_at' => $dt($now - 300 * 86400)]), 'price' => $p];
}
$first = ['محمد', 'أحمد', 'محمود', 'خالد', 'يوسف', 'إبراهيم', 'عمر', 'علي', 'سامي', 'رامي', 'فاطمة', 'مريم', 'آية', 'هدى', 'سارة', 'نور', 'إسراء', 'ياسمين'];
$last = ['أبو سلطان', 'الشوا', 'حلس', 'الريس', 'عاشور', 'الغول', 'السقا', 'شحادة', 'أبو شعبان', 'النجار', 'الحلو', 'قديح', 'المصري', 'الأسطل', 'الفرا'];
$rows = [];
for ($i = 1; $i <= $nSubs; $i++) {
    $plan = $plans[$i % count($plans)];
    $exp = $now + mt_rand(-40, 35) * 86400;
    $rows[] = [
        'full_name' => $rand($first) . ' ' . $rand($first) . ' ' . $rand($last), 'phone' => '059' . str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
        'address' => 'شارع ' . mt_rand(1, 60), 'area_id' => $areas[$i % count($areas)], 'router_id' => $routers[$i % count($routers)], 'site_id' => $sites[$i % count($sites)],
        'lat' => 31.50 + mt_rand(0, 900) / 10000, 'lng' => 34.42 + mt_rand(0, 900) / 10000,
        'username' => sprintf('demo%05d', $i), 'password' => (string) mt_rand(100000, 999999), 'plan_id' => $plan['id'],
        'status' => $i % 23 === 0 ? 'suspended' : 'active', 'expires_at' => $dt($exp), 'balance' => mt_rand(-3, 1) * 10,
        'router_state' => 'enabled', 'last_online_at' => $dt($now - mt_rand(60, 30 * 86400)),
        'created_by' => $owner ?: null, 'created_at' => $dt($now - mt_rand(30, 700) * 86400),
    ];
    if (count($rows) >= 1000) {
        DB::insertMany('subscribers', $rows);
        $rows = [];
    }
}
DB::insertMany('subscribers', $rows);
DB::query("INSERT IGNORE INTO logins (login, owner_type, owner_id) SELECT username, 'subscriber', id FROM subscribers WHERE username LIKE 'demo%'");
// أربع حركات مالية لكل مشترك على مدى أربعة أشهر
$subRows = DB::rows("SELECT id, plan_id FROM subscribers WHERE username LIKE 'demo%'");
$priceOf = array_column($plans, 'price', 'id');
$rows = [];
foreach ($subRows as $s) {
    for ($m = 0; $m < 4; $m++) {
        $ts = $now - $m * 30 * 86400 - mt_rand(0, 25) * 86400;
        $rows[] = ['subscriber_id' => $s['id'], 'type' => $m % 2 ? 'payment' : 'renewal', 'amount' => $priceOf[$s['plan_id']] ?? 50, 'plan_id' => $s['plan_id'],
            'period_from' => $dt($ts), 'period_to' => $dt($ts + 30 * 86400), 'method' => 'cash', 'employee_id' => $owner ?: null, 'created_at' => $dt($ts)];
        if (count($rows) >= 2000) {
            DB::insertMany('subscriber_transactions', $rows);
            $rows = [];
        }
    }
}
DB::insertMany('subscriber_transactions', $rows);
$say(number_format($nSubs) . ' مشترك وحركاتهم المالية');

// --------------------------------------------------------------- الجلسات وسجل الدخول والتنبيهات
$cardSample = DB::rows("SELECT c.id, c.username, c.last_mac FROM cards c JOIN batches b ON b.id = c.batch_id WHERE b.name LIKE 'demo-%' AND c.status IN ('active','expired') ORDER BY c.id LIMIT 30000");
$rows = [];
$open = 0;
foreach ($cardSample as $k => $c) {
    $start = $now - mt_rand(60, 30 * 86400);
    $isOpen = $k < 1500;
    $open += $isOpen ? 1 : 0;
    $rows[] = ['router_id' => $routers[$k % count($routers)], 'service' => 'hotspot', 'username' => $c['username'], 'card_id' => $c['id'],
        'mac' => $c['last_mac'], 'ip' => '10.5.' . mt_rand(0, 255) . '.' . mt_rand(1, 254), 'started_at' => $dt($isOpen ? $now - mt_rand(60, 7200) : $start),
        'updated_at' => $dt($isOpen ? $now - 30 : $start + 3600), 'stopped_at' => $isOpen ? null : $dt($start + mt_rand(300, 7200)),
        'uptime' => mt_rand(60, 7200), 'bytes_in' => mt_rand(1, 900) * 1048576, 'bytes_out' => mt_rand(1, 90) * 1048576,
        'rate_down' => $isOpen ? mt_rand(1, 8000) * 1000 : null, 'rate_up' => $isOpen ? mt_rand(1, 900) * 1000 : null];
    if (count($rows) >= 2000) {
        DB::insertMany('sessions', $rows);
        $rows = [];
    }
}
DB::insertMany('sessions', $rows);
$rows = [];
foreach (array_slice($cardSample, 0, 20000) as $k => $c) {
    $rows[] = ['created_at' => $dt($now - mt_rand(60, 30 * 86400)), 'router_id' => $routers[$k % count($routers)], 'source' => 'portal', 'event' => $k % 5 ? 'login' : 'check',
        'login' => $c['username'], 'mac' => $c['last_mac'], 'ip' => '10.5.' . mt_rand(0, 255) . '.' . mt_rand(1, 254), 'result' => $k % 17 ? 'accept' : 'reject',
        'reason' => $k % 17 ? null : 'wrong_password', 'card_id' => $c['id']];
    if (count($rows) >= 2000) {
        DB::insertMany('login_log', $rows);
        $rows = [];
    }
}
DB::insertMany('login_log', $rows);
$rows = [];
for ($i = 0; $i < 300; $i++) {
    $rid = $rand($routers);
    $rows[] = ['type' => $i % 3 ? 'router_down' : 'router_up', 'level' => $i % 3 ? 'danger' : 'success', 'title' => ($i % 3 ? 'انقطع ' : 'عاد ') . 'جهاز MikroTik',
        'router_id' => $rid, 'is_read' => $i > 20 ? 1 : 0, 'created_at' => $dt($now - $i * 3600)];
}
DB::insertMany('alerts', $rows);
$rows = [];
for ($i = 0; $i < 40; $i++) {
    $st = $now - mt_rand(1, 30) * 86400;
    $rows[] = ['router_id' => $rand($routers), 'area_id' => null, 'title' => 'انقطاع برج', 'cause' => $rand(['power', 'fault', 'unknown']), 'source' => 'auto',
        'started_at' => $dt($st), 'ended_at' => $dt($st + mt_rand(600, 20000)), 'affected' => mt_rand(5, 120), 'created_at' => $dt($st)];
}
DB::insertMany('outages', $rows);
$rows = [];
for ($i = 0; $i < 5000; $i++) {
    $rows[] = ['employee_id' => $owner ?: null, 'action' => 'demo.seed', 'entity_type' => 'subscriber', 'entity_id' => mt_rand(1, max(1, $nSubs)),
        'summary' => 'تعديل مشترك (بيانات تجريبية)', 'ip' => '127.0.0.1', 'created_at' => $dt($now - $i * 600)];
}
DB::insertMany('audit_log', $rows);
$say("$open جلسة متصلة الآن، وسجل الدخول والتنبيهات والانقطاعات");

$pdo->exec('SET unique_checks = 1');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach (['cards', 'subscribers', 'sessions', 'login_log', 'router_metrics', 'subscriber_transactions', 'sales', 'logins'] as $tbl) {
    $pdo->query("ANALYZE TABLE `$tbl`")->fetchAll();
}
$say('تم.');
