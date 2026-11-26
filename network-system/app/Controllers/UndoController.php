<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Undo;

/** زر «تراجع» بعد الحذف (البند 71) */
final class UndoController extends Controller
{
    public function restore(Request $request, string $token): Response
    {
        $r = Undo::restore($token);
        if ($request->wantsJson()) {
            return Response::json($r, $r['ok'] ? 200 : 422);
        }
        flash($r['ok'] ? 'success' : 'error', $r['message']);
        return back('/');
    }
}
