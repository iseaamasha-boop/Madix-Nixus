<?php
declare(strict_types=1);

namespace App\Support;

/**
 * الملفات الثابتة للنسخة المنشورة على الخادم:
 *  - تصغير CSS وJavaScript (حذف التعليقات والمسافات فقط، دون إعادة كتابة الشيفرة) عند بناء ملف التثبيت.
 *  - رقم إصدار لكل ملف من محتواه: الملف الذي لم يتغير يبقى في ذاكرة المتصفح بعد التحديث، والذي تغيّر يُطلب من جديد.
 *  - نسخ مضغوطة مسبقاً (brotli وgzip) بجانب الملفات النصية يرسلها الخادم كما هي بدل ضغطها مع كل طلب.
 */
final class Assets
{
    /** ملف أرقام الإصدارات (مصفوفة PHP تقرؤها كل صفحة من ذاكرة OPcache) */
    public static function manifestPath(): string
    {
        return STORAGE_PATH . '/cache/assets.php';
    }

    /** ملفات لا تُصغَّر: مكتبات مصغّرة أصلاً، ومكتبة الهوتسبوت المنشورة على أجهزة MikroTik كما هي */
    private const KEEP = ['#/vendor/#', '#\.min\.(js|css)$#', '#/hotspot/#'];

    // ------------------------------------------------------------------ CSS

    public static function minifyCss(string $css): string
    {
        // أجزاء: نصوص بين علامتي تنصيص تبقى كما هي، وما بينها تُحذف منه التعليقات وتُختصر مسافاته
        $parts = [];
        $buf = '';
        $n = strlen($css);
        for ($i = 0; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $css[$j] !== $c) {
                    $j += $css[$j] === '\\' ? 2 : 1;
                }
                $parts[] = [false, $buf];
                $parts[] = [true, substr($css, $i, $j - $i + 1)];
                $buf = '';
                $i = $j;
                continue;
            }
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;
                $buf .= ' ';
                continue;
            }
            $buf .= $c;
        }
        $parts[] = [false, $buf];
        $out = '';
        foreach ($parts as [$isString, $text]) {
            if ($isString) {
                $out .= $text;
                continue;
            }
            // مسافة واحدة، ولا مسافة حول الأقواس والفواصل المنقوطة والفواصل (لا تمس + و- و: لأن معناها يتغير)
            $text = (string) preg_replace('/\s+/', ' ', $text);
            $text = (string) preg_replace('/\s*([{};,])\s*/', '$1', $text);
            $out .= $text;
        }
        $out = str_replace(';}', '}', $out);
        return trim($out) . "\n";
    }

    // ------------------------------------------------------------------ JavaScript

    /**
     * حذف التعليقات والمسافات الزائدة مع إبقاء فواصل الأسطر (فلا يتغير الفصل التلقائي بين الجمل).
     * يميّز النصوص وقوالب النص (`...${...}`) والتعابير النمطية (/.../) حتى لا يُحذف منها شيء.
     */
    public static function minifyJs(string $js): string
    {
        $n = strlen($js);
        $out = '';
        $i = 0;
        // آخر رمز مهم كُتب: يحدد هل «/» بداية تعبير نمطي أم قسمة
        $prev = '';
        $prevWord = '';
        $stack = [];      // قوالب النص المفتوحة: عدد الأقواس { المفتوحة داخل ${ }
        $keywords = ['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await'];
        while ($i < $n) {
            $c = $js[$i];
            $d = $js[$i + 1] ?? '';
            // داخل ${ } لقالب نص: إغلاق القوس الموافق يعيدنا إلى القالب
            if ($stack && $c === '}' && end($stack) === 0) {
                array_pop($stack);
                $j = self::templateEnd($js, $i + 1, $n, $stack);
                $out .= substr($js, $i, $j - $i);
                $i = $j;
                $prev = '`';
                continue;
            }
            if ($stack && ($c === '{' || $c === '}')) {
                $stack[count($stack) - 1] += $c === '{' ? 1 : -1;
            }
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $js[$j] !== $c && $js[$j] !== "\n") {
                    $j += $js[$j] === '\\' ? 2 : 1;
                }
                $out .= substr($js, $i, $j - $i + 1);
                $i = $j + 1;
                $prev = $c;
                $prevWord = '';
                continue;
            }
            if ($c === '`') {
                $j = self::templateEnd($js, $i + 1, $n, $stack);
                $out .= substr($js, $i, $j - $i);
                $i = $j;
                $prev = '`';
                $prevWord = '';
                continue;
            }
            if ($c === '/' && $d === '/') {
                $end = strpos($js, "\n", $i);
                $i = $end === false ? $n : $end;
                continue;
            }
            if ($c === '/' && $d === '*') {
                $end = strpos($js, '*/', $i + 2);
                $comment = substr($js, $i, ($end === false ? $n : $end + 2) - $i);
                $i = $end === false ? $n : $end + 2;
                // تعليق متعدد الأسطر يفصل جملتين: يبقى مكانه سطر جديد
                if (str_contains($comment, "\n")) {
                    $out .= "\n";
                } elseif ($prev !== '' && ctype_alnum(substr($out, -1)) && ctype_alnum($js[$i] ?? '')) {
                    $out .= ' ';
                }
                continue;
            }
            if ($c === '/') {
                $regex = $prev === '' || str_contains('(,=:[!&|?{};+-*%<>~^', $prev) || in_array($prevWord, $keywords, true);
                if ($regex) {
                    $j = $i + 1;
                    $class = false;
                    while ($j < $n && $js[$j] !== "\n") {
                        $ch = $js[$j];
                        if ($ch === '\\') {
                            $j += 2;
                            continue;
                        }
                        if ($ch === '[') {
                            $class = true;
                        } elseif ($ch === ']') {
                            $class = false;
                        } elseif ($ch === '/' && !$class) {
                            break;
                        }
                        $j++;
                    }
                    $j++;
                    while ($j < $n && ctype_alpha($js[$j])) {
                        $j++;
                    }
                    $out .= substr($js, $i, $j - $i);
                    $i = $j;
                    $prev = '/';
                    $prevWord = '';
                    continue;
                }
            }
            if ($c === "\n" || $c === "\r") {
                $out = rtrim($out, " \t");
                if ($out !== '' && !str_ends_with($out, "\n")) {
                    $out .= "\n";
                }
                $i++;
                continue;
            }
            if ($c === ' ' || $c === "\t") {
                $j = $i;
                while ($j < $n && ($js[$j] === ' ' || $js[$j] === "\t")) {
                    $j++;
                }
                // مسافة واحدة فقط حيث تفصل كلمتين أو رمزين قد يلتصقان (a b، + +، - -)
                $l = substr($out, -1);
                $r = $js[$j] ?? '';
                if ($l !== '' && $l !== "\n" && (self::word($l) && self::word($r) || ($l === '+' && $r === '+') || ($l === '-' && $r === '-'))) {
                    $out .= ' ';
                }
                $i = $j;
                continue;
            }
            $out .= $c;
            if (self::word($c)) {
                $prevWord = ctype_alnum($prev) || $prev === '_' || $prev === '$' ? $prevWord . $c : $c;
            } else {
                $prevWord = '';
            }
            $prev = $c;
            $i++;
        }
        return trim($out) . "\n";
    }

    private static function word(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) > 127);
    }

    /** نهاية قالب نص بدءاً من $i؛ عند ${ يُفتح سياق شيفرة ويُرجع موضعه */
    private static function templateEnd(string $js, int $i, int $n, array &$stack): int
    {
        while ($i < $n) {
            $ch = $js[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === '`') {
                return $i + 1;
            }
            if ($ch === '$' && ($js[$i + 1] ?? '') === '{') {
                $stack[] = 0;
                return $i + 2;
            }
            $i++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ البناء

    /** تصغير ملفات مجلد assets في مكانها (نسخة ملف التثبيت فقط، لا المستودع) */
    public static function minifyDir(string $dir): array
    {
        $stats = ['files' => 0, 'before' => 0, 'after' => 0];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, ['css', 'js'], true) || self::kept($path)) {
                continue;
            }
            $src = (string) file_get_contents($path);
            $min = $ext === 'css' ? self::minifyCss($src) : self::minifyJs($src);
            file_put_contents($path, $min);
            $stats['files']++;
            $stats['before'] += strlen($src);
            $stats['after'] += strlen($min);
        }
        return $stats;
    }

    private static function kept(string $path): bool
    {
        foreach (self::KEEP as $re) {
            if (preg_match($re, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * على الخادم بعد كل تثبيت أو تحديث: أرقام الإصدارات من المحتوى، والنسخ المضغوطة مسبقاً.
     * @return array{files:int, compressed:int}
     */
    public static function build(string $public): array
    {
        $root = rtrim($public, '/') . '/assets';
        $manifest = [];
        $compressed = 0;
        $brotli = self::brotliBinary();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            $ext = strtolower($f->getExtension());
            if ($ext === 'br' || $ext === 'gz') {
                continue;
            }
            $rel = substr($path, strlen($root) + 1);
            $manifest[$rel] = substr(md5_file($path), 0, 10);
            if (in_array($ext, ['css', 'js', 'svg', 'json'], true) && $f->getSize() > 1024) {
                $data = (string) file_get_contents($path);
                file_put_contents($path . '.gz', (string) gzencode($data, 9));
                if ($brotli !== null) {
                    exec(escapeshellarg($brotli) . ' -f -q 11 -o ' . escapeshellarg($path . '.br') . ' ' . escapeshellarg($path) . ' 2>/dev/null', $o, $code);
                    if ($code !== 0) {
                        @unlink($path . '.br');
                    }
                }
                $compressed++;
            }
        }
        ksort($manifest);
        $file = self::manifestPath();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file . '.tmp', "<?php\nreturn " . var_export($manifest, true) . ";\n");
        rename($file . '.tmp', $file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        return ['files' => count($manifest), 'compressed' => $compressed];
    }

    private static function brotliBinary(): ?string
    {
        foreach (['/usr/bin/brotli', '/usr/local/bin/brotli'] as $b) {
            if (is_executable($b)) {
                return $b;
            }
        }
        return null;
    }
}
