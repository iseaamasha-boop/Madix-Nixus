<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AdminAuth;
use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Settings;

/**
 * البند 108: لوحة التحكم على ذوق الموظف والمسؤول.
 * - الموظف يرتّب بطاقات لوحة شبكته ويكبّرها ويصغّرها لنفسه (تفضيلاته الشخصية)، ومسؤول المنصة يفعل ذلك في لوحة المسؤول.
 * - مسؤول المنصة يطبّق ترتيباً للوحة الشبكات على كل الأنظمة: كافتراضي (لمن لم يرتّب لوحته)، أو على الجميع
 *   (يتقدّم على الترتيبات الشخصية السابقة دون حذفها، ولكل موظف أن يرتّب بعدها من جديد).
 * - الترتيب يُطبَّق من الخادم عند رسم الصفحة (لا قفزة بعد التحميل).
 * الترتيب: ['stats' => [مفتاح => مقاس]، 'panels' => [مفتاح => مقاس]] بالترتيب.
 */
final class DashLayout
{
    /** لوحة الشبكة: المفاتيح بترتيبها ومقاسها الأصليين */
    public const NET = [
        'stats' => ['online' => 's', 'routers' => 's', 'active' => 's', 'available' => 's', 'subs' => 's', 'sales' => 's', 'collected' => 's', 'traffic' => 's'],
        'panels' => ['chart' => 'l', 'mikrotik' => 's', 'days' => 'l', 'stock' => 's', 'logins' => 's', 'top' => 's', 'expiring' => 's'],
    ];

    /** لوحة المسؤول */
    public const ADMIN = [
        'stats' => ['all' => 's', 'trial' => 's', 'active' => 's', 'expired' => 's', 'revenue' => 's'],
        'panels' => ['signups' => 'l', 'ending' => 's', 'latest' => 'l', 'activity' => 's'],
    ];

    /** المقاسات: بطاقة الرقم عادية أو عريضة، واللوحة ثلث أو نصف أو ثلثان أو كامل العرض */
    public const SIZES = ['stats' => ['s', 'w'], 'panels' => ['s', 'm', 'l', 'x']];

    /** أسماء القطع (لقائمة استوديو التصميم) */
    public const NET_LABELS = [
        'online' => 'المتصلون الآن', 'routers' => 'أجهزة MikroTik المتصلة', 'active' => 'الكروت النشطة', 'available' => 'الكروت المتاحة',
        'subs' => 'المشتركون الفعّالون', 'sales' => 'مبيعات الكروت اليوم', 'collected' => 'المحصّل اليوم', 'traffic' => 'حركة الإنترنت الآن',
        'chart' => 'المتصلون والسرعة', 'mikrotik' => 'حالة أجهزة MikroTik', 'days' => 'المبيعات والتفعيلات خلال 30 يوماً', 'stock' => 'مخزون الباقات',
        'logins' => 'آخر عمليات الدخول', 'top' => 'الأعلى استهلاكاً الآن', 'expiring' => 'اشتراكات تنتهي قريباً والتنبيهات',
    ];

    /**
     * تنقية ترتيب مرسل: مفاتيح معروفة فقط بلا تكرار، ومقاسات صالحة، وما نقص يُلحق بمقاسه الأصلي في آخره.
     * يعيد null إن لم يكن ترتيباً صالحاً أصلاً.
     */
    public static function sanitize(array $def, mixed $in): ?array
    {
        if (is_string($in)) {
            $in = json_decode($in, true);
        }
        if (!is_array($in)) {
            return null;
        }
        $out = [];
        foreach (['stats', 'panels'] as $g) {
            $out[$g] = [];
            foreach ((array) ($in[$g] ?? []) as $item) {
                [$k, $s] = is_array($item) ? [(string) ($item[0] ?? ''), (string) ($item[1] ?? '')] : [(string) $item, ''];
                if (!isset($def[$g][$k]) || isset($out[$g][$k])) {
                    continue;
                }
                $out[$g][$k] = in_array($s, self::SIZES[$g], true) ? $s : $def[$g][$k];
            }
            foreach ($def[$g] as $k => $s) {
                $out[$g][$k] ??= $s;
            }
        }
        return $out;
    }

    /** الصيغة المحفوظة (قوائم [مفتاح، مقاس]) */
    public static function pack(array $layout): array
    {
        $out = [];
        foreach (['stats', 'panels'] as $g) {
            $out[$g] = array_map(static fn ($k, $s) => [$k, $s], array_keys($layout[$g]), array_values($layout[$g]));
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // لوحة الشبكة
    // ------------------------------------------------------------------

    /** ترتيب لوحة الشبكة للموظف الحالي: ترتيبه، أو ترتيب المنصة، أو الأصل */
    public static function net(?array $user = null): array
    {
        $mine = Prefs::get('dash', null, $user ?? Auth::user());
        [$sys, $at] = self::system();
        if (is_array($mine) && ($at === 0 || (int) ($mine['at'] ?? 0) > $at)) {
            $l = self::sanitize(self::NET, $mine);
            if ($l) {
                return $l;
            }
        }
        return $sys ?? self::NET;
    }

    /** هل للموظف ترتيب خاص نافذ الآن؟ */
    public static function netCustom(?array $user = null): bool
    {
        $mine = Prefs::get('dash', null, $user ?? Auth::user());
        [, $at] = self::system();
        return is_array($mine) && ($at === 0 || (int) ($mine['at'] ?? 0) > $at);
    }

    public static function saveNet(int $employeeId, array $layout): void
    {
        Prefs::set($employeeId, 'dash', ['at' => time()] + self::pack($layout));
    }

    public static function resetNet(int $employeeId): void
    {
        Prefs::set($employeeId, 'dash', null);
    }

    /** ترتيب المنصة للوحة الشبكات ووقت «تطبيقه على الجميع» (0 = افتراضي فقط) */
    public static function system(): array
    {
        try {
            $raw = Platform::enabled() ? Platform::setting('dash.layout', '') : (Config::installed() ? (string) Settings::get('dash.layout', '') : '');
            $at = (int) (Platform::enabled() ? Platform::setting('dash.layout_at', '0') : (Config::installed() ? Settings::get('dash.layout_at', '0') : 0));
        } catch (\Throwable) {
            return [null, 0];
        }
        return [$raw !== '' ? self::sanitize(self::NET, $raw) : null, $at];
    }

    /** مسؤول المنصة يطبّق ترتيباً على كل الأنظمة ($all: على الجميع، وإلا كافتراضي) */
    public static function applySystem(array $layout, bool $all): void
    {
        $values = ['dash.layout' => json_encode(self::pack($layout))];
        if ($all) {
            $values['dash.layout_at'] = (string) time();
        }
        Platform::setSettings($values);
    }

    public static function resetSystem(): void
    {
        Platform::setSettings(['dash.layout' => '', 'dash.layout_at' => '0']);
    }

    // ------------------------------------------------------------------
    // لوحة المسؤول
    // ------------------------------------------------------------------

    public static function admin(): array
    {
        $raw = (string) Platform::value('SELECT prefs FROM platform_admins WHERE id = ?', [AdminAuth::id()]);
        $all = json_decode($raw, true);
        $l = is_array($all) && isset($all['dash']) ? self::sanitize(self::ADMIN, $all['dash']) : null;
        return $l ?? self::ADMIN;
    }

    public static function saveAdmin(int $adminId, ?array $layout): void
    {
        $all = json_decode((string) Platform::value('SELECT prefs FROM platform_admins WHERE id = ?', [$adminId]), true);
        $all = is_array($all) ? $all : [];
        if ($layout === null) {
            unset($all['dash']);
        } else {
            $all['dash'] = self::pack($layout);
        }
        Platform::exec('UPDATE platform_admins SET prefs = ? WHERE id = ?', [$all ? json_encode($all, JSON_UNESCAPED_UNICODE) : null, $adminId]);
    }

    // ------------------------------------------------------------------
    // الرسم
    // ------------------------------------------------------------------

    /** صنف المقاس لعنصر */
    public static function size(array $layout, string $group, string $key): string
    {
        return ' dsz-' . ($layout[$group][$key] ?? 's');
    }

    /** يرسم القطع المحفوظة في $blocks بترتيب المجموعة (ما لا يحق للموظف رؤيته غير موجود في $blocks أصلاً) */
    public static function render(array $layout, string $group, array $blocks): string
    {
        $html = '';
        foreach (array_keys($layout[$group]) as $k) {
            if (isset($blocks[$k]) && $blocks[$k] !== '') {
                $html .= $blocks[$k];
            }
        }
        return $html;
    }
}
