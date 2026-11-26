<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Throttle;
use App\Platform\Demo;

/**
 * البند 105: دخول الشبكة التجريبية بلا تسجيل. يبدأ زيارة (البند 105+) ثم يُدخل الزائر بحساب التجربة دون كلمة مرور.
 */
final class DemoController extends Controller
{
    public function start(Request $request): Response
    {
        if (!Demo::active()) {
            throw new HttpException(404);
        }
        if (!Throttle::rate('demo-start:' . $request->ip(), 30, 3600)) {
            return new Response('', 429, ['Retry-After' => '600']);
        }
        Demo::startVisit($request);
        if (!Auth::check()) {
            $emp = DB::row("SELECT * FROM employees WHERE is_owner = 1 AND status = 'active' ORDER BY id LIMIT 1");
            if (!$emp) {
                throw new HttpException(503);
            }
            // دخول صامت: لا تنبيه دخول ولا سجل عمليات لكل زائر
            Auth::loginAs($emp, $request->ip(), true);
        }
        return redirect('/');
    }
}
