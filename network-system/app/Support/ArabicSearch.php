<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\DB;

/**
 * البحث العربي الذكي: «احمد» يجد «أحمد» و«إحمد»، «فاطمه» يجد «فاطمة»، «مصطفي» يجد «مصطفى»، «مومن» يجد «مؤمن»،
 * والتشكيل والتطويل (ـ) لا يمنعان التطابق، والأرقام العربية تُقرأ لاتينية.
 *
 * قاعدة البيانات تتجاهل التشكيل أصلاً، أما صور الحروف فتُطابَق بتعبير منتظم (REGEXP) بفئات حروف:
 * سريع على آلاف الصفوف (أسرع من LIKE في القياس)، ويرجع إلى LIKE العادي على خوادم MySQL القديمة.
 */
final class ArabicSearch
{
    /** مجموعات الحروف المتكافئة في البحث */
    private const GROUPS = [
        'اأإآٱ', 'ةه', 'ىيئی', 'وؤ', 'كک',
    ];
    private const MARKS = '[\x{064B}-\x{065F}\x{0670}\x{0640}]*';
    private const MAX = 60;

    private static ?bool $supported = null;
    private static ?array $map = null;

    /** النص بصورة موحّدة (للمقارنة في PHP) */
    public static function normalize(string $s): string
    {
        $s = latin_digits($s);
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ئ' => 'ي', 'ی' => 'ي', 'ؤ' => 'و', 'ک' => 'ك']);
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /** تعبير منتظم يطابق النص بكل صور حروفه */
    public static function regex(string $q): string
    {
        $q = mb_substr(self::normalize($q), 0, self::MAX);
        if (self::$map === null) {
            self::$map = [];
            foreach (self::GROUPS as $g) {
                foreach (mb_str_split($g) as $ch) {
                    self::$map[$ch] = '[' . $g . ']';
                }
            }
        }
        $out = '';
        foreach (mb_str_split($q) as $ch) {
            if ($ch === ' ') {
                $out .= '[[:space:]]+';
                continue;
            }
            $part = self::$map[$ch] ?? preg_quote($ch, '/');
            // بعد كل حرف عربي: تشكيل أو تطويل اختياري
            $out .= $part . (preg_match('/\p{Arabic}/u', $ch) ? self::MARKS : '');
        }
        return $out;
    }

    /** هل يدعم خادم قاعدة البيانات التعابير المنتظمة بحروف يونيكود (MariaDB، أو MySQL 8) */
    public static function supported(): bool
    {
        if (self::$supported === null) {
            try {
                $v = (string) DB::value('SELECT VERSION()');
                self::$supported = stripos($v, 'mariadb') !== false || version_compare((string) preg_replace('/[^0-9.].*$/', '', $v), '8.0.4', '>=');
            } catch (\Throwable) {
                self::$supported = false;
            }
        }
        return self::$supported;
    }

    /**
     * شرط SQL وقيمته للبحث في عمود نصي: [«العمود REGEXP ?»، النمط] أو [«العمود LIKE ?»، %النص%] على الخوادم القديمة.
     */
    public static function cond(string $column, string $q): array
    {
        $q = trim($q);
        if ($q === '' || !self::supported()) {
            return [$column . ' LIKE ?', DB::like($q)];
        }
        return [$column . ' REGEXP ?', self::regex($q)];
    }
}
