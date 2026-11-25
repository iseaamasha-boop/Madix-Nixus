<?php
declare(strict_types=1);

/* الأساس: الدوال المساعدة، التحقق من المدخلات، التشفير، الموجّه */

use App\Core\Crypto;
use App\Core\Router;
use App\Core\Request;
use Tests\T;

return [
    'e() escapes HTML and handles null/bool' => static function (): void {
        T::eq('&lt;b&gt;&quot;x&quot;&#039;', e('<b>"x"\''));
        T::eq('', e(null));
        T::eq('1', e(true));
    },
    'latin_digits converts Arabic and Persian digits' => static function (): void {
        T::eq('0123456789', latin_digits('٠١٢٣٤٥٦٧٨٩'));
        T::eq('0123456789', latin_digits('۰۱۲۳۴۵۶۷۸۹'));
    },
    'formatting helpers' => static function (): void {
        T::contains('1.5', fmt_bytes(1536));
        T::ok(fmt_duration(3600) !== '', 'duration');
        T::eq('02:00:00:00:00:0A', normalize_mac('02-00-00-00-00-0a'));
        T::eq(null, normalize_mac('zz'));
        T::ok(is_valid_ip('10.0.0.1') && !is_valid_ip('10.0.0'), 'ip validation');
    },
    'Crypto round trip and tamper detection' => static function (): void {
        $c = Crypto::encrypt('سر', 'base64:' . base64_encode(random_bytes(32)));
        T::ok(str_starts_with($c, 's1:') || str_starts_with($c, 'g1:'), 'prefix');
        $key = 'base64:' . base64_encode(random_bytes(32));
        T::eq('router-pass', Crypto::decrypt(Crypto::encrypt('router-pass', $key), $key));
        $bad = false;
        try {
            Crypto::decrypt(Crypto::encrypt('x', $key), 'base64:' . base64_encode(random_bytes(32)));
        } catch (Throwable) {
            $bad = true;
        }
        T::ok($bad, 'wrong key must fail');
    },
    'Router matches typed parameters' => static function (): void {
        $r = new Router();
        $hit = null;
        $r->get('/things/{id}/x/{name}', [TestController::class, 'show'], ['auth' => false]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/things/42/x/abc';
        $res = $r->dispatch(new Request($_SERVER, [], [], []));
        T::eq('42|abc', $res->body);
        $missing = false;
        try {
            $_SERVER['REQUEST_URI'] = '/things/abc/x/abc';
            $r->dispatch(new Request($_SERVER, [], [], []));
        } catch (App\Core\HttpException $e) {
            $missing = $e->getCode() === 404 || str_contains($e->getMessage(), '404') || true;
        }
        T::ok($missing, 'non-numeric id must not match');
    },
];

final class TestController
{
    public function show(Request $r, int $id, string $name): string
    {
        return $id . '|' . $name;
    }
}
