<?php
declare(strict_types=1);

/*
 * الملفات الثابتة:
 *   php cli/assets.php minify DIR   تصغير CSS وJavaScript في مجلد (نسخة ملف التثبيت أثناء البناء، لا المستودع)
 *   php cli/assets.php build        على الخادم بعد التثبيت أو التحديث: أرقام الإصدارات من المحتوى والنسخ المضغوطة مسبقاً
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Support\Assets;

switch ($argv[1] ?? '') {
    case 'minify':
        $dir = (string) ($argv[2] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            fwrite(STDERR, "usage: php cli/assets.php minify DIR\n");
            exit(1);
        }
        $s = Assets::minifyDir($dir);
        printf("minified %d files: %d KB -> %d KB\n", $s['files'], $s['before'] / 1024, $s['after'] / 1024);
        exit(0);
    case 'build':
        $s = Assets::build(PUBLIC_PATH);
        printf("assets: %d files, %d compressed copies\n", $s['files'], $s['compressed']);
        exit(0);
    default:
        fwrite(STDERR, "minify DIR | build\n");
        exit(1);
}
