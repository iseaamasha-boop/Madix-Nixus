<?php
declare(strict_types=1);

namespace Tests;

/** أدوات التحقق في الاختبارات */
final class Fail extends \RuntimeException
{
}

final class T
{
    public static int $asserts = 0;

    public static function ok(mixed $cond, string $msg = 'expected true'): void
    {
        self::$asserts++;
        if (!$cond) {
            throw new Fail($msg);
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::$asserts++;
        if ($expected !== $actual) {
            throw new Fail(($msg !== '' ? $msg . ': ' : '') . 'expected ' . self::show($expected) . ', got ' . self::show($actual));
        }
    }

    public static function contains(string $needle, string $hay, string $msg = ''): void
    {
        self::$asserts++;
        if (!str_contains($hay, $needle)) {
            throw new Fail(($msg !== '' ? $msg . ': ' : '') . 'missing ' . self::show($needle) . ' in ' . self::show(mb_substr($hay, 0, 300)));
        }
    }

    public static function notContains(string $needle, string $hay, string $msg = ''): void
    {
        self::$asserts++;
        if (str_contains($hay, $needle)) {
            throw new Fail(($msg !== '' ? $msg . ': ' : '') . 'unexpected ' . self::show($needle));
        }
    }

    public static function status(array|int $expected, array $res, string $msg = ''): void
    {
        self::$asserts++;
        $expected = (array) $expected;
        if (!in_array($res['status'], $expected, true)) {
            throw new Fail(($msg !== '' ? $msg . ': ' : '') . 'HTTP ' . $res['status'] . ' (expected ' . implode('/', $expected) . ') for ' . ($res['url'] ?? '') . ' ' . mb_substr(strip_tags((string) $res['body']), 0, 300));
        }
    }

    /** لا أخطاء PHP ظاهرة في الصفحة */
    public static function cleanHtml(array $res): void
    {
        self::$asserts++;
        foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught ', 'Stack trace'] as $bad) {
            if (str_contains((string) $res['body'], $bad)) {
                throw new Fail('PHP error text "' . $bad . '" in ' . ($res['url'] ?? ''));
            }
        }
    }

    public static function show(mixed $v): string
    {
        $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mb_substr($s === false ? var_export($v, true) : $s, 0, 300);
    }
}
