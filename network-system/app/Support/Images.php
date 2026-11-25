<?php
declare(strict_types=1);

namespace App\Support;

/** نسخ WebP للصور المرفوعة: أصغر للمتصفح، والأصل يبقى كما هو لما يحتاجه (صفحات الهوتسبوت، الطباعة، التطبيقات القديمة) */
final class Images
{
    public static function webp(string $src, string $dst, int $quality = 82): bool
    {
        if (!function_exists('imagewebp') || !is_file($src) || filesize($src) > 8 * 1048576) {
            return false;
        }
        $info = @getimagesize($src);
        if (!$info || $info[0] * $info[1] > 16000000) {
            return false;
        }
        $img = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($src),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            default => false,
        };
        if (!$img) {
            return false;
        }
        if ($info[2] === IMAGETYPE_PNG) {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $tmp = $dst . '.' . bin2hex(random_bytes(4));
        $ok = @imagewebp($img, $tmp, $quality);
        imagedestroy($img);
        if (!$ok || !@rename($tmp, $dst)) {
            @unlink($tmp);
            return false;
        }
        @chmod($dst, 0644);
        return true;
    }
}
