<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;

/**
 * تفضيلات الموظف الشخصية (عمود employees.prefs بصيغة JSON): الأصوات (البند 57)، ترتيب لوحة التحكم (البند 108).
 * تخص الموظف وحده ولا تغيّر شيئاً لغيره.
 */
final class Prefs
{
    /** قيمة تفضيل للموظف الحالي (أو لصف موظف معطى) */
    public static function get(string $key, mixed $default = null, ?array $user = null): mixed
    {
        $user ??= Auth::user();
        $all = self::decode($user['prefs'] ?? null);
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** حفظ تفضيل (أو حذفه بقيمة null) للموظف */
    public static function set(int $employeeId, string $key, mixed $value): void
    {
        $all = self::decode(DB::value('SELECT prefs FROM employees WHERE id = ?', [$employeeId]));
        if ($value === null) {
            unset($all[$key]);
        } else {
            $all[$key] = $value;
        }
        DB::update('employees', ['prefs' => $all ? json_encode($all, JSON_UNESCAPED_UNICODE) : null], 'id = ?', [$employeeId]);
    }

    private static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $v = json_decode($raw, true);
        return is_array($v) ? $v : [];
    }
}
