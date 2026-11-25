<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static bool $started = false;

    public static function start(bool $secure, string $name = 'SNSID'): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        // الجلسات في مجلد النظام بمعالج خاص، فلا تتأثر بإعداد session.save_path على الخادم
        $dir = self::directory();
        if ($dir !== null) {
            $handler = new FileSessionHandler($dir);
            if (@session_set_save_handler($handler, true) && random_int(1, 100) === 1) {
                $handler->gc(43200);
            }
        }
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (base_path() ?: '') . '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '43200');
        if (@session_start() === false) {
            throw new \RuntimeException('تعذّر تشغيل الجلسات على الخادم. اجعل المجلد storage قابلاً للكتابة (الصلاحية 755 أو 775).');
        }
        self::$started = true;

        // نقل بيانات الطلب السابق (المدخلات والأخطاء) إلى الطلب الحالي فقط.
        // التحميل المسبق لا يستهلكها: تبقى للصفحة التي يفتحها المستخدم فعلاً
        if (is_prefetch()) {
            $_SESSION['_old_now'] = [];
            $_SESSION['_errors_now'] = [];
            return;
        }
        $_SESSION['_old_now'] = $_SESSION['_old'] ?? [];
        $_SESSION['_errors_now'] = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }

    /** أول مجلد قابل للكتابة لحفظ الجلسات */
    private static function directory(): ?string
    {
        $candidates = [
            storage_path('sessions'),
            rtrim(sys_get_temp_dir(), '/\\') . '/mnx-sess-' . substr(md5(BASE_PATH), 0, 10),
        ];
        foreach ($candidates as $dir) {
            if ((@is_dir($dir) || @mkdir($dir, 0700, true)) && @is_writable($dir)) {
                return $dir;
            }
        }
        return null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::resume();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::resume();
        unset($_SESSION[$key]);
    }

    /** قراءة قيمة تُعرض مرة واحدة ثم حذفها. طلب التحميل المسبق لا يستهلكها: تبقى للصفحة التي تُفتح فعلاً */
    public static function pull(string $key, mixed $default = null): mixed
    {
        if (is_prefetch()) {
            return $default;
        }
        if (!array_key_exists($key, $_SESSION ?? [])) {
            return $default;
        }
        $v = $_SESSION[$key];
        // الجلسة قد تكون أُغلقت مبكراً (طلبات القراءة): تُفتح من جديد ليُحفظ الحذف
        self::resume();
        unset($_SESSION[$key]);
        return $v ?? $default;
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
    }

    /** حفظ المدخلات والأخطاء للطلب التالي (بعد إعادة التوجيه) */
    public static function flashInput(array $input, array $errors = []): void
    {
        self::resume();
        unset($input['_token'], $input['password'], $input['password_confirmation'], $input['api_password']);
        $_SESSION['_old'] = $input;
        $_SESSION['_errors'] = $errors;
    }

    /**
     * إعادة فتح الجلسة بعد إغلاقها مبكراً (لحفظ الرسائل مثلاً).
     * تُقرأ من جديد من ملفها: ما كتبه هذا الطلب حُفظ عند الإغلاق، وما غيّره طلب موازٍ بعده (الخروج مثلاً) يبقى كما هو،
     * ثم يُطبَّق التعديل المطلوب وحده.
     */
    public static function resume(): void
    {
        // الطلبات بلا جلسة (واجهات التطبيق) تبقى بلا جلسة
        if (!self::$started) {
            return;
        }
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            $data = $_SESSION ?? [];
            if (@session_start() === false) {
                $_SESSION = $data;
            }
        }
    }

    /**
     * علامة الجلسة الحالية (ليست سراً: بصمة من معرّف الجلسة ومفتاح النظام) في وسم meta وفي ملف تعريف ارتباط يقرؤه المتصفح.
     * صفحة مستعادة بزر الرجوع بعد الخروج أو بعد دخول مستخدم آخر لا تطابق علامتها الملف الحالي، فتُطلب من الخادم من جديد.
     */
    public static function tag(string $cookie = 'mnx_tag'): string
    {
        $id = PHP_SAPI === 'cli' ? '' : (string) session_id();
        if ($id === '') {
            return '';
        }
        $tag = substr(hash_hmac('sha256', $id, (string) Config::get('app.key', '')), 0, 16);
        if (($_COOKIE[$cookie] ?? '') !== $tag && !headers_sent()) {
            setcookie($cookie, $tag, ['path' => self::cookiePath($cookie), 'secure' => (bool) Request::current()?->isHttps(), 'httponly' => false, 'samesite' => 'Lax']);
        }
        return $tag;
    }

    /** حذف علامة الجلسة (عند الخروج) */
    public static function forgetTag(string $cookie = 'mnx_tag'): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie($cookie, '', ['expires' => time() - 3600, 'path' => self::cookiePath($cookie), 'samesite' => 'Lax']);
        }
    }

    private static function cookiePath(string $cookie): string
    {
        return $cookie === 'mnx_atag' ? '/' : (base_path() ?: '') . '/';
    }

    /** إغلاق ملف الجلسة مبكراً للطلبات الطويلة كي لا تُقفل الطلبات الأخرى */
    public static function release(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
