<?php
declare(strict_types=1);

/*
 * المرحلة 4 (لوحة التحكم والشكل).
 * البند 90: صور رمزية ملونة بحرفين ولون ثابت من رقم السجل، بلا خدمات خارجية، في القوائم وبطاقة المعاينة وشريط المستخدم.
 */

use Tests\Env;
use Tests\T;

return [
    'avatar initials: two letters, Arabic kept apart, no diacritics' => static function (Env $env): void {
        T::eq("ع\u{200C}خ", avatar_initials('عمر خالد عاشور'), 'first letters of the first two words');
        T::eq("م\u{200C}ح", avatar_initials('محمد'), 'one word: its first two letters');
        T::eq("أ\u{200C}ح", avatar_initials('أَحمد'), 'diacritics removed');
        T::eq("ع\u{200C}س", avatar_initials('ـعلي سالم'), 'tatweel removed');
        T::eq('AA', avatar_initials('ahmed ali'), 'latin upper case');
        T::eq('S', avatar_initials('s'), 'single letter');
        T::eq('؟', avatar_initials('   '), 'empty name');
    },
    'avatar colour is stable and spread out' => static function (Env $env): void {
        T::eq(avatar_hue(42), avatar_hue(42), 'same id, same colour');
        T::eq(avatar_hue('42'), avatar_hue(42), 'numeric string is the id');
        $hues = array_map('avatar_hue', range(1, 12));
        T::eq(12, count(array_unique($hues)), 'neighbouring ids get different colours');
        foreach ($hues as $h) {
            T::ok($h >= 0 && $h < 360, 'hue in range');
        }
        $html = avatar('سارة <b>', 7, 'sm', '<i class="status-dot"></i>');
        T::contains('class="avatar av sm" style="--h:' . avatar_hue(7) . '" aria-hidden="true"', $html, 'markup');
        T::ok(!str_contains($html, '<b>'), 'name is escaped');
    },
    'avatar fallback colours keep 4.5 contrast on every hue' => static function (Env $env): void {
        // ألوان hsl البديلة (المتصفحات التي لا تدعم oklch): نص داكن على فاتح، وفاتح على داكن في الوضع الداكن
        $css = (string) file_get_contents(PUBLIC_PATH . '/assets/css/app.css');
        T::ok(preg_match('/\.av \{ --av-bg: hsl\(var\(--h\) (\d+)% (\d+)%\); --av-fg: hsl\(var\(--h\) (\d+)% (\d+)%\); \}/', $css, $l) === 1, 'light fallback found');
        T::ok(preg_match('/\[data-theme="dark"\] \.av \{ --av-bg: hsl\(var\(--h\) (\d+)% (\d+)%\); --av-fg: hsl\(var\(--h\) (\d+)% (\d+)%\); \}/', $css, $d) === 1, 'dark fallback found');
        $rgb = static function (float $h, float $s, float $l): array {
            $s /= 100; $l /= 100;
            $k = static fn ($n) => fmod($n + $h / 30, 12);
            $a = $s * min($l, 1 - $l);
            $f = static fn ($n) => $l - $a * max(-1, min($k($n) - 3, 9 - $k($n), 1));
            return [$f(0), $f(8), $f(4)];
        };
        $lum = static function (array $c): float {
            $c = array_map(static fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        foreach (['light' => $l, 'dark' => $d] as $mode => $m) {
            $worst = 99.0;
            for ($h = 0; $h < 360; $h++) {
                $a = $lum($rgb($h, (float) $m[1], (float) $m[2]));
                $b = $lum($rgb($h, (float) $m[3], (float) $m[4]));
                $worst = min($worst, (max($a, $b) + 0.05) / (min($a, $b) + 0.05));
            }
            T::ok($worst >= 4.5, $mode . ' fallback contrast ' . round($worst, 2));
        }
    },
    'avatars on subscribers, employees, preview card and the user chip' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $pdo = $env->db($t['db']);
        $id = (int) $pdo->query('SELECT MIN(id) FROM subscribers')->fetchColumn();
        $json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
        $list = $h->request('GET', $t['path'] . '/subscribers');
        T::contains('class="avatar av sm"', $list['body'], 'subscriber rows');
        T::contains('class="status-dot', $list['body'], 'online dot kept');
        T::contains('class="avatar av lg"', $h->request('GET', $t['path'] . '/subscribers/' . $id)['body'], 'subscriber page');
        T::contains('class="avatar av"', $h->request('GET', $t['path'] . '/employees')['body'], 'employees and user chip');
        $p = $h->request('GET', $t['path'] . '/subscribers/' . $id . '/preview', null, $json);
        T::ok(is_array($p['json']['av'] ?? null) && count($p['json']['av']) === 2 && is_int($p['json']['av'][1]), 'preview card has initials and colour');
        T::eq(avatar_hue($id), $p['json']['av'][1], 'same colour as the list');
    },
    'black OLED mode: chosen per employee, offered by the design studio, dark when turned off' => static function (Env $env): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
        $owner = static fn () => $pdo->query('SELECT theme FROM employees WHERE is_owner = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $page = $h->request('GET', $t['path'] . '/');
        T::contains('data-theme-set="black"', $page['body'], 'third option in the theme menu');
        T::status(200, $h->post($t['path'] . '/account/theme', ['theme' => 'black'], $json));
        T::eq('black', $owner(), 'stored for the employee');
        $page = $h->request('GET', $t['path'] . '/');
        T::contains('data-theme-pref="black"', $page['body'], 'page opens in black');
        T::contains('<option value="black" selected', $h->request('GET', $t['path'] . '/account')['body'], 'account page shows it');
        T::status(422, $h->post($t['path'] . '/account/theme', ['theme' => 'purple'], $json), 'unknown value refused');
        T::eq('black', $owner(), 'unchanged after a refused value');
        // استوديو التصميم يطفئ الخيار: الصفحة داكنة ولا زر أسود، واختيار الموظف محفوظ كما هو
        $a = $env->admin();
        $a->get('/admin/design');
        T::status(302, $a->post('/admin/design', ['oled' => '0']), 'design saved');
        $page = $h->request('GET', $t['path'] . '/');
        T::contains('data-theme-pref="dark"', $page['body'], 'falls back to dark');
        T::contains('data-oled="0"', $page['body'], 'pages know it is off');
        T::notContains('data-theme-set="black"', $page['body'], 'no black option');
        T::eq('black', $owner(), 'employee choice kept');
        $m = json_decode((string) $env->http()->request('GET', $t['path'] . '/api/app/v1/info')['body'], true);
        T::ok(isset($m['network']['oled']) && $m['network']['oled'] === false, 'app API: new field');
        foreach (['name', 'logo', 'currency', 'phone', 'primary', 'accent'] as $k) {
            T::ok(array_key_exists($k, $m['network'] ?? []), 'app API keeps ' . $k);
        }
        T::status(302, $a->post('/admin/design', ['oled' => '1']), 'design restored');
        $page = $h->request('GET', $t['path'] . '/');
        T::contains('data-theme-pref="black"', $page['body'], 'black again');
        T::notContains('data-oled=', $page['body'], 'nothing extra on the page when available');
        T::status(200, $h->post($t['path'] . '/account/theme', ['theme' => 'light'], $json));
    },
    'live chart: last hour of real readings, MikroTik scope respected (45)' => static function (Env $env): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $routers = array_map('intval', $pdo->query('SELECT id FROM routers WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        T::eq(2, count($routers), 'two MikroTik devices');
        // قراءة لكل جهاز قبل 10 دقائق (داخل الساعة) وقراءة قبل ساعتين (خارجها)
        $start = intdiv(time() - 600, 300) * 300;
        $at = date('Y-m-d H:i:s', $start + 10);
        // النافذة نفسها (5 دقائق) لهذا الاختبار وحده: قراءات معروفة فقط
        $pdo->prepare('DELETE FROM router_metrics WHERE created_at >= ? AND created_at < ?')->execute([date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start + 300)]);
        $ins = $pdo->prepare('INSERT INTO router_metrics (router_id, created_at, online, active_hotspot, active_ppp, rx_bps, tx_bps) VALUES (?, ?, 1, ?, ?, ?, ?)');
        $ins->execute([$routers[0], $at, 30, 5, 4000000, 1000000]);
        $ins->execute([$routers[1], $at, 20, 0, 6000000, 3000000]);
        $ins->execute([$routers[0], date('Y-m-d H:i:s', time() - 7200), 999, 0, 1, 1]);
        $env->cli('cli/migrate.php');
        $seed = static function (string $body): array {
            T::ok(preg_match('/data-live-chart="(\[[0-9,\[\]-]*\])"/', $body, $m) === 1, 'readings in the live chart');
            return json_decode($m[1], true);
        };
        // الصيغة المختصرة: [دقائق مضت، المتصلون، تنزيل kbps، رفع kbps]، وآخر صف القراءة الحالية (0 دقيقة)
        $ago = (int) round((time() - $start) / 60);
        $bucket = static fn (array $rows) => array_values(array_filter(array_slice($rows, 0, -1), static fn ($r) => abs($r[0] - $ago) <= 1));
        $page = $env->owner('a')->request('GET', $t['path'] . '/');
        T::contains('id="live-chart"', $page['body'], 'live chart on the dashboard');
        T::contains('data-range-tab', $page['body'], 'live and 24 hours tabs');
        $rows = $seed($page['body']);
        T::eq(0, end($rows)[0], 'current reading last');
        T::ok(!in_array(999, array_column($rows, 1), true), 'nothing older than an hour');
        T::ok(max(array_column($rows, 0)) <= 61, 'within the last hour');
        $b = $bucket($rows);
        T::eq(1, count($b), 'one point for the 5 minutes');
        T::eq([55, 10000, 4000], array_slice($b[0], 1), 'sum of all MikroTik devices: users, download, upload (kbps)');
        $env->employee('a', 'live45', 'LivePass12345', ['dashboard.view'], [$routers[1]]);
        $e = $env->owner('a', ['live45', 'LivePass12345']);
        $b = $bucket($seed($e->request('GET', $t['path'] . '/')['body']));
        T::eq([20, 6000, 3000], array_slice($b[0] ?? [0, 0, 0, 0], 1), 'scoped employee sees only own MikroTik device');
    },
    'migration 19 adds the black value once' => static function (Env $env): void {
        $t = $env->tenant('a');
        $type = (string) $env->db($t['db'])->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'theme'")->fetchColumn();
        T::eq("enum('system','light','dark','black')", $type, 'theme values');
    },
];
