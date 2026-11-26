<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;

// ---------------------------------------------------------------------------
// مخرجات وعناوين
// ---------------------------------------------------------------------------

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** مسار جذر التثبيت داخل النطاق (مثلاً /net) أو فارغ — لا يتغير بتغيّر الزبون */
function root_path(bool $reset = false): string
{
    static $root = null;
    if ($reset) {
        $root = null;
    }
    if ($root === null) {
        $url = \App\Core\Platform::enabled() ? \App\Core\Platform::rootUrl() : (string) Config::get('app.url', '');
        if ($url !== '') {
            $root = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        } else {
            // قبل التثبيت: استنتاج المسار من موقع السكربت (مع أو بدون مجلد public)
            $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
            $dir = preg_replace('#/public$#', '', rtrim($dir, '/'));
            $root = $dir === '/' ? '' : (string) $dir;
        }
    }
    return $root;
}

/** طلب تحميل مسبق من المتصفح (Speculation Rules) قبل أن يضغط المستخدم الرابط */
function is_prefetch(): bool
{
    $p = strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? ''));
    return str_contains($p, 'prefetch');
}

/** المسار الأساسي للتطبيق: الجذر، وفي وضع المنصة يضاف اسم حساب الزبون (/sohaib) */
function base_path(bool $reset = false): string
{
    if ($reset) {
        return '';
    }
    $slug = \App\Core\Tenant::slug();
    return root_path() . ($slug !== null ? '/' . $slug : '');
}

/** رابط ملف مرفوع (يُخدم من جذر التثبيت دائماً) */
function upload_url(string $relative): string
{
    return root_path() . '/' . ltrim($relative, '/');
}

/** رابط نسخة WebP بجانب صورة مرفوعة (PNG/JPEG)، تُنشأ مرة واحدة عند أول حاجة؛ null إن تعذّر */
function upload_webp(string $relative): ?string
{
    if (!preg_match('/\.(png|jpe?g)$/i', $relative)) {
        return null;
    }
    $rel = ltrim($relative, '/');
    $webp = (string) preg_replace('/\.(png|jpe?g)$/i', '.webp', $rel);
    if (!is_file(PUBLIC_PATH . '/' . $webp) && !\App\Support\Images::webp(PUBLIC_PATH . '/' . $rel, PUBLIC_PATH . '/' . $webp)) {
        return null;
    }
    return upload_url($webp);
}

/** صورة مرفوعة: WebP للمتصفحات التي تدعمه والأصل لغيرها (الغلاف لا يؤثر على التخطيط) */
function upload_img(string $relative, string $attrs = 'alt=""'): string
{
    $img = '<img src="' . e(upload_url($relative)) . '" ' . $attrs . '>';
    $webp = upload_webp($relative);
    return $webp === null ? $img : '<picture class="pic"><source srcset="' . e($webp) . '" type="image/webp">' . $img . '</picture>';
}

/** مجلد الرفع للزبون الحالي والمسار النسبي المقابل */
function upload_dir(): array
{
    $slug = \App\Core\Tenant::slug();
    $rel = 'uploads' . ($slug !== null ? '/t/' . $slug : '');
    return [PUBLIC_PATH . '/' . $rel, $rel];
}

function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $q = $query ? '?' . http_build_query(array_filter($query, static fn ($v) => $v !== null && $v !== '')) : '';
    return base_path() . ($path === '/' ? '/' : $path) . ($q === '?' ? '' : $q);
}

function absolute_url(string $path = '/'): string
{
    $root = rtrim((string) Config::get('app.url', ''), '/');
    if ($root === '') {
        $req = Request::current();
        $root = $req ? $req->origin() . base_path() : '';
    } else {
        $root = preg_replace('#(https?://[^/]+).*#', '$1', $root) . base_path();
    }
    return $root . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    static $versions = [];
    $rel = ltrim($path, '/');
    // الواجهة الإنجليزية: نسخة JavaScript مترجمة مولّدة (cli/i18n.php js)
    if (str_starts_with($rel, 'js/') && !str_starts_with($rel, 'js/i18n/') && \App\Core\Lang::isEn()) {
        $alt = 'js/i18n/en/' . substr($rel, 3);
        if (is_file(PUBLIC_PATH . '/assets/' . $alt)) {
            $rel = $alt;
        }
    }
    if (!isset($versions[$rel])) {
        // على الخادم: رقم الإصدار من محتوى الملف (يُبنى عند التثبيت والتحديث): ما لم يتغير يبقى في ذاكرة المتصفح بعد التحديث
        static $manifest = null;
        if ($manifest === null) {
            $m = STORAGE_PATH . '/cache/assets.php';
            $manifest = is_file($m) ? (array) (include $m) : [];
        }
        if (isset($manifest[$rel])) {
            $versions[$rel] = $manifest[$rel];
            return root_path() . '/assets/' . $rel . '?v=' . $versions[$rel];
        }
        $file = PUBLIC_PATH . '/assets/' . $rel;
        $v = APP_VERSION;
        if (is_file($file)) {
            $time = (int) filemtime($file);
            // ملف بلا تاريخ صالح (نسخة مستخرجة بتاريخ صفري): بصمة المحتوى تضمن تغيّر الرابط مع كل تحديث
            $v = $time > 946684800 ? (string) $time : (string) hash_file('crc32b', $file);
        }
        $versions[$rel] = $v;
    }
    return root_path() . '/assets/' . $rel . '?v=' . $versions[$rel];
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true"><use href="'
        . e(asset('img/icons.svg')) . '#i-' . e($name) . '"></use></svg>';
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

/** زمن بعد/قبل عدد من الثواني من الآن */
function time_offset(int $seconds, ?string $from = null): string
{
    $base = $from !== null ? strtotime($from) : time();
    return date('Y-m-d H:i:s', $base + $seconds);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    $old = Session::get('_old_now', []);
    if (is_array($old) && array_key_exists($key, $old)) {
        return $old[$key];
    }
    return $default;
}

function field_error(string $key): ?string
{
    $errors = Session::get('_errors_now', []);
    return is_array($errors) && isset($errors[$key]) ? (string) $errors[$key] : null;
}

function error_html(string $key): string
{
    $msg = field_error($key);
    return $msg !== null ? '<div class="field-error">' . e($msg) . '</div>' : '';
}

function flash(string $type, string $message): void
{
    $list = Session::get('_flash', []);
    $list[] = ['type' => $type, 'message' => $message];
    Session::set('_flash', $list);
}

/** رسالة نجاح مع زر «تراجع» (رمز التراجع من App\Services\Undo) */
function flash_undo(string $message, string $token): void
{
    $list = Session::get('_flash', []);
    $list[] = ['type' => 'success', 'message' => $message, 'undo' => $token];
    Session::set('_flash', $list);
}

/**
 * البند 90: الحرفان الأولان لصورة رمزية: أول حرف من أول كلمتين، أو أول حرفين من كلمة واحدة.
 * التشكيل والتطويل يُحذفان، والحرفان العربيان يُفصلان بفاصل غير مرئي (ZWNJ) فيظهر كل حرف منفصلاً لا متصلاً.
 */
function avatar_initials(string $name): string
{
    $clean = trim((string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $name));
    $words = array_values(array_filter(preg_split('/[\s\-_.]+/u', $clean) ?: [], static fn ($w) => $w !== ''));
    if (!$words) {
        return '؟';
    }
    $first = static function (string $w): array {
        preg_match_all('/\X/u', $w, $m);
        return $m[0];
    };
    $a = $first($words[0]);
    $chars = count($words) > 1 ? [$a[0], $first($words[1])[0]] : array_slice($a, 0, 2);
    $chars = array_map(static fn ($c) => mb_strtoupper($c), $chars);
    $arabic = static fn ($c) => preg_match('/^\p{Arabic}$/u', $c) === 1;
    return count($chars) === 2 && $arabic($chars[0]) && $arabic($chars[1]) ? $chars[0] . "\u{200C}" . $chars[1] : implode('', $chars);
}

/** لون ثابت (درجة 0–359) من رقم السجل، أو من النص إن لم يكن رقماً: الأرقام المتتالية تأخذ ألواناً متباعدة */
function avatar_hue(int|string $seed): int
{
    return is_int($seed) || ctype_digit((string) $seed) ? ((int) $seed * 137) % 360 : (int) (crc32((string) $seed) % 360);
}

/** صورة رمزية ملونة بحرفين (بلا خدمات خارجية)؛ $class: sm أو lg، و$inner عنصر إضافي داخلها (نقطة الحالة) */
function avatar(string $name, int|string $seed, string $class = '', string $inner = ''): string
{
    return '<span class="avatar av' . ($class !== '' ? ' ' . $class : '') . '" style="--h:' . avatar_hue($seed) . '" aria-hidden="true">'
        . e(avatar_initials($name)) . $inner . '</span>';
}

/** صوت مع آخر رسالة (البند 57): يعزفه المتصفح لمن شغّل الأصوات فقط */
function flash_sound(string $sound): void
{
    $list = Session::get('_flash', []);
    if ($list) {
        $list[array_key_last($list)]['sound'] = $sound;
        Session::set('_flash', $list);
    }
}

function auth(): ?array
{
    return Auth::user();
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

function view(string $template, array $data = [], string $layout = 'app'): Response
{
    return new Response(View::render($template, $data, $layout));
}

function redirect(string $path, array $query = []): Response
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path, $query);
    return Response::redirect($target);
}

function back(string $fallback = '/'): Response
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref !== '' && $host !== '' && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
        return Response::redirect($ref);
    }
    return redirect($fallback);
}

function json_response(mixed $data, int $status = 200, array $headers = []): Response
{
    return Response::json($data, $status, $headers);
}

function abort(int $code, string $message = ''): never
{
    throw new App\Core\HttpException($code, $message);
}

// ---------------------------------------------------------------------------
// تنسيق القيم
// ---------------------------------------------------------------------------

function num(int|float|string|null $value, int $decimals = 0): string
{
    return number_format((float) ($value ?? 0), $decimals, '.', ',');
}

/** عزل نص لاتيني (رقم مع وحدة) كي يظهر بالترتيب الصحيح داخل النص العربي */
function ltr(string $text): string
{
    return "\u{2066}" . $text . "\u{2069}";
}

function money(int|float|string|null $amount): string
{
    $amount = round((float) ($amount ?? 0), 2);
    $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
    $symbol = (string) Settings::get('general.currency', '₪');
    return ltr(number_format($amount, $decimals, '.', ',') . ($symbol !== '' ? ' ' . $symbol : ''));
}

function fmt_bytes(int|float|string|null $bytes, int $precision = 1): string
{
    $bytes = (float) ($bytes ?? 0);
    if ($bytes <= 0) {
        return ltr('0 B');
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = (int) min(floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $i);
    $num = number_format($value, $i === 0 ? 0 : $precision, '.', ',');
    if (str_contains($num, '.')) {
        $num = rtrim(rtrim($num, '0'), '.');
    }
    return ltr($num . ' ' . $units[$i]);
}

function fmt_rate(int|float|string|null $bps): string
{
    $bps = (float) ($bps ?? 0);
    if ($bps < 1000) {
        return ltr(number_format($bps, 0) . ' bps');
    }
    $units = ['Kbps', 'Mbps', 'Gbps'];
    $i = -1;
    while ($bps >= 1000 && $i < 2) {
        $bps /= 1000;
        $i++;
    }
    $text = number_format($bps, $bps >= 100 ? 0 : 1);
    return ltr((str_ends_with($text, '.0') ? substr($text, 0, -2) : $text) . ' ' . $units[$i]);
}

/** صيغ العدد في العربية: 1، 2، 3-10، 11+ */
function ar_plural(int $n, string $one, string $two, string $few, string $many): string
{
    if (\App\Core\Lang::isEn()) {
        // الواجهة الإنجليزية: الصيغ مترجمة من القاموس (المفرد والمثنى صيغ كاملة، والجمع بعد العدد)
        if ($n === 1) {
            return ['يوم' => '1 day', 'ساعة' => '1 hour', 'دقيقة' => '1 minute', 'أسبوع' => '1 week', 'شهر' => '1 month', 'ثانية' => '1 second'][$one] ?? \App\Core\I18n::text($one);
        }
        if ($n === 2) {
            return \App\Core\I18n::text($two);
        }
        return $n . ' ' . \App\Core\I18n::text($n <= 10 ? $few : $many);
    }
    if ($n === 1) {
        return $one;
    }
    if ($n === 2) {
        return $two;
    }
    if ($n >= 3 && $n <= 10) {
        return $n . ' ' . $few;
    }
    return $n . ' ' . $many;
}

function fmt_duration(int|float|string|null $seconds, bool $short = false): string
{
    $s = (int) ($seconds ?? 0);
    $en = \App\Core\Lang::isEn();
    if ($s <= 0) {
        return $short ? '0' : ($en ? '0 seconds' : '0 ثانية');
    }
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    if ($en) {
        if ($short) {
            return $d > 0 ? sprintf('%dd %02d:%02d', $d, $h, $m) : sprintf('%02d:%02d:%02d', $h, $m, $sec);
        }
        $parts = [];
        if ($d > 0) {
            $parts[] = $d . ($d === 1 ? ' day' : ' days');
        }
        if ($h > 0) {
            $parts[] = $h . ($h === 1 ? ' hour' : ' hours');
        }
        if ($m > 0 && $d === 0) {
            $parts[] = $m . ($m === 1 ? ' minute' : ' minutes');
        }
        if (!$parts) {
            $parts[] = $sec . ($sec === 1 ? ' second' : ' seconds');
        }
        return implode(' ', array_slice($parts, 0, 2));
    }
    if ($short) {
        if ($d > 0) {
            return sprintf('%dي %02d:%02d', $d, $h, $m);
        }
        return sprintf('%02d:%02d:%02d', $h, $m, $sec);
    }
    $parts = [];
    if ($d > 0) {
        $parts[] = $d . ' يوم';
    }
    if ($h > 0) {
        $parts[] = $h . ' ساعة';
    }
    if ($m > 0 && $d === 0) {
        $parts[] = $m . ' دقيقة';
    }
    if (!$parts) {
        $parts[] = $sec . ' ثانية';
    }
    return implode(' و', array_slice($parts, 0, 2));
}

function fmt_dt(?string $datetime, bool $withSeconds = false): string
{
    if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date($withSeconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i', $ts) : '—';
}

function fmt_date(?string $datetime): string
{
    if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date('Y-m-d', $ts) : '—';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $diff = time() - (int) strtotime($datetime);
    if ($diff < 0) {
        return fmt_dt($datetime);
    }
    if (\App\Core\Lang::isEn()) {
        $u = static fn (int $n, string $w) => $n . ' ' . $w . ($n === 1 ? '' : 's') . ' ago';
        return match (true) {
            $diff < 60 => 'just now',
            $diff < 3600 => $u(intdiv($diff, 60), 'minute'),
            $diff < 86400 => $u(intdiv($diff, 3600), 'hour'),
            $diff < 86400 * 30 => $u(intdiv($diff, 86400), 'day'),
            default => fmt_date($datetime),
        };
    }
    if ($diff < 60) {
        return 'الآن';
    }
    if ($diff < 3600) {
        return 'منذ ' . ar_plural(intdiv($diff, 60), 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة');
    }
    if ($diff < 86400) {
        return 'منذ ' . ar_plural(intdiv($diff, 3600), 'ساعة', 'ساعتين', 'ساعات', 'ساعة');
    }
    if ($diff < 86400 * 30) {
        return 'منذ ' . ar_plural(intdiv($diff, 86400), 'يوم', 'يومين', 'أيام', 'يوماً');
    }
    return fmt_date($datetime);
}

/** الثواني المتبقية حتى تاريخ (سالبة إذا انقضى) */
function seconds_until(?string $datetime): ?int
{
    if (!$datetime) {
        return null;
    }
    return (int) strtotime($datetime) - time();
}

// ---------------------------------------------------------------------------
// أدوات عامة
// ---------------------------------------------------------------------------

function normalize_mac(?string $mac): ?string
{
    if ($mac === null) {
        return null;
    }
    $hex = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $mac));
    if (strlen($hex) !== 12) {
        return null;
    }
    return implode(':', str_split($hex, 2));
}

function is_valid_ip(?string $ip): bool
{
    return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

/** تحويل قيم الإدخال الفارغة إلى null */
function nullable(mixed $value): mixed
{
    if (is_string($value)) {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
    return $value;
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $value): string
{
    return $value ? ' checked' : '';
}

function str_limit(?string $text, int $length = 60): string
{
    $text = (string) $text;
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
}

/** تحويل الأرقام العربية-الهندية والفارسية إلى أرقام لاتينية */
function latin_digits(string $value): string
{
    return strtr($value, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function storage_path(string $path = ''): string
{
    $slug = \App\Core\Tenant::slug();
    $root = STORAGE_PATH . ($slug !== null ? '/tenants/' . $slug : '');
    return $root . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/** ملف مؤقت داخل storage/tmp (لا يعتمد على مجلد الخادم المؤقت) */
function temp_file(string $prefix): string
{
    $dir = storage_path('tmp');
    if (!@is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!@is_dir($dir) || !@is_writable($dir)) {
        $dir = sys_get_temp_dir();
    }
    $file = @tempnam($dir, $prefix);
    if ($file === false) {
        throw new RuntimeException('تعذّر إنشاء ملف مؤقت على الخادم.');
    }
    return $file;
}

/** إنشاء المجلدات المؤقتة المعرّفة في إعداد PHP إن كانت غير موجودة (بعض الاستضافات) */
function ensure_php_temp_dirs(): void
{
    foreach (['session.save_path', 'upload_tmp_dir', 'sys_temp_dir'] as $key) {
        $dir = (string) ini_get($key);
        if ($key === 'session.save_path' && str_contains($dir, ';')) {
            $parts = explode(';', $dir);
            $dir = (string) end($parts);
        }
        $dir = trim($dir);
        if ($dir !== '' && !@is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
}

function log_message(string $channel, string $message, array $context = []): void
{
    $dir = storage_path('logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($dir . '/' . $channel . '-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
