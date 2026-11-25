<?php
declare(strict_types=1);

namespace App\Core;

/**
 * مفتاح منع التكرار: كل عملية تعديل يرسلها المتصفح أو التطبيق تحمل مفتاحاً فريداً (الترويسة Idempotency-Key،
 * أو الحقل المخفي _idem في النماذج). إعادة الطلب نفسه — ضغط مزدوج، إعادة تحميل بعد انقطاع النت، إعادة محاولة
 * تلقائية — تُرجع نتيجة المرة الأولى المحفوظة ولا تُنفَّذ العملية مرتين: لا بيع مكرر ولا دفعة مكررة.
 *
 * النطاق: الشبكة + المستخدم + الطريقة والمسار + محتوى الطلب. المفتاح نفسه ببيانات مختلفة عملية جديدة،
 * ولا يرى مستخدم نتيجة غيره. الملفات في مجلد الشبكة نفسها وتُحذف بعد يوم.
 */
final class Idempotency
{
    public const FIELD = '_idem';
    public const HEADER = 'Idempotency-Key';

    private const TTL = 86400;
    /** ثوانٍ لانتظار طلب سابق بالمفتاح نفسه ما زال قيد التنفيذ (من التطبيق بلا جلسة) */
    private const WAIT = 15;
    private const MAX_BODY = 2097152;
    private const KEEP_HEADERS = ['Location', 'Content-Type', 'Content-Disposition'];

    /** المفتاح من الطلب إن كان صالحاً */
    public static function key(Request $request): ?string
    {
        $key = (string) ($request->header(self::HEADER) ?? $request->post[self::FIELD] ?? '');
        return preg_match('/^[A-Za-z0-9_-]{16,80}$/', $key) === 1 ? $key : null;
    }

    /**
     * تنفيذ العملية مرة واحدة لكل مفتاح. $exec تُرجع الاستجابة النهائية.
     */
    public static function run(Request $request, string $key, string $actor, callable $exec, bool $again = true): Response
    {
        $dir = storage_path('idem');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (mt_rand(1, 100) === 1) {
            self::gc($dir);
        }
        $file = $dir . '/' . hash('sha256', json_encode([
            \App\Core\Cache::context(), $actor, $request->method, $request->path, $key, self::fingerprint($request),
        ]));

        $fh = @fopen($file, 'x');
        if ($fh === false) {
            $saved = self::wait($file);
            if ($saved === false && $again) {
                // الطلب الأول فشل وحُذف مفتاحه: هذه محاولة جديدة
                return self::run($request, $key, $actor, $exec, false);
            }
            if (is_array($saved)) {
                return self::replay($saved);
            }
            $message = 'هذا الطلب أُرسل من قبل وما زال قيد التنفيذ.';
            return $request->wantsJson() || $request->header(self::HEADER) !== null
                ? Response::json(['ok' => false, 'pending' => true, 'message' => $message], 409, ['Retry-After' => '3'])
                : self::pendingRedirect($message);
        }
        fclose($fh);

        $before = count((array) ($_SESSION['_flash'] ?? []));
        try {
            $response = $exec();
        } catch (\Throwable $e) {
            // لم تكتمل العملية (خطأ قبل الحفظ عادة): يُسمح بإعادتها
            @unlink($file);
            throw $e;
        }
        // خطأ في الخادم: يُسمح بإعادة المحاولة. الصفحات الحساسة (رموز الاسترجاع، المفاتيح، كلمات المرور) لا تُحفظ نتيجتها
        if ($response->status >= 500 || strlen($response->body) > self::MAX_BODY || Kernel::sensitive($request->path)) {
            @unlink($file);
            return $response;
        }
        $saved = [
            'status'  => $response->status,
            'headers' => array_intersect_key($response->headers, array_flip(self::KEEP_HEADERS)),
            'body'    => base64_encode($response->body),
            'flash'   => array_values(array_slice((array) ($_SESSION['_flash'] ?? []), $before)),
        ];
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, (string) json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
            @unlink($file);
        }
        return $response;
    }

    /** بصمة محتوى الطلب (دون رمز الحماية والمفتاح) */
    private static function fingerprint(Request $request): string
    {
        $post = $request->post;
        unset($post['_token'], $post[self::FIELD]);
        $files = [];
        foreach ($request->files as $name => $f) {
            $files[$name] = [$f['name'] ?? null, $f['size'] ?? null];
        }
        $raw = str_contains((string) ($request->server['CONTENT_TYPE'] ?? ''), 'json') ? $request->raw() : '';
        return hash('sha256', serialize([$post, $files, $raw]));
    }

    /**
     * النتيجة المحفوظة؛ null ما دام الطلب الأول قيد التنفيذ بعد مدة الانتظار، وfalse إن حُذف المفتاح (فشل الطلب الأول).
     * في المتصفح ينتظر الطلب الثاني انتهاء الأول عند فتح الجلسة نفسها، فيجد النتيجة فوراً.
     */
    private static function wait(string $file): array|false|null
    {
        $until = microtime(true) + self::WAIT;
        do {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                return false;
            }
            $raw = (string) @file_get_contents($file);
            if ($raw !== '') {
                $saved = json_decode($raw, true);
                return is_array($saved) ? $saved : null;
            }
            usleep(200000);
        } while (microtime(true) < $until);
        return null;
    }

    private static function replay(array $saved): Response
    {
        foreach ((array) ($saved['flash'] ?? []) as $f) {
            if (isset($f['type'], $f['message'])) {
                flash((string) $f['type'], (string) $f['message']);
            }
        }
        $response = new Response((string) base64_decode((string) ($saved['body'] ?? '')), (int) ($saved['status'] ?? 200), (array) ($saved['headers'] ?? []));
        $response->headers['X-Idempotent-Replay'] = '1';
        return $response;
    }

    private static function pendingRedirect(string $message): Response
    {
        flash('warning', $message);
        return back('/');
    }

    private static function gc(string $dir): void
    {
        $limit = time() - self::TTL;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (@filemtime($f) < $limit) {
                @unlink($f);
            }
        }
    }
}
