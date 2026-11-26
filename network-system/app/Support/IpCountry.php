<?php
declare(strict_types=1);

namespace App\Support;

/**
 * دولة عنوان IP من ملف محلي (app/Data/ipcountry.bin، بلا أي خدمة خارجية).
 * بحث ثنائي داخل الملف دون تحميله في الذاكرة: نحو 20 قراءة صغيرة لكل عنوان.
 * IPv4 بدقة /24 وIPv6 بدقة /32 (كافية لمعرفة الدولة). العناوين الخاصة والمجهولة: null.
 */
final class IpCountry
{
    private const RECORD = 6;

    public static function file(): string
    {
        return APP_PATH . '/Data/ipcountry.bin';
    }

    public static function of(string $ip): ?string
    {
        $ip = trim($ip);
        // العناوين الخاصة والمحجوزة ليست لدولة (والملف يدمج الفجوات بين نطاقات الدولة نفسها)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $bin = substr($bin, 12);
        }
        $v4 = strlen($bin) === 4;
        $key = unpack('N', substr($bin, 0, 4))[1];
        if ($v4) {
            $key >>= 8;
        }
        $fh = @fopen(self::file(), 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $head = fread($fh, 12);
            if (strlen($head) !== 12 || substr($head, 0, 4) !== 'IPC1') {
                return null;
            }
            ['a' => $n4, 'b' => $n6] = unpack('Na/Nb', substr($head, 4));
            $base = 12 + ($v4 ? 0 : $n4 * self::RECORD);
            $n = $v4 ? $n4 : $n6;
            // آخر سجل يبدأ عند المفتاح أو قبله
            $lo = 0;
            $hi = $n - 1;
            $found = -1;
            while ($lo <= $hi) {
                $mid = ($lo + $hi) >> 1;
                fseek($fh, $base + $mid * self::RECORD);
                $start = unpack('N', (string) fread($fh, 4))[1];
                if ($start <= $key) {
                    $found = $mid;
                    $lo = $mid + 1;
                } else {
                    $hi = $mid - 1;
                }
            }
            if ($found < 0) {
                return null;
            }
            fseek($fh, $base + $found * self::RECORD + 4);
            $cc = (string) fread($fh, 2);
            return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : null;
        } finally {
            fclose($fh);
        }
    }
}
