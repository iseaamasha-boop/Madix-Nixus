<?php
declare(strict_types=1);

namespace Tests;

use PDO;

/**
 * بيئة اختبار مؤقتة ومعزولة:
 *   قاعدة منصة وقواعد حسابات بأسماء عشوائية (mnxtest_*)، ملف إعداد مؤقت، وخادم PHP المدمج على منفذ حر.
 * بيانات الاتصال بقاعدة البيانات من المتغيرات MNX_TEST_DB_HOST/PORT/USER/PASS (افتراضياً root عبر المقبس).
 * كل شيء يُحذف في النهاية.
 */
final class Env
{
    public string $run;
    public string $dir;
    public string $config;
    public string $base = '';
    public int $port = 0;
    public string $platformDb;
    public string $prefix;
    public array $tenants = [];
    /** @var resource|null */
    private $server = null;
    private PDO $root;
    private string $user = 'mnxtest';
    private string $pass;

    public const ADMIN = ['admin', 'AdminPass123'];
    public const OWNER = ['owner', 'OwnerPass123'];

    public function __construct()
    {
        $this->run = bin2hex(random_bytes(3));
        $this->dir = sys_get_temp_dir() . '/mnxtest-' . $this->run;
        @mkdir($this->dir, 0700, true);
        $this->platformDb = 'mnxtest_' . $this->run . '_platform';
        $this->prefix = 'mnxtest_' . $this->run . '_';
        $this->pass = bin2hex(random_bytes(12));
        $this->root = self::rootPdo();
        $this->root->exec("CREATE USER IF NOT EXISTS '{$this->user}'@'localhost' IDENTIFIED BY '{$this->pass}'");
        $this->root->exec("ALTER USER '{$this->user}'@'localhost' IDENTIFIED BY '{$this->pass}'");
        $this->root->exec("CREATE USER IF NOT EXISTS '{$this->user}'@'127.0.0.1' IDENTIFIED BY '{$this->pass}'");
        $this->root->exec("ALTER USER '{$this->user}'@'127.0.0.1' IDENTIFIED BY '{$this->pass}'");
        foreach (['localhost', '127.0.0.1'] as $h) {
            $this->root->exec("GRANT ALL PRIVILEGES ON `mnxtest\\_%`.* TO '{$this->user}'@'$h'");
        }
        $this->root->exec('FLUSH PRIVILEGES');
        $this->root->exec('CREATE DATABASE `' . $this->platformDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public static function rootPdo(): PDO
    {
        $host = getenv('MNX_TEST_DB_HOST') ?: '';
        $user = getenv('MNX_TEST_DB_USER') ?: 'root';
        $pass = getenv('MNX_TEST_DB_PASS') ?: '';
        if ($host === '') {
            foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $sock) {
                if (file_exists($sock)) {
                    return new PDO('mysql:unix_socket=' . $sock, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                }
            }
            $host = '127.0.0.1';
        }
        return new PDO('mysql:host=' . $host . ';port=' . (getenv('MNX_TEST_DB_PORT') ?: '3306'), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** ملف إعداد المنصة المؤقت ثم تهيئتها وبدء الخادم */
    public function start(): void
    {
        $this->port = self::freePort();
        $this->base = 'http://127.0.0.1:' . $this->port;
        $host = getenv('MNX_TEST_DB_HOST') ?: 'localhost';
        $cfg = [
            'app' => ['url' => $this->base, 'key' => 'base64:' . base64_encode(random_bytes(32)), 'debug' => false, 'timezone' => 'Asia/Gaza', 'cron_key' => bin2hex(random_bytes(20))],
            'db' => ['host' => $host, 'port' => 3306, 'name' => $this->platformDb, 'user' => $this->user, 'pass' => $this->pass, 'charset' => 'utf8mb4'],
            'platform' => ['enabled' => true, 'tenant_db' => ['host' => $host, 'port' => 3306, 'user' => $this->user, 'pass' => $this->pass, 'prefix' => $this->prefix]],
        ];
        $this->config = $this->dir . '/config.php';
        file_put_contents($this->config, "<?php\nreturn " . var_export($cfg, true) . ";\n");
        $this->cli('cli/platform.php', ['setup', '--name=Admin', '--username=' . self::ADMIN[0], '--password=' . self::ADMIN[1]]);
        $this->startServer();
    }

    public function startServer(): void
    {
        $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $this->port, '-t', BASE_PATH . '/public', __DIR__ . '/router.php'];
        $env = ['SN_CONFIG' => $this->config, 'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'TZ' => 'Asia/Gaza'];
        $this->server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']], $pipes, BASE_PATH, $env);
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', $this->port, $e, $es, 0.2);
            if ($s) {
                fclose($s);
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('test server did not start: ' . @file_get_contents($this->dir . '/server.log'));
    }

    public function stopServer(): void
    {
        if (is_resource($this->server)) {
            $st = proc_get_status($this->server);
            // الخادم المدمج مع العمّال: إنهاء مجموعة العمليات كلها
            if (!empty($st['pid'])) {
                exec('pkill -TERM -P ' . (int) $st['pid'] . ' 2>/dev/null');
                @posix_kill((int) $st['pid'], 15);
            }
            proc_close($this->server);
            $this->server = null;
        }
    }

    /** تشغيل أمر سطر أوامر من النظام بإعداد بيئة الاختبار */
    public function cli(string $script, array $args = []): string
    {
        $cmd = 'SN_CONFIG=' . escapeshellarg($this->config) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/' . $script);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException("cli $script failed ($code): " . implode("\n", $out));
        }
        return implode("\n", $out);
    }

    /** إنشاء حساب شبكة مع بيانات تجريبية صغيرة */
    public function tenant(string $key, int $subscribers = 40, int $cards = 400): array
    {
        if (isset($this->tenants[$key])) {
            return $this->tenants[$key];
        }
        $slug = 't' . $key . '-' . $this->run;
        $this->cli('tests/seed-demo.php', ['--slug=' . $slug, '--create', '--subscribers=' . $subscribers, '--cards=' . $cards, '--user=' . self::OWNER[0], '--password=' . self::OWNER[1], '--name=شبكة ' . $key]);
        $db = $this->prefix . str_replace('-', '_', $slug);
        $this->tenants[$key] = ['slug' => $slug, 'db' => $db, 'path' => '/' . $slug];
        return $this->tenants[$key];
    }

    /** اتصال مباشر بقاعدة (للتحقق من النتائج) */
    public function db(?string $name = null): PDO
    {
        $pdo = self::rootPdo();
        $pdo->exec('USE `' . ($name ?? $this->platformDb) . '`');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    public function http(): Http
    {
        return new Http($this->base);
    }

    /** متصفح داخل حساب شبكة بمالكها (أو بمستخدم آخر) */
    public function owner(string $key = 'a', ?array $cred = null): Http
    {
        $t = $this->tenant($key);
        $h = $this->http();
        $cred ??= self::OWNER;
        $r = $h->login($t['path'] . '/login', $cred[0], $cred[1]);
        T::status(302, $r, 'owner login');
        T::ok(!str_contains($r['location'], '/login'), 'owner login redirected back to login');
        return $h;
    }

    /** موظف بصلاحيات محددة (وأجهزة MikroTik محددة إن أُعطيت) */
    public function employee(string $key, string $username, string $password, array $perms, ?array $routerIds = null): int
    {
        $t = $this->tenant($key);
        $pdo = $this->db($t['db']);
        $pdo->prepare('DELETE FROM employees WHERE username = ?')->execute([$username]);
        $pdo->prepare("INSERT INTO employees (name, username, password_hash, is_owner, role_preset, router_scope, status, theme, created_at) VALUES (?, ?, ?, 0, 'custom', ?, 'active', 'light', NOW())")
            ->execute(['موظف ' . $username, $username, password_hash($password, PASSWORD_DEFAULT), $routerIds === null ? 'all' : 'selected']);
        $id = (int) $pdo->lastInsertId();
        $st = $pdo->prepare('INSERT INTO employee_permissions (employee_id, permission) VALUES (?, ?)');
        foreach ($perms as $p) {
            $st->execute([$id, $p]);
        }
        foreach ((array) $routerIds as $r) {
            $pdo->prepare('INSERT INTO employee_routers (employee_id, router_id) VALUES (?, ?)')->execute([$id, $r]);
        }
        return $id;
    }

    /** متصفح داخل لوحة المسؤول */
    public function admin(): Http
    {
        $h = $this->http();
        $r = $h->login('/admin/login', self::ADMIN[0], self::ADMIN[1]);
        T::status(302, $r, 'admin login');
        T::ok(!str_contains($r['location'], '/login'), 'admin login redirected back to login');
        return $h;
    }

    public function serverLog(): string
    {
        return (string) @file_get_contents($this->dir . '/server.log');
    }

    public function teardown(): void
    {
        $this->stopServer();
        try {
            foreach ($this->root->query("SHOW DATABASES LIKE 'mnxtest\\_{$this->run}\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $db) {
                $this->root->exec('DROP DATABASE `' . $db . '`');
            }
        } catch (\Throwable) {
        }
        foreach ($this->tenants as $t) {
            foreach ([BASE_PATH . '/storage/tenants/' . $t['slug'], BASE_PATH . '/public/uploads/t/' . $t['slug']] as $d) {
                if (is_dir($d)) {
                    exec('rm -rf ' . escapeshellarg($d));
                }
            }
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }
}
