<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\Crypto;
use App\Core\DB;

/**
 * البند 105: بيانات الشبكة التجريبية («جرّب الآن»): شبكة كاملة صغيرة بأوقات نسبية إلى الآن
 * (الرسوم والمتصلون الآن وملخص السنة تبدو حية بعد كل إعادة تجهيز).
 * أجهزة MikroTik بعناوين توثيقية (TEST-NET) لا تصل إلى أي جهاز، ومراقبتها متوقفة؛ والاتصال نفسه ممنوع في الشبكة التجريبية.
 * تُستدعى داخل قاعدة الشبكة التجريبية بعد إنشاء جداولها من جديد.
 */
final class DemoSeed
{
    public static function run(?int $now = null): void
    {
        $now ??= time();
        mt_srand(20261126);
        $dt = static fn (int $ts): string => date('Y-m-d H:i:s', $ts);
        $pick = static fn (array $a) => $a[mt_rand(0, count($a) - 1)];
        $owner = (int) DB::value('SELECT id FROM employees WHERE is_owner = 1 ORDER BY id LIMIT 1') ?: null;
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        // ---------------------------------------------------------- المناطق والأبراج والروابط
        $areas = [];
        foreach (['الرمال', 'تل الهوى', 'النصر', 'الشيخ رضوان', 'الزيتون', 'التفاح'] as $n) {
            $areas[] = DB::insert('areas', ['name' => $n, 'created_at' => $dt($now - 400 * 86400)]);
        }
        $sites = [];
        $towers = ['برج الشمال', 'برج السوق', 'برج الجامعة', 'برج الميناء', 'برج المستشفى', 'محطة الرئيسية', 'برج الحديقة', 'برج المدارس', 'برج الشاطئ', 'برج الملعب'];
        foreach ($towers as $i => $n) {
            $sites[] = DB::insert('sites', [
                'name' => $n, 'type' => str_starts_with($n, 'محطة') ? 'station' : 'tower', 'area_id' => $areas[$i % count($areas)],
                'lat' => 31.50 + mt_rand(0, 800) / 10000, 'lng' => 34.42 + mt_rand(0, 800) / 10000, 'height_m' => mt_rand(12, 40),
                'created_at' => $dt($now - 400 * 86400),
            ]);
        }
        for ($i = 1; $i < count($sites); $i++) {
            DB::insert('site_links', ['site_a' => $sites[$i - 1], 'site_b' => $sites[$i], 'type' => $i % 3 ? 'wireless' : 'fiber', 'capacity' => $i % 3 ? '300 Mbps' : '1 Gbps', 'created_at' => $dt($now)]);
        }

        // ---------------------------------------------------------- أجهزة MikroTik (عناوين TEST-NET، مراقبة متوقفة)
        $routers = [];
        $secret = Crypto::encrypt('demo-' . bin2hex(random_bytes(6)));
        for ($i = 1; $i <= 14; $i++) {
            $online = $i % 7 !== 0;
            $routers[] = DB::insert('routers', [
                'name' => 'MikroTik ' . $towers[$i % count($towers)] . ($i > count($towers) ? ' 2' : ''), 'area_id' => $areas[$i % count($areas)], 'site_id' => $sites[$i % count($sites)],
                'host' => '203.0.113.' . $i, 'api_port' => 8728, 'api_username' => 'madix', 'api_password' => $secret,
                'router_key' => bin2hex(random_bytes(16)), 'is_active' => 1,
                'status' => $online ? 'online' : 'offline', 'status_changed_at' => $dt($now - mt_rand(600, 86400)),
                'last_seen_at' => $dt($online ? $now - 30 : $now - 5400), 'last_polled_at' => $dt($now - 30),
                'next_poll_at' => $dt($now + 10 * 365 * 86400),
                'identity' => 'tower-' . $i, 'board_name' => $pick(['hAP ac2', 'RB4011', 'CCR2004', 'hEX S']), 'model' => 'RB' . mt_rand(700, 5009),
                'version' => '7.' . mt_rand(12, 16) . '.' . mt_rand(0, 3), 'ros_major' => 7, 'architecture' => 'arm',
                'uptime_seconds' => mt_rand(3600, 3000000), 'cpu_load' => mt_rand(4, 70), 'cpu_count' => 4,
                'memory_total' => 1073741824, 'memory_free' => mt_rand(250, 900) * 1048576, 'hdd_total' => 134217728, 'hdd_free' => 90000000,
                'temperature' => mt_rand(380, 600) / 10, 'voltage' => mt_rand(235, 268) / 10,
                'active_hotspot' => $online ? mt_rand(15, 120) : 0, 'active_ppp' => $online ? mt_rand(5, 45) : 0,
                'rx_bps' => $online ? mt_rand(4, 80) * 1000000 : 0, 'tx_bps' => $online ? mt_rand(1, 25) * 1000000 : 0,
                'metrics_at' => $dt($now - 60), 'info_synced_at' => $dt($now - 600), 'created_at' => $dt($now - 380 * 86400),
            ]);
        }

        // ---------------------------------------------------------- قراءات المراقبة لليومين الأخيرين (الرسوم الحية وإعادة تشغيل اليوم)
        $rows = [];
        foreach ($routers as $k => $rid) {
            for ($ts = $now - 2 * 86400; $ts <= $now; $ts += 300) {
                $load = 0.35 + 0.65 * max(0, sin(((int) date('G', $ts) - 6) / 24 * M_PI * 2));
                $up = !(($k + 1) % 7 === 0 && $ts > $now - 5400);
                $rows[] = [
                    'router_id' => $rid, 'created_at' => $dt($ts), 'online' => $up ? 1 : 0, 'cpu_load' => $up ? (int) (8 + 55 * $load) : null,
                    'memory_used_pct' => $up ? mt_rand(30, 65) : null, 'active_hotspot' => $up ? (int) (15 + 100 * $load) : null,
                    'active_ppp' => $up ? (int) (5 + 40 * $load) : null, 'rx_bps' => $up ? (int) (70000000 * $load) : null, 'tx_bps' => $up ? (int) (18000000 * $load) : null,
                ];
                if (count($rows) >= 3000) {
                    DB::insertMany('router_metrics', $rows);
                    $rows = [];
                }
            }
        }
        DB::insertMany('router_metrics', $rows);
        $rows = [];
        foreach (array_slice($routers, 0, 6) as $rid) {
            for ($ts = $now - 2 * 86400; $ts <= $now; $ts += 600) {
                $rows[] = ['router_id' => $rid, 'created_at' => $dt($ts), 'voltage' => 24 + mt_rand(-15, 28) / 10, 'temperature' => mt_rand(300, 520) / 10];
            }
        }
        DB::insertMany('router_power', $rows);

        // ---------------------------------------------------------- باقات الكروت والموزعون والكروت والمبيعات (سنة كاملة)
        $catDefs = [['ساعة', 1, 3600, 86400, '#2453d6'], ['ساعتين', 2, 7200, 86400, '#0e7490'], ['يوم', 5, 86400, 86400, '#047857'],
            ['أسبوع', 20, null, 604800, '#c2410c'], ['شهر', 60, null, 2592000, '#6d28d9'], ['10 جيجا', 18, null, 2592000, '#be123c']];
        $cats = [];
        foreach ($catDefs as $i => [$n, $price, $time, $valid, $color]) {
            $cats[] = ['id' => DB::insert('categories', [
                'name' => $n, 'price' => $price, 'color' => $color, 'time_limit' => $time, 'validity' => $valid,
                'rate_up' => '1M', 'rate_down' => '4M', 'shared_users' => 1, 'sort' => $i, 'low_stock' => 50, 'created_at' => $dt($now - 400 * 86400),
            ]), 'price' => $price];
        }
        $dists = [];
        foreach (['بقالة الأمل', 'مكتبة النور', 'سوبرماركت السلام', 'محل الهدى', 'كافيه الشاطئ', 'مكتبة الجامعة', 'بقالة الحي', 'محل الريان'] as $i => $n) {
            $dists[] = DB::insert('distributors', ['name' => $n, 'phone' => '059' . mt_rand(1000000, 9999999), 'area_id' => $areas[$i % count($areas)],
                'discount_percent' => $pick([0, 5, 10]), 'balance' => mt_rand(-200, 900), 'created_at' => $dt($now - 400 * 86400)]);
        }
        $cardNo = 0;
        $batchNo = 0;
        // دفعة لكل باقة في كل شهرين من السنة الماضية، يُباع جزء منها لموزع في الشهر نفسه
        for ($m = 11; $m >= 0; $m--) {
            foreach ($cats as $ci => $cat) {
                if (($m + $ci) % 2) {
                    continue;
                }
                $created = $now - $m * 30 * 86400 - mt_rand(0, 20) * 86400;
                $qty = 120 + 20 * ((11 - $m) % 5);
                $batch = DB::insert('batches', ['name' => 'دفعة ' . (++$batchNo), 'category_id' => $cat['id'], 'source' => 'generated',
                    'quantity' => $qty, 'created_by' => $owner, 'created_at' => $dt($created)]);
                $sold = (int) ($qty * (0.55 + 0.04 * (11 - $m) / 2));
                $dist = $pick($dists);
                $soldAt = min($created + mt_rand(1, 6) * 86400, $now - 3600);
                $sale = DB::insert('sales', ['type' => 'distributor', 'distributor_id' => $dist, 'category_id' => $cat['id'], 'quantity' => $sold, 'unit_price' => $cat['price'],
                    'total' => $cat['price'] * $sold, 'paid' => $cat['price'] * $sold, 'employee_id' => $owner, 'created_at' => $dt($soldAt)]);
                $rows = [];
                for ($j = 0; $j < $qty; $j++) {
                    $cardNo++;
                    $isSold = $j < $sold;
                    $status = 'new';
                    $first = $exp = $ended = null;
                    if ($isSold) {
                        $r = mt_rand(1, 100);
                        $recent = $m === 0;
                        $status = $r <= 25 ? 'sold' : ($recent && $r <= 70 ? 'active' : ($r <= 95 ? 'expired' : 'depleted'));
                        if ($status !== 'sold') {
                            $first = min($soldAt + mt_rand(60, 3 * 86400), $now - 600);
                            $exp = $status === 'active' ? $now + mt_rand(600, 10 * 86400) : $first + 86400;
                            $ended = $status === 'active' ? null : $exp;
                        }
                    }
                    $rows[] = [
                        'batch_id' => $batch, 'category_id' => $cat['id'], 'username' => sprintf('%08d', 10000000 + $cardNo * 7919 % 89999999), 'password' => (string) mt_rand(1000, 9999),
                        'status' => $status, 'distributor_id' => $isSold ? $dist : null, 'sale_id' => $isSold ? $sale : null,
                        'sold_at' => $isSold ? $dt($soldAt) : null, 'sale_price' => $isSold ? $cat['price'] : null,
                        'first_login_at' => $first ? $dt($first) : null, 'last_login_at' => $first ? $dt(min($now, $first + 3600)) : null,
                        'expires_at' => $exp ? $dt($exp) : null, 'ended_at' => $ended ? $dt($ended) : null,
                        'used_seconds' => $first ? mt_rand(60, 86400) : 0, 'used_bytes_in' => $first ? mt_rand(1, 3000) * 1048576 : 0, 'used_bytes_out' => $first ? mt_rand(1, 300) * 1048576 : 0,
                        'last_mac' => $first ? sprintf('02:00:%02X:%02X:%02X:%02X', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)) : null,
                        'last_router_id' => $first ? $pick($routers) : null, 'created_at' => $dt($created),
                    ];
                }
                DB::insertMany('cards', $rows);
            }
        }
        // بيع مباشر يومي في الشهر الأخير (لوحة التحكم وتقرير اليوم)
        $rows = [];
        for ($d = 30; $d >= 0; $d--) {
            foreach (array_slice($cats, 0, 3) as $cat) {
                $q = mt_rand(3, 18);
                $rows[] = ['type' => 'direct', 'category_id' => $cat['id'], 'quantity' => $q, 'unit_price' => $cat['price'], 'total' => $cat['price'] * $q, 'paid' => $cat['price'] * $q,
                    'employee_id' => $owner, 'created_at' => $dt(min($now - 600, $now - $d * 86400 + mt_rand(0, 40000)))];
            }
        }
        DB::insertMany('sales', $rows);
        DB::query("INSERT IGNORE INTO logins (login, owner_type, owner_id) SELECT username, 'card', id FROM cards");

        // ---------------------------------------------------------- باقات الاشتراك والمشتركون وحركاتهم
        $plans = [];
        foreach ([['4 ميجا', 50], ['8 ميجا', 80], ['16 ميجا', 120], ['25 ميجا', 160], ['منزلي اقتصادي', 35]] as $i => [$n, $p]) {
            $plans[] = ['id' => DB::insert('plans', ['name' => $n, 'service' => 'ppp', 'price' => $p, 'duration_days' => 30, 'rate_up' => '2M', 'rate_down' => '8M',
                'sort' => $i, 'created_at' => $dt($now - 400 * 86400)]), 'price' => $p];
        }
        $first = ['محمد', 'أحمد', 'محمود', 'خالد', 'يوسف', 'إبراهيم', 'عمر', 'علي', 'سامي', 'رامي', 'فاطمة', 'مريم', 'آية', 'هدى', 'سارة', 'نور', 'ياسمين'];
        $last = ['السقا', 'النجار', 'الحلو', 'المصري', 'الفرا', 'عاشور', 'الغول', 'شحادة', 'حلس', 'الريس', 'الشوا', 'قديح'];
        $rows = [];
        for ($i = 1; $i <= 360; $i++) {
            $plan = $plans[$i % count($plans)];
            $rows[] = [
                'full_name' => $pick($first) . ' ' . $pick($first) . ' ' . $pick($last), 'phone' => '059' . str_pad((string) (2100000 + $i * 37), 7, '0', STR_PAD_LEFT),
                'address' => 'شارع ' . mt_rand(1, 40), 'area_id' => $areas[$i % count($areas)], 'router_id' => $routers[$i % count($routers)], 'site_id' => $sites[$i % count($sites)],
                'lat' => 31.50 + mt_rand(0, 800) / 10000, 'lng' => 34.42 + mt_rand(0, 800) / 10000,
                'username' => sprintf('sub%04d', $i), 'password' => (string) mt_rand(100000, 999999), 'plan_id' => $plan['id'],
                'status' => $i % 29 === 0 ? 'suspended' : 'active', 'expires_at' => $dt($now + mt_rand(-25, 30) * 86400), 'balance' => mt_rand(-2, 1) * 10,
                'router_state' => 'enabled', 'last_online_at' => $dt($now - mt_rand(60, 10 * 86400)),
                'created_by' => $owner, 'created_at' => $dt($now - mt_rand(30, 380) * 86400),
            ];
        }
        DB::insertMany('subscribers', $rows);
        DB::query("INSERT IGNORE INTO logins (login, owner_type, owner_id) SELECT username, 'subscriber', id FROM subscribers");
        $priceOf = array_column($plans, 'price', 'id');
        $rows = [];
        foreach (DB::rows('SELECT id, plan_id FROM subscribers') as $s) {
            for ($m = 0; $m < 12; $m += 2) {
                $ts = $now - $m * 30 * 86400 - mt_rand(0, 25) * 86400;
                $rows[] = ['subscriber_id' => $s['id'], 'type' => 'renewal', 'amount' => $priceOf[$s['plan_id']] ?? 50, 'plan_id' => $s['plan_id'],
                    'period_from' => $dt($ts), 'period_to' => $dt($ts + 30 * 86400), 'method' => 'cash', 'employee_id' => $owner, 'created_at' => $dt($ts)];
            }
        }
        DB::insertMany('subscriber_transactions', $rows);

        // ---------------------------------------------------------- الجلسات (متصلون الآن وما قبلها) وسجل الدخول والتنبيهات
        $sample = DB::rows("SELECT id, username, last_mac FROM cards WHERE status IN ('active', 'expired') ORDER BY id DESC LIMIT 2400");
        $rows = [];
        foreach ($sample as $k => $c) {
            $open = $k < 260;
            $start = $open ? $now - mt_rand(60, 7200) : $now - mt_rand(3600, 20 * 86400);
            $rows[] = ['router_id' => $routers[$k % count($routers)], 'service' => 'hotspot', 'username' => $c['username'], 'card_id' => $c['id'],
                'mac' => $c['last_mac'] ?: sprintf('02:00:00:00:%02X:%02X', $k >> 8 & 255, $k & 255), 'ip' => '10.5.' . mt_rand(0, 255) . '.' . mt_rand(1, 254),
                'started_at' => $dt($start), 'updated_at' => $dt($open ? $now - 30 : $start + 3600), 'stopped_at' => $open ? null : $dt($start + mt_rand(300, 7200)),
                'uptime' => mt_rand(60, 7200), 'bytes_in' => mt_rand(1, 700) * 1048576, 'bytes_out' => mt_rand(1, 70) * 1048576,
                'rate_down' => $open ? mt_rand(1, 8000) * 1000 : null, 'rate_up' => $open ? mt_rand(1, 900) * 1000 : null];
        }
        DB::insertMany('sessions', $rows);
        $rows = [];
        foreach (array_slice($sample, 0, 1500) as $k => $c) {
            $rows[] = ['created_at' => $dt($now - mt_rand(60, 10 * 86400)), 'router_id' => $routers[$k % count($routers)], 'source' => 'portal', 'event' => $k % 5 ? 'login' : 'check',
                'login' => $c['username'], 'mac' => $c['last_mac'], 'ip' => '10.5.' . mt_rand(0, 255) . '.' . mt_rand(1, 254), 'result' => $k % 17 ? 'accept' : 'reject',
                'reason' => $k % 17 ? null : 'wrong_password', 'card_id' => $c['id']];
        }
        DB::insertMany('login_log', $rows);
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $down = $i % 3 > 0;
            $rows[] = ['type' => $down ? 'router_down' : 'router_up', 'level' => $down ? 'danger' : 'success', 'title' => ($down ? 'انقطع ' : 'عاد ') . 'جهاز MikroTik',
                'router_id' => $pick($routers), 'is_read' => $i > 6 ? 1 : 0, 'created_at' => $dt($now - $i * 5400)];
        }
        DB::insertMany('alerts', $rows);
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $st = $now - mt_rand(1, 60) * 86400;
            $rows[] = ['router_id' => $pick($routers), 'area_id' => null, 'title' => 'انقطاع برج', 'cause' => $pick(['power', 'fault', 'unknown']), 'source' => 'auto',
                'started_at' => $dt($st), 'ended_at' => $dt($st + mt_rand(600, 20000)), 'affected' => mt_rand(5, 90), 'created_at' => $dt($st)];
        }
        DB::insertMany('outages', $rows);
        $rows = [];
        for ($m = 0; $m < 12; $m++) {
            foreach ([['fuel', 300], ['provider', 900], ['rent', 200]] as [$cat, $amount]) {
                $ts = $now - $m * 30 * 86400 - mt_rand(0, 20) * 86400;
                $rows[] = ['category' => $cat, 'amount' => $amount + mt_rand(-50, 80), 'spent_on' => date('Y-m-d', $ts), 'created_by' => $owner, 'created_at' => $dt($ts)];
            }
        }
        DB::insertMany('expenses', $rows);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
