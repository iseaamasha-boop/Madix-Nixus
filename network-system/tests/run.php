<?php
declare(strict_types=1);

/*
 * اختبارات النظام كلها على قاعدة بيانات مؤقتة، بلا أي اتصال خارجي.
 *
 *   php tests/run.php                 كل المجموعات
 *   php tests/run.php Platform        مجموعة واحدة (أو أكثر: Platform,Hotspot)
 *   php tests/run.php --record        تسجيل عقد واجهات التطبيق والهوتسبوت من النسخة الحالية (tests/fixtures/contract.json)
 *   php tests/run.php --keep          إبقاء البيئة المؤقتة بعد الانتهاء (للفحص اليدوي)
 *
 * قاعدة البيانات: MNX_TEST_DB_HOST/PORT/USER/PASS (افتراضياً root عبر مقبس MariaDB المحلي).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

putenv('SN_CONFIG=/nonexistent/mnx-test-config.php');
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib/T.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Env.php';
require __DIR__ . '/lib/Contract.php';

use Tests\Env;
use Tests\Fail;
use Tests\T;

$filter = [];
$flags = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $flags[substr($a, 2)] = true;
    } else {
        $filter = array_merge($filter, array_map('strtolower', explode(',', $a)));
    }
}
define('TEST_RECORD', isset($flags['record']));

$suites = [];
foreach (glob(__DIR__ . '/suites/*.php') as $file) {
    $name = basename($file, '.php');
    if ($filter && !in_array(strtolower($name), $filter, true)) {
        continue;
    }
    $suites[$name] = $file;
}
ksort($suites);
if (!$suites) {
    fwrite(STDERR, "no suites match\n");
    exit(1);
}

$c = static fn (string $code, string $s): string => posix_isatty(STDOUT) ? "\e[{$code}m{$s}\e[0m" : $s;
$started = microtime(true);
$env = new Env();
$pass = 0;
$failed = [];
try {
    $env->start();
    // سياق المنصة داخل عملية الاختبار نفسها (للاختبارات التي تستدعي الشيفرة مباشرة)
    App\Core\Config::load(require $env->config);
    App\Core\Platform::boot();
    echo 'MADIX NEXUS tests · ' . $env->base . ' · run ' . $env->run . PHP_EOL;
    foreach ($suites as $name => $file) {
        $tests = (static fn (Env $env) => require $file)($env);
        echo PHP_EOL . $c('1', $name) . PHP_EOL;
        foreach ($tests as $title => $fn) {
            $t0 = microtime(true);
            try {
                $fn($env);
                $pass++;
                printf("  %s %s %s\n", $c('32', '✔'), $title, $c('2', sprintf('%.0fms', (microtime(true) - $t0) * 1000)));
            } catch (Throwable $e) {
                $failed[] = "$name › $title";
                $where = $e instanceof Fail ? '' : ' [' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
                printf("  %s %s\n    %s%s\n", $c('31', '✘'), $title, $c('31', $e->getMessage()), $where);
            }
        }
    }
} catch (Throwable $e) {
    $failed[] = 'setup';
    echo $c('31', 'setup failed: ' . $e->getMessage()) . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
} finally {
    $log = $env->serverLog();
    if (preg_match_all('/PHP (Fatal error|Warning|Notice|Deprecated|Parse error):[^\n]*/', $log, $m)) {
        $uniq = array_unique($m[0]);
        echo PHP_EOL . $c('33', 'PHP messages in the server log (' . count($m[0]) . '):') . PHP_EOL . '  ' . implode("\n  ", array_slice($uniq, 0, 15)) . PHP_EOL;
        if (preg_grep('/Fatal error|Parse error/', $uniq)) {
            $failed[] = 'server log has fatal errors';
        }
    }
    if (isset($flags['keep'])) {
        echo "kept: {$env->dir} ({$env->base})\n";
    } else {
        $env->teardown();
    }
}
printf("\n%s passed, %s failed, %d assertions, %.1fs\n", $c('32', (string) $pass), $c($failed ? '31' : '32', (string) count($failed)), T::$asserts, microtime(true) - $started);
if ($failed) {
    echo $c('31', 'FAILED: ' . implode(' | ', $failed)) . PHP_EOL;
    exit(1);
}
exit(0);
