<?php
declare(strict_types=1);

/*
 * ترحيل قاعدة البيانات: إضافات فقط، ويُعاد بأمان مرتين، ويُطبَّق على قواعد كل الشبكات.
 */

use App\Core\DB;
use App\Core\Migrator;
use App\Core\Platform;
use App\Core\Settings;
use App\Core\Tenant;
use Tests\Env;
use Tests\T;

$enter = static function (Env $env, string $key): array {
    $t = $env->tenant($key, 5, 30);
    Tenant::leave();
    $row = Platform::tenantBySlug($t['slug']);
    T::ok($row !== null, 'tenant row');
    Tenant::enter($row);
    return $t;
};

/** كل الجداول في ملف البنية */
$schemaTables = static function (): array {
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \(/', (string) file_get_contents(BASE_PATH . '/database/schema.sql'), $m);
    return $m[1];
};

return [
    'a new network is created at the current schema version' => static function (Env $env) use ($enter, $schemaTables): void {
        $enter($env, 'm1');
        T::eq((string) Migrator::VERSION, (string) Settings::get('app.schema_version'));
        $have = DB::column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
        T::eq([], array_values(array_diff($schemaTables(), $have)), 'missing tables');
        Tenant::leave();
    },
    'migrating an old network twice is safe and adds what is missing' => static function (Env $env) use ($enter, $schemaTables): void {
        $t = $enter($env, 'm2');
        $pdo = $env->db($t['db']);
        // محاكاة قاعدة من نسخة قديمة: جداول وأعمدة أضافتها خطوات لاحقة غير موجودة
        $pdo->exec('DROP TABLE IF EXISTS speed_caps');
        $pdo->exec('DROP TABLE IF EXISTS hotspot_ads');
        $pdo->exec('ALTER TABLE sessions DROP COLUMN rate_down, DROP COLUMN rate_up, DROP COLUMN heavy_since, DROP COLUMN heavy_alerted');
        $pdo->exec("UPDATE settings SET `value` = '13' WHERE `key` = 'app.schema_version'");
        $rowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn();
        Tenant::leave();
        for ($i = 0; $i < 2; $i++) {
            Tenant::enter(Platform::tenantBySlug($t['slug']));
            Migrator::run();
            T::eq((string) Migrator::VERSION, (string) Settings::get('app.schema_version'), 'version after run ' . ($i + 1));
            Tenant::leave();
        }
        $have = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        T::eq([], array_values(array_diff($schemaTables(), $have)), 'missing tables after migration');
        T::eq(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'rate_down'")->fetchColumn(), 'sessions.rate_down');
        T::eq($rowsBefore, (int) $pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn(), 'no data lost');
    },
    'platform migration is idempotent' => static function (Env $env): void {
        Tenant::leave();
        Platform::migrate();
        Platform::migrate();
        T::ok((int) DB::value('SELECT COUNT(*) FROM platform_admins') >= 1, 'admins kept');
    },
];
