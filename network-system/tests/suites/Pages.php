<?php
declare(strict_types=1);

/*
 * كل صفحات النظام (GET) للمالك: لا خطأ خادم ولا نص خطأ PHP. والموظف لا يفتح ما ليس له.
 * المسارات تُقرأ من app/routes.php نفسه، فأي صفحة جديدة تُفحص تلقائياً.
 */

use Tests\Env;
use Tests\T;

/** مسارات GET من ملف المسارات مع تعويض المعرّفات بصفوف موجودة */
$routes = static function (Env $env, string $file, array $ids): array {
    $src = (string) file_get_contents(BASE_PATH . '/app/' . $file);
    preg_match_all("/->get\\(\\s*(?:\\\$v \\. )?'([^']+)'/", $src, $m);
    $skip = '#/(export|download|print|cron|logout|lang/|api/|hotspot/sdk|manifest|install|routers/setup|auth/ticket|jobs/\{|billing/(receipt|invoice)|support/\{|inbox/\{|cards/import/\{|telegram/(webhook|customer))|\.rsc|\{token|\{key|\{secret|/script|/status$|/data$|/live$|/poll$|/ports$|/count$|/recipients$|/statement$#';
    $out = [];
    foreach (array_unique($m[1]) as $path) {
        if (preg_match($skip, $path)) {
            continue;
        }
        $ok = true;
        $path = preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', static function ($x) use ($path, $ids, &$ok) {
            foreach ($ids as $prefix => $id) {
                if (str_starts_with($path, $prefix . '/') && $id) {
                    return (string) $id;
                }
            }
            $ok = false;
            return '0';
        }, $path);
        if ($ok) {
            $out[] = $path;
        }
    }
    sort($out);
    return $out;
};

$idsOf = static function (Env $env, string $db): array {
    $pdo = $env->db($db);
    $first = static fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
    return [
        '/routers' => $first('SELECT MIN(id) FROM routers'), '/subscribers' => $first('SELECT MIN(id) FROM subscribers'),
        '/cards/accounts' => 0, '/cards' => $first('SELECT MIN(id) FROM cards'), '/batches' => $first('SELECT MIN(id) FROM batches'),
        '/sales' => $first('SELECT MIN(id) FROM sales'), '/categories' => $first('SELECT MIN(id) FROM categories'),
        '/plans' => $first('SELECT MIN(id) FROM plans'), '/distributors' => $first('SELECT MIN(id) FROM distributors'),
        '/sites' => $first('SELECT MIN(id) FROM sites'), '/outages' => $first('SELECT MIN(id) FROM outages'),
        '/power' => $first('SELECT MIN(id) FROM routers'), '/check/card' => $first('SELECT MIN(id) FROM cards'),
        '/employees' => $first('SELECT MIN(id) FROM employees'), '/print/templates' => $first('SELECT MIN(id) FROM print_templates'),
    ];
};

return [
    'every network page opens for the owner' => static function (Env $env) use ($routes, $idsOf): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $bad = [];
        $n = 0;
        foreach ($routes($env, 'routes.php', $idsOf($env, $t['db'])) as $path) {
            $r = $h->get($t['path'] . $path);
            $n++;
            if ($r['status'] >= 500 || !in_array($r['status'], [200, 302, 303, 403, 404], true)) {
                $bad[] = $path . ' → ' . $r['status'];
                continue;
            }
            foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Stack trace'] as $e) {
                if (str_contains($r['body'], $e)) {
                    $bad[] = $path . ' shows "' . $e . '"';
                }
            }
            if ($r['status'] === 302 && str_contains($r['location'], '/login')) {
                $bad[] = $path . ' → login';
            }
        }
        T::ok($n > 60, 'expected many pages, got ' . $n);
        T::eq([], $bad, 'pages with problems');
    },
    'every admin page opens for the platform owner' => static function (Env $env) use ($routes): void {
        $h = $env->admin();
        $bad = [];
        $n = 0;
        foreach ($routes($env, 'routes_platform.php', []) as $path) {
            if (!str_starts_with($path, '/admin') || str_contains($path, 'login') || str_contains($path, 'logout')) {
                continue;
            }
            $r = $h->get($path);
            $n++;
            if ($r['status'] >= 500 || str_contains($r['body'], 'Fatal error') || str_contains($r['body'], 'Warning:')) {
                $bad[] = $path . ' → ' . $r['status'];
            }
        }
        T::ok($n > 15, 'expected many admin pages, got ' . $n);
        T::eq([], $bad, 'admin pages with problems');
    },
    'employee permissions are enforced on pages' => static function (Env $env): void {
        $t = $env->tenant('a');
        $env->employee('a', 'seller1', 'SellerPass123', ['dashboard.view', 'cards.view', 'sales.view', 'sales.create']);
        $h = $env->owner('a', ['seller1', 'SellerPass123']);
        T::status(200, $h->get($t['path'] . '/cards'));
        T::status(200, $h->get($t['path'] . '/sales'));
        foreach (['/subscribers', '/routers', '/settings', '/employees', '/reports', '/audit'] as $p) {
            T::status(403, $h->get($t['path'] . $p), 'seller must not open ' . $p);
        }
        // صلاحية الإجراء أيضاً، لا الصفحة فقط
        $r = $h->post($t['path'] . '/subscribers', ['full_name' => 'x']);
        T::status(403, $r, 'seller must not create subscribers');
    },
    'router scope hides other routers from an employee' => static function (Env $env): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $ids = array_map('intval', $pdo->query('SELECT id FROM routers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        $env->employee('a', 'tech1', 'TechPass1234', ['dashboard.view', 'routers.view'], [$ids[0]]);
        $h = $env->owner('a', ['tech1', 'TechPass1234']);
        T::status(200, $h->get($t['path'] . '/routers/' . $ids[0]));
        T::status([403, 404], $h->get($t['path'] . '/routers/' . $ids[1]), 'router outside scope');
    },
];
