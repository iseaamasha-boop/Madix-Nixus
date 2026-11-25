<?php
declare(strict_types=1);

namespace App\Core;

/**
 * كاش قصير للأرقام الثقيلة (لوحة التحكم والتقارير والعدّادات).
 *
 * - معزول لكل شبكة: ملفاته في مجلد خاص برقم الشبكة، والمفتاح نفسه يحمل رقمها، فلا تظهر أرقام شبكة في شبكة أخرى.
 * - مفتاحه يحمل صلاحيات المستخدم ونطاق أجهزة MikroTik المسموحة له: موظفان بالصلاحيات نفسها يتشاركان النتيجة، ولا يرى أحد غير ما يحق له.
 * - يُمسح تلقائياً عند تغيّر البيانات: كل نتيجة تُسجَّل مع «أجيال» الجداول التي بُنيت منها، وأي كتابة على جدول
 *   (INSERT/UPDATE/DELETE عبر DB) ترفع جيله فتُهمل النتائج القديمة فوراً.
 * - مدته قصيرة (60 ثانية افتراضياً) حتى لما لا يُكتب عبر النظام (أدوات خارجية).
 */
final class Cache
{
    /**
     * أعمدة تتغير باستمرار مع المراقبة دون أن تغيّر الأعداد والإجماليات (آخر ظهور، الاستهلاك، السرعة...).
     * تعديل يمس هذه الأعمدة فقط يرفع جيل «الجدول~» لا «الجدول»: أعداد الكروت حسب الحالة مثلاً تبقى في الكاش
     * مع كل دخول لكرت، وتُمسح فور تغيّر حالة كرت أو بيعه أو تفعيله. أي عمود غير مذكور هنا يُعامَل كتغيير حقيقي.
     */
    private const VOLATILE = [
        'cards'       => ['last_login_at', 'last_mac', 'last_ip', 'last_router_id', 'used_seconds', 'used_bytes_in', 'used_bytes_out', 'bound_mac', 'updated_at'],
        'sessions'    => ['updated_at', 'uptime', 'bytes_in', 'bytes_out', 'rate_down', 'rate_up', 'heavy_since', 'heavy_alerted', 'ip', 'mac', 'active_ref', 'login_by'],
        'subscribers' => ['last_online_at', 'last_mac', 'last_ip', 'last_router_id', 'router_state', 'period_bytes', 'period_started_at', 'fup_active', 'bound_mac', 'updated_at'],
        'routers'     => ['last_seen_at', 'last_polled_at', 'next_poll_at', 'fail_count', 'last_error', 'uptime_seconds', 'cpu_load', 'cpu_count', 'memory_total', 'memory_free',
            'hdd_total', 'hdd_free', 'temperature', 'voltage', 'active_hotspot', 'active_ppp', 'rx_bps', 'tx_bps', 'metrics_at', 'info_synced_at', 'leases_synced_at',
            'users_synced_at', 'power_at', 'power_state', 'identity', 'board_name', 'model', 'serial_number', 'version', 'ros_major', 'architecture', 'updated_at'],
        'card_routers' => ['uptime', 'bytes_in', 'bytes_out', 'carry_uptime', 'carry_bytes_in', 'carry_bytes_out', 'synced_at'],
        'subscriber_routers' => ['uptime', 'bytes_in', 'bytes_out', 'synced_at'],
    ];

    /** الجداول التي تغيّرت ولم يُسجَّل جيلها الجديد بعد (تُسجَّل قبل أي قراءة من الكاش وفي نهاية الطلب) */
    private static array $pending = [];
    private static float $pendingSince = 0.0;
    /** أجيال الجداول المقروءة لكل سياق خلال هذا الطلب */
    private static array $gens = [];
    private static bool $hooked = false;
    /** إيقاف الكاش (الاختبارات ووضع التطوير) */
    public static bool $enabled = true;

    /**
     * نتيجة محفوظة أو محسوبة الآن.
     * @param string   $name    اسم النتيجة
     * @param array    $params  ما تعتمد عليه النتيجة (الفلاتر، التاريخ...)
     * @param string[] $tables  الجداول التي تُقرأ لحسابها
     * @param callable $fn      الحساب
     * @param int      $ttl     المدة بالثواني
     * @param bool     $scoped  إدخال الصلاحيات ونطاق الأجهزة في المفتاح (افتراضياً نعم)
     */
    public static function remember(string $name, array $params, array $tables, callable $fn, int $ttl = 60, bool $scoped = true): mixed
    {
        if (!self::$enabled || !Config::installed()) {
            return $fn();
        }
        self::flushPending();
        [$file, $gens] = self::locate($name, $params, $tables, $scoped);
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $entry = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($entry) && ($entry['exp'] ?? 0) > time() && ($entry['gens'] ?? null) === $gens) {
                return $entry['v'];
            }
        }
        $value = $fn();
        self::write($file, $gens, $value, $ttl);
        if (random_int(1, 400) === 1) {
            self::gc();
        }
        return $value;
    }

    /** قراءة نتيجة محفوظة دون حسابها (null إن لم توجد أو انتهت أو تغيّرت جداولها) */
    public static function get(string $name, array $params, array $tables = [], bool $scoped = true): mixed
    {
        if (!self::$enabled || !Config::installed()) {
            return null;
        }
        self::flushPending();
        [$file, $gens] = self::locate($name, $params, $tables, $scoped);
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $entry = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($entry) && ($entry['exp'] ?? 0) > time() && ($entry['gens'] ?? null) === $gens ? $entry['v'] : null;
    }

    /** حفظ نتيجة */
    public static function put(string $name, array $params, mixed $value, int $ttl = 60, array $tables = [], bool $scoped = true): void
    {
        if (!self::$enabled || !Config::installed()) {
            return;
        }
        self::flushPending();
        [$file, $gens] = self::locate($name, $params, $tables, $scoped);
        self::write($file, $gens, $value, $ttl);
    }

    /** @return array{0:string,1:array} مسار الملف وأجيال جداوله الحالية */
    private static function locate(string $name, array $params, array $tables, bool $scoped): array
    {
        $ctx = self::context();
        $key = hash('sha256', json_encode([$ctx, $name, $params, $scoped ? self::scope() : '', APP_VERSION]));
        return [self::dir($ctx) . '/' . substr($key, 0, 2) . '/' . $key, self::generations($ctx, $tables)];
    }

    private static function write(string $file, array $gens, mixed $value, int $ttl): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, serialize(['exp' => time() + $ttl, 'gens' => $gens, 'v' => $value])) !== false) {
            @rename($tmp, $file);
        }
    }

    /** سياق الكاش: رقم الشبكة في وضع المنصة، أو المنصة نفسها، أو التثبيت المفرد */
    public static function context(): string
    {
        $id = Tenant::id();
        if ($id !== null) {
            return 't' . $id;
        }
        return Platform::enabled() ? 'platform' : 'single';
    }

    /** بصمة الصلاحيات ونطاق أجهزة MikroTik للمستخدم الحالي */
    public static function scope(): string
    {
        $u = Auth::user();
        if ($u === null) {
            return 'guest';
        }
        if (!empty($u['is_owner'])) {
            return 'owner';
        }
        $perms = Auth::permissions();
        sort($perms);
        return hash('sha256', json_encode([$perms, Auth::routerIds()]));
    }

    private static function dir(string $ctx): string
    {
        return storage_path('cache/data/' . $ctx);
    }

    /** أجيال الجداول الحالية في السياق */
    private static function generations(string $ctx, array $tables): array
    {
        if (!isset(self::$gens[$ctx])) {
            $raw = @file_get_contents(self::dir($ctx) . '/gen.json');
            self::$gens[$ctx] = $raw ? (array) json_decode($raw, true) : [];
        }
        $out = [];
        sort($tables);
        foreach ($tables as $t) {
            $out[$t] = (string) (self::$gens[$ctx][$t] ?? self::$gens[$ctx]['*'] ?? '0');
        }
        $out['*'] = (string) (self::$gens[$ctx]['*'] ?? '0');
        return $out;
    }

    /** يُستدعى من DB لكل استعلام كتابة: يحدد الجداول المتغيّرة */
    public static function written(string $sql): void
    {
        if (!preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE)\b/i', $sql, $m)) {
            return;
        }
        $verb = strtoupper($m[1]);
        $tables = [];
        if (in_array($verb, ['ALTER', 'DROP', 'TRUNCATE', 'CREATE'], true)) {
            $tables = ['*'];
        } else {
            preg_match_all('/\b(?:INTO|UPDATE|FROM|JOIN)\s+`?([a-z_][a-z0-9_]*)`?/i', $sql, $mm);
            $tables = array_map('strtolower', $mm[1]);
            if ($verb === 'UPDATE' && preg_match('/^\s*UPDATE\s+(?:IGNORE\s+)?`?([a-z_][a-z0-9_]*)`?\s+SET\s+(.*?)(?:\s+WHERE\s|$)/is', $sql, $u)) {
                $table = strtolower($u[1]);
                $tables = [$table];
                if (isset(self::VOLATILE[$table])) {
                    // أسماء الأعمدة المعدّلة في جملة SET
                    preg_match_all('/(?:^|,)\s*`?([a-z_][a-z0-9_]*)`?\s*=/i', $u[2], $cols);
                    $changed = array_map('strtolower', $cols[1]);
                    if ($changed && !array_diff($changed, self::VOLATILE[$table])) {
                        $tables = [$table . '~'];
                    }
                }
            }
        }
        if (!$tables) {
            return;
        }
        $ctx = self::context();
        foreach ($tables as $t) {
            self::$pending[$ctx][$t] = true;
        }
        if (self::$pendingSince === 0.0) {
            self::$pendingSince = microtime(true);
        } elseif (microtime(true) - self::$pendingSince > 1.0) {
            // العمليات الطويلة (المهام المجدولة) تسجّل التغيير كل ثانية على الأكثر
            self::flushPending();
        }
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'flushPending']);
        }
    }

    /** تسجيل الأجيال الجديدة للجداول المتغيّرة */
    public static function flushPending(): void
    {
        if (!self::$pending) {
            return;
        }
        $pending = self::$pending;
        self::$pending = [];
        self::$pendingSince = 0.0;
        $stamp = sprintf('%.6f', microtime(true)) . '-' . getmypid();
        foreach ($pending as $ctx => $tables) {
            $dir = self::dir($ctx);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                continue;
            }
            $fh = @fopen($dir . '/gen.json', 'c+');
            if (!$fh) {
                continue;
            }
            flock($fh, LOCK_EX);
            $gens = (array) json_decode((string) stream_get_contents($fh), true);
            foreach (array_keys($tables) as $t) {
                $gens[$t] = $stamp;
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($gens));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            self::$gens[$ctx] = $gens;
        }
    }

    /** مسح الكاش (بعد الاستعادة أو التحديث): للسياق الحالي، أو للمنصة وكل الشبكات */
    public static function flushAll(bool $everything = false): void
    {
        // مجلد التخزين نفسه خاص بكل شبكة (storage/tenants/اسم-الشبكة)، والكاش داخله
        $targets = $everything
            ? array_merge([STORAGE_PATH . '/cache/data'], glob(STORAGE_PATH . '/tenants/*/cache/data', GLOB_ONLYDIR) ?: [])
            : [self::dir(self::context())];
        foreach ($targets as $target) {
            if (is_dir($target)) {
                self::rmTree($target);
            }
        }
        self::$gens = [];
    }

    /** حذف الملفات المنتهية */
    public static function gc(?string $ctx = null): void
    {
        $dir = self::dir($ctx ?? self::context());
        foreach (glob($dir . '/??/*') ?: [] as $f) {
            // أطول مدة حفظ يوم واحد (نسخ بيانات أجهزة MikroTik)، فما هو أقدم منتهٍ حتماً
            if (@filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }

    private static function rmTree(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
