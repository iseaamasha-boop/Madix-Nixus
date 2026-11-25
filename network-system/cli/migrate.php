<?php
declare(strict_types=1);

/*
 * ترحيل كل قواعد البيانات إلى نسخة الشيفرة الحالية (يشغّله التحديث على الخادم):
 *   php cli/migrate.php
 * المنصة ثم كل حساب أياً كانت حالته (التجربة، الفعّال، الموقوف، الملغى)، وقاعدة الشبكة في التثبيت المفرد.
 * كل خطوة ترحيل تضيف فقط وتُعاد بأمان، فتشغيله مرتين لا يغيّر شيئاً. رمز الخروج 1 إن فشلت أي قاعدة.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Cache;
use App\Core\Config;
use App\Core\Migrator;
use App\Core\Platform;
use App\Core\Settings;
use App\Core\Tenant;

if (!Config::installed()) {
    fwrite(STDERR, "System is not installed.\n");
    exit(1);
}

$failed = 0;
$done = 0;
if (Platform::enabled()) {
    Platform::boot();
    Platform::migrate();
    foreach (App\Core\DB::rows('SELECT * FROM tenants ORDER BY id') as $t) {
        try {
            Tenant::enter($t);
            Migrator::run(true);
            $v = (int) Settings::get('app.schema_version', '0');
            echo str_pad((string) $t['slug'], 30) . ' ' . $v . PHP_EOL;
            $done++;
        } catch (Throwable $e) {
            $failed++;
            fwrite(STDERR, $t['slug'] . ': ' . $e->getMessage() . PHP_EOL);
        } finally {
            Tenant::leave();
        }
    }
} else {
    try {
        Migrator::run(true);
        echo 'database ' . Settings::get('app.schema_version', '0') . PHP_EOL;
        $done++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
    }
}
// الشيفرة الجديدة قد تحسب الأرقام بطريقة أخرى: لا نتائج محفوظة من النسخة السابقة
Cache::flushAll(true);
echo "migrated $done, failed $failed (schema " . Migrator::VERSION . ')' . PHP_EOL;
exit($failed ? 1 : 0);
