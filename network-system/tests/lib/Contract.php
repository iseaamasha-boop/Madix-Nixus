<?php
declare(strict_types=1);

namespace Tests;

/**
 * عقد الواجهات: شكل ردود JSON (المفاتيح وأنواع القيم) مسجّل من النسخة الأصلية في tests/fixtures/contract.json.
 * أي نسخة جديدة يجب أن تُرجع كل المفاتيح القديمة بالأنواع نفسها (يجوز أن تضيف مفاتيح)،
 * حتى تبقى تطبيقات الجوال المثبتة عند الناس وصفحات الهوتسبوت على أجهزة MikroTik تعمل.
 */
final class Contract
{
    private static ?array $data = null;
    private static bool $dirty = false;

    public static function file(): string
    {
        return dirname(__DIR__) . '/fixtures/contract.json';
    }

    /** شكل قيمة: نوعها، ومفاتيحها إن كانت كائناً، وشكل أول عنصر إن كانت قائمة */
    public static function shape(mixed $v): mixed
    {
        if (is_array($v)) {
            if ($v === []) {
                return '[]';
            }
            if (array_is_list($v)) {
                return ['[0]' => self::shape($v[0])];
            }
            $out = [];
            foreach ($v as $k => $x) {
                $out[(string) $k] = self::shape($x);
            }
            ksort($out);
            return $out;
        }
        return match (true) {
            $v === null => 'null',
            is_bool($v) => 'bool',
            is_int($v), is_float($v) => 'number',
            default => 'string',
        };
    }

    /** الفروق: ما كان في الشكل القديم ولم يعد موجوداً أو تغيّر نوعه */
    public static function missing(mixed $old, mixed $new, string $path = ''): array
    {
        if ($old === 'null' || $new === 'null' || $old === '[]' || $new === '[]') {
            return [];
        }
        if (is_array($old)) {
            if (!is_array($new)) {
                return [$path . ': was object/list, now ' . (is_string($new) ? $new : 'other')];
            }
            $out = [];
            foreach ($old as $k => $sub) {
                if (!array_key_exists($k, $new)) {
                    $out[] = $path . '.' . $k . ': removed';
                    continue;
                }
                $out = array_merge($out, self::missing($sub, $new[$k], $path . '.' . $k));
            }
            return $out;
        }
        return $old === $new ? [] : [$path . ": was $old, now " . (is_string($new) ? $new : 'object')];
    }

    /** مقارنة رد بالعقد المسجّل (أو تسجيله مع --record) */
    public static function check(string $name, ?array $json): void
    {
        T::ok(is_array($json), $name . ': not JSON');
        $shape = self::shape($json);
        self::load();
        if (\defined('TEST_RECORD') && TEST_RECORD) {
            self::$data[$name] = $shape;
            self::$dirty = true;
            self::save();
            return;
        }
        T::ok(isset(self::$data[$name]), $name . ': no recorded contract (run tests with --record on the original version)');
        $diff = self::missing(self::$data[$name], $shape, $name);
        T::eq([], $diff, 'API contract broken');
    }

    private static function load(): void
    {
        if (self::$data === null) {
            $f = self::file();
            self::$data = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
        }
    }

    private static function save(): void
    {
        if (self::$dirty) {
            ksort(self::$data);
            file_put_contents(self::file(), json_encode(self::$data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }
}
