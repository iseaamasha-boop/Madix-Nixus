<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\DB;
use App\Core\Installer;
use App\Core\Migrator;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\Throttle;
use PDO;
use RuntimeException;

/**
 * البند 105: الشبكة التجريبية («جرّب الآن»).
 * شبكة منفصلة تماماً بقاعدة بيانات خاصة (حساب في المنصة مُعلَّم is_demo)، ببيانات وهمية تُعاد إلى أصلها كل ساعة.
 * الزائر يدخلها بلا تسجيل من /<اسمها>/start. ممنوع فيها: الرسائل (SMS، تيليجرام، البريد، واتساب)، الذكاء الاصطناعي،
 * الاتصال بأجهزة MikroTik، كلمة المرور والتحقق بخطوتين والمستخدمون، ولوحة المسؤول (لا جلسة مسؤول فيها أصلاً).
 * عليها حد للطلبات، وnoindex، وعلامة «تجريبي». المسؤول يشغّلها ويطفئها من لوحته، ويرى زوارها (البند 105+).
 */
final class Demo
{
    /** أسماء مقترحة للشبكة بالترتيب (أول اسم متاح) */
    private const SLUGS = ['try', 'try-now', 'try-madix', 'demo-net'];
    public const RESET_EVERY = 3600;
    /** مسارات الكتابة الممنوعة (بادئة المسار بعد اسم الشبكة) */
    private const BLOCKED = [
        '/account/2fa', '/account/devices', '/employees', '/routers', '/tunnels', '/server', '/cables', '/hotspot/deploy',
        '/sessions', '/devices', '/usage/cap', '/usage/uncap', '/sms', '/telegram', '/inbox', '/assistant', '/subscribers/import-smart',
        '/settings/backup', '/billing', '/support', '/networks',
    ];
    /** مسارات كتابة ممنوعة بالضبط (POST /account يغيّر كلمة المرور) */
    private const BLOCKED_EXACT = ['/account'];

    private static ?array $tenant = null;
    private static bool $loaded = false;

    public static function enabled(): bool
    {
        return Platform::setting('demo.enabled', '0') === '1' && self::tenant() !== null;
    }

    /** حساب الشبكة التجريبية في المنصة (أو null) */
    public static function tenant(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            self::$tenant = Platform::row('SELECT * FROM tenants WHERE is_demo = 1 ORDER BY id LIMIT 1') ?: null;
        }
        return self::$tenant;
    }

    public static function forget(): void
    {
        self::$loaded = false;
        self::$tenant = null;
    }

    /** هل الطلب الحالي داخل الشبكة التجريبية؟ (رخيص: من صف الحساب الحالي) */
    public static function active(): bool
    {
        $t = Tenant::current();
        return $t !== null && (int) ($t['is_demo'] ?? 0) === 1;
    }

    public static function url(): string
    {
        $t = self::tenant();
        return $t ? url('/' . $t['slug'] . '/start') : url('/');
    }

    /** رابط «جرّب الآن» لصفحة الهبوط من الإعدادات المحمّلة أصلاً (بلا استعلام إضافي)، أو null إن كانت موقوفة */
    public static function landingUrl(): ?string
    {
        $slug = Platform::setting('demo.slug', '');
        return Platform::setting('demo.enabled', '0') === '1' && $slug !== '' ? url('/' . $slug . '/start') : null;
    }

    public const MESSAGE = 'غير متاح في الشبكة التجريبية.';

    // ------------------------------------------------------------------ التشغيل والإيقاف وإعادة التجهيز

    /** تشغيل الشبكة: تُنشأ وتُجهَّز أول مرة، ثم تُفتح للزوار */
    public static function enable(): array
    {
        $t = self::tenant();
        if ($t === null) {
            $slug = null;
            foreach (self::SLUGS as $s) {
                if (TenantProvisioner::slugError($s) === null) {
                    $slug = $s;
                    break;
                }
            }
            if ($slug === null) {
                throw new RuntimeException('تعذّر اختيار اسم للشبكة التجريبية.');
            }
            $t = TenantProvisioner::create([
                'slug' => $slug, 'name' => 'شبكة تجريبية', 'owner_name' => 'زائر التجربة', 'phone' => null, 'email' => null,
                'username' => 'demo', 'password' => bin2hex(random_bytes(16)), 'status' => 'active',
            ]);
            Platform::exec('UPDATE tenants SET is_demo = 1, ai_enabled = 0, paid_until = ?, activated_at = COALESCE(activated_at, ?) WHERE id = ?',
                ['2099-12-31 00:00:00', date('Y-m-d H:i:s'), $t['id']]);
            self::forget();
            $t = self::tenant();
            self::build($t);
        }
        Platform::exec("UPDATE tenants SET status = 'active', status_reason = NULL, paid_until = ? WHERE id = ?", ['2099-12-31 00:00:00', $t['id']]);
        Platform::setSettings(['demo.enabled' => '1', 'demo.slug' => $t['slug']]);
        self::forget();
        return self::tenant();
    }

    /** إيقاف الشبكة: لا يدخلها أحد، وتبقى بياناتها حتى التشغيل التالي */
    public static function disable(): void
    {
        $t = self::tenant();
        Platform::setSettings(['demo.enabled' => '0']);
        if ($t) {
            Platform::exec("UPDATE tenants SET status = 'suspended', status_reason = ? WHERE id = ?", ['الشبكة التجريبية موقوفة', $t['id']]);
        }
        self::forget();
    }

    /** إعادة الشبكة إلى أصلها: جداول جديدة كما في حساب جديد ثم بيانات التجربة بأوقات اليوم */
    public static function build(array $t): void
    {
        if ((int) ($t['is_demo'] ?? 0) !== 1) {
            throw new RuntimeException('ليست الشبكة التجريبية.');
        }
        Platform::setSettings(['demo.building_until' => (string) (time() + 120)]);
        $inside = Tenant::current();
        try {
            $pdo = Tenant::pdo($t);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            Installer::migrate($pdo);
            $now = date('Y-m-d H:i:s');
            $settings = [
                'general.network_name' => 'شبكة تجريبية',
                'general.panel_url'    => Platform::rootUrl() . '/' . $t['slug'],
                'general.timezone'     => Platform::setting('platform.timezone'),
                'app.schema_version'   => (string) Migrator::VERSION,
                'backup.auto'          => '0',
            ];
            $st = $pdo->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            foreach ($settings as $k => $v) {
                $st->execute([$k, $v, $now]);
            }
            $pdo->prepare("INSERT INTO employees (name, username, password_hash, is_owner, role_preset, router_scope, status, theme, created_at) VALUES (?, 'demo', ?, 1, 'manager', 'all', 'active', 'light', ?)")
                ->execute(['زائر التجربة', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $now]);
            Tenant::enter($t);
            DemoSeed::run();
            Cache::flushAll();
        } finally {
            Tenant::leave();
            if ($inside) {
                Tenant::enter($inside);
            }
            Platform::setSettings(['demo.building_until' => '0', 'demo.reset_at' => date('Y-m-d H:i:s')]);
        }
        // ملفات رفعها الزوار (الشعار، صور الإعلانات...) وأي نسخ احتياطية
        foreach ([STORAGE_PATH . '/tenants/' . $t['slug'] . '/uploads', STORAGE_PATH . '/tenants/' . $t['slug'] . '/backups', PUBLIC_PATH . '/uploads/t/' . $t['slug']] as $dir) {
            self::clearDir($dir);
        }
    }

    public static function building(): bool
    {
        return (int) Platform::setting('demo.building_until', '0') > time();
    }

    /** من المهمة المجدولة للمنصة: إعادة التجهيز كل ساعة، وتنظيف زيارات أقدم من 180 يوماً */
    public static function cron(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        Platform::exec('DELETE FROM demo_visits WHERE started_at < ?', [date('Y-m-d H:i:s', time() - 180 * 86400)]);
        $last = strtotime(Platform::setting('demo.reset_at', '1970-01-01 00:00:00')) ?: 0;
        if (time() - $last < self::RESET_EVERY) {
            return null;
        }
        self::build(self::tenant());
        return 'reset';
    }

    private static function clearDir(string $dir): void
    {
        if (!is_dir($dir) || (!str_contains($dir, '/tenants/') && !str_contains($dir, '/uploads/t/'))) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }

    // ------------------------------------------------------------------ الحماية في كل طلب داخل الشبكة التجريبية

    /**
     * يُستدعى من Kernel قبل توجيه الطلب في الشبكة التجريبية: رد بديل، أو null ليكمل الطلب.
     */
    public static function guard(Request $request): ?Response
    {
        $json = $request->wantsJson() || str_starts_with($request->path, '/api/');
        $ip = $request->ip();
        $write = !in_array($request->method, ['GET', 'HEAD'], true);
        if (!Throttle::rate('demo:' . $ip, 300, 60) || ($write && !Throttle::rate('demo-w:' . $ip, 60, 60))) {
            return $json
                ? Response::json(['ok' => false, 'message' => 'طلبات كثيرة.'], 429)
                : new Response(self::page('طلبات كثيرة', 30), 429, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '30']);
        }
        // صفحة الدخول تُدخل الزائر مباشرة، والخروج يعيده إلى صفحة المنصة
        if ($request->method === 'GET' && $request->path === '/login') {
            return Response::redirect(url('/start'));
        }
        if ($write && $request->path === '/logout') {
            Auth::logout();
            return Response::redirect(root_path() . '/');
        }
        if ($write && self::blocked($request->path)) {
            if ($json) {
                return Response::json(['ok' => false, 'message' => self::MESSAGE], 403);
            }
            flash('error', self::MESSAGE);
            return back('/');
        }
        return null;
    }

    /** رد أثناء إعادة التجهيز (قبل أي استعلام على قاعدة الشبكة) */
    public static function buildingResponse(Request $request): Response
    {
        if ($request->wantsJson() || str_starts_with($request->path, '/api/')) {
            return Response::json(['ok' => false, 'message' => 'تُجهَّز الشبكة التجريبية الآن.'], 503, ['Retry-After' => '5']);
        }
        return new Response(self::page('تُجهَّز الشبكة التجريبية الآن', 5), 503, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '5']);
    }

    public static function blocked(string $path): bool
    {
        if (in_array($path, self::BLOCKED_EXACT, true)) {
            return true;
        }
        foreach (self::BLOCKED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /** صفحة حالة بسيطة (بلا أنماط النظام) تُحدَّث تلقائياً */
    private static function page(string $title, int $refresh): string
    {
        return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><meta http-equiv="refresh" content="' . $refresh . '"><title>' . e($title) . '</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:#0b1124;color:#e6eaf6}'
            . 'p{font-size:18px;display:flex;align-items:center;gap:12px}i{width:14px;height:14px;border-radius:50%;background:#60a5fa;animation:b 1s ease-in-out infinite alternate}'
            . '@keyframes b{to{opacity:.3;transform:scale(.7)}}@media (prefers-reduced-motion:reduce){i{animation:none}}</style></head>'
            . '<body><p><i></i>' . e($title) . '</p></body></html>';
    }

    // ------------------------------------------------------------------ الزوار (البند 105+)

    public const COOKIE_VISITOR = 'mnx_dvid';
    public const COOKIE_VISIT = 'mnx_dv';

    /** بدء زيارة (أو متابعة زيارة مفتوحة خلال آخر 30 دقيقة) وإرجاع رقمها */
    public static function startVisit(Request $request): int
    {
        $visitor = (string) ($_COOKIE[self::COOKIE_VISITOR] ?? '');
        if (!preg_match('/^[a-f0-9]{24}$/', $visitor)) {
            $visitor = bin2hex(random_bytes(12));
        }
        $open = self::visitId();
        if ($open !== null) {
            $row = Platform::row('SELECT id, visitor, last_at FROM demo_visits WHERE id = ?', [$open]);
            if ($row && $row['visitor'] === $visitor && strtotime($row['last_at']) > time() - 1800) {
                self::cookies($visitor, (int) $row['id'], $request);
                return (int) $row['id'];
            }
        }
        [$device, $browser, $os] = self::agent($request->userAgent());
        $now = date('Y-m-d H:i:s');
        Platform::exec(
            'INSERT INTO demo_visits (visitor, started_at, last_at, pages, device, browser, os, country, source, lang) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
            [$visitor, $now, $now, $device, $browser, $os, \App\Support\IpCountry::of($request->ip()), self::source($request), \App\Core\Lang::isEn() ? 'en' : 'ar']
        );
        $id = (int) Platform::pdo()->lastInsertId();
        self::cookies($visitor, $id, $request);
        return $id;
    }

    /** رقم الزيارة من ملف التعريف الموقّع (لا يمكن تزويره لزيارة زائر آخر) */
    public static function visitId(): ?int
    {
        $raw = (string) ($_COOKIE[self::COOKIE_VISIT] ?? '');
        if (!preg_match('/^(\d{1,10})\.([a-f0-9]{32})$/', $raw, $m)) {
            return null;
        }
        return hash_equals(self::sign((int) $m[1]), $m[2]) ? (int) $m[1] : null;
    }

    private static function sign(int $id): string
    {
        return substr(hash_hmac('sha256', 'demo-visit:' . $id, (string) \App\Core\Config::get('app.key', 'madix')), 0, 32);
    }

    private static function cookies(string $visitor, int $visit, Request $request): void
    {
        $base = ['path' => root_path() . '/', 'secure' => $request->isHttps(), 'httponly' => true, 'samesite' => 'Lax'];
        setcookie(self::COOKIE_VISITOR, $visitor, $base + ['expires' => time() + 365 * 86400]);
        setcookie(self::COOKIE_VISIT, $visit . '.' . self::sign($visit), $base + ['expires' => 0]);
        $_COOKIE[self::COOKIE_VISIT] = $visit . '.' . self::sign($visit);
    }

    /** صفحة زارها الزائر (بعد إرسال الرد، فلا تؤخر الصفحة) */
    public static function track(Request $request, Response $response): void
    {
        if ($request->method !== 'GET' || $response->status !== 200 || $request->wantsJson()
            || !str_starts_with((string) ($response->headers['Content-Type'] ?? ''), 'text/html') || ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') !== '') {
            return;
        }
        $visit = self::visitId();
        if ($visit === null) {
            return;
        }
        $title = preg_match('#<title>(.*?)</title>#s', $response->body, $m) ? html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8') : null;
        $path = mb_substr($request->path, 0, 190);
        // وقت فتح الصفحة يُؤخذ الآن (بالملّي ثانية)؛ الكتابة بعد إرسال الرد قد تتأخر عن صفحة تالية أسرع
        $at = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $when = $at->format('Y-m-d H:i:s.v');
        \App\Core\Kernel::afterResponse(static function () use ($visit, $path, $title, $when): void {
            Platform::exec('INSERT INTO demo_pageviews (visit_id, path, title, created_at) SELECT id, ?, ?, ? FROM demo_visits WHERE id = ?',
                [$path, $title !== null ? mb_substr(trim($title), 0, 120) : null, $when, $visit]);
            Platform::exec('UPDATE demo_visits SET pages = pages + 1, last_at = GREATEST(last_at, ?) WHERE id = ?', [substr($when, 0, 19), $visit]);
        });
    }

    /** المصدر: ?src= أو utm_source، وإلا اسم الموقع الذي جاء منه (غير صفحة المنصة نفسها) */
    private static function source(Request $request): ?string
    {
        foreach (['src', 'utm_source'] as $k) {
            $v = trim($request->str($k));
            if ($v !== '') {
                return mb_substr(preg_replace('/[^\p{L}\p{N} ._-]/u', '', $v), 0, 120) ?: null;
            }
        }
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        $own = strtolower((string) parse_url(Platform::rootUrl(), PHP_URL_HOST));
        if ($host === '' || $host === $own) {
            return $host === $own && $host !== '' ? 'landing' : null;
        }
        return mb_substr(preg_replace('/^www\./', '', $host), 0, 120);
    }

    /** الجهاز والمتصفح والنظام من User-Agent (بلا مكتبات) */
    public static function agent(string $ua): array
    {
        $os = match (true) {
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
            (bool) preg_match('/Windows/i', $ua) => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/CrOS/i', $ua) => 'ChromeOS',
            (bool) preg_match('/Linux/i', $ua) => 'Linux',
            default => null,
        };
        $browser = match (true) {
            (bool) preg_match('/Edg\//i', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
            (bool) preg_match('/Firefox\/|FxiOS/i', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS/i', $ua) => 'Chrome',
            (bool) preg_match('/Safari\//i', $ua) => 'Safari',
            default => null,
        };
        $device = match (true) {
            (bool) preg_match('/iPad|Tablet|(Android(?!.*Mobile))/i', $ua) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android/i', $ua) => 'mobile',
            $ua === '' => null,
            default => 'desktop',
        };
        return [$device, $browser, $os];
    }
}
