<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\DB;

/**
 * البند 107: «مين موجود هلأ»: الموظفون الذين يفتحون الصفحة نفسها الآن.
 * نبضة داخل طلب التحديث المجمّع (/live) كل 20 ثانية والتبويب ظاهر فقط؛ صف واحد لكل تبويب في قاعدة الشبكة نفسها
 * (لا تسريب بين الشبكات)، تحديث صف واحد واستعلام واحد بفهرس. يعمل فقط حين يكون في الشبكة أكثر من موظف فعّال.
 */
final class Presence
{
    /** من لم ينبض خلال هذه المدة خرج من الصفحة (نبضتان فائتتان تقريباً) */
    public const TTL = 45;

    public static function enabled(): bool
    {
        try {
            return (bool) Cache::remember('presence.on', [], ['employees'], static fn () => (int) DB::value(
                "SELECT COUNT(*) FROM employees WHERE status = 'active'"
            ) > 1, 300, false);
        } catch (\Throwable) {
            return false;
        }
    }

    /** مفتاح الصفحة: المسار وحده (بلا استعلام)، مختصراً بطول ثابت للفهرس */
    public static function key(string $path): string
    {
        return substr(hash('sha256', $path), 0, 16);
    }

    /**
     * يسجّل نبضة هذا التبويب على صفحته، ويعيد بقية الموظفين على الصفحة نفسها الآن
     * @return list<array{id:int,name:string,i:string,h:int}>
     */
    public static function ping(int $employeeId, string $tab, string $path): array
    {
        if ($employeeId <= 0 || !preg_match('/^[A-Za-z0-9]{8,32}$/', $tab) || $path === '' || $path[0] !== '/' || strlen($path) > 190) {
            return [];
        }
        $key = self::key($path);
        $now = date('Y-m-d H:i:s');
        DB::query(
            'INSERT INTO presence (employee_id, tab, path_key, seen_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE path_key = VALUES(path_key), seen_at = VALUES(seen_at)',
            [$employeeId, $tab, $key, $now]
        );
        $rows = DB::rows(
            "SELECT e.id, e.name FROM presence p JOIN employees e ON e.id = p.employee_id
             WHERE p.path_key = ? AND p.seen_at >= ? AND p.employee_id <> ? AND e.status = 'active'
             GROUP BY e.id, e.name ORDER BY MIN(p.seen_at) LIMIT 8",
            [$key, date('Y-m-d H:i:s', time() - self::TTL), $employeeId]
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'],
            'i' => avatar_initials((string) $r['name']), 'h' => avatar_hue((int) $r['id']),
        ], $rows);
    }

    /** تنظيف الصفوف القديمة (من المهام الدورية) */
    public static function cleanup(): void
    {
        DB::query('DELETE FROM presence WHERE seen_at < ?', [date('Y-m-d H:i:s', time() - 3600)]);
    }
}
