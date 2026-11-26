<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

abstract class Controller
{
    /**
     * تحقق من المدخلات؛ عند الفشل يعيد المستخدم إلى النموذج مع الأخطاء.
     * @throws ValidationFailed
     */
    protected function validate(Request $request, array $rules, array $labels = [], ?callable $extra = null): array
    {
        $v = Validator::make($request->all(), $rules, $labels);
        if ($extra) {
            $extra($v);
        }
        if ($v->fails()) {
            throw new ValidationFailed($v, $request);
        }
        return $v->validated();
    }

    /**
     * التحقق من حقول إعدادات [مفتاح => [قاعدة، تسمية]] وإرجاع القيم الجاهزة للحفظ.
     * الحقل في النموذج يُسمّى بالمفتاح بعد استبدال النقطة بـ __، وقاعدة bool لمفاتيح التشغيل.
     */
    protected function settingsInput(Request $request, array $fields, ?callable $extra = null): array
    {
        $rules = [];
        $labels = [];
        $input = [];
        foreach ($fields as $key => [$rule, $label]) {
            $form = str_replace('.', '__', $key);
            $labels[$form] = $label;
            if ($rule === 'bool') {
                $input[$key] = $request->bool($form) ? '1' : '0';
                continue;
            }
            $rules[$form] = $rule;
        }
        $data = $this->validate($request, $rules, $labels, $extra);
        foreach ($rules as $form => $_) {
            $val = $data[$form];
            $input[str_replace('__', '.', $form)] = $val === null ? '' : (string) $val;
        }
        return $input;
    }

    protected function backWithErrors(Request $request, array $errors, string $fallback = '/'): Response
    {
        if ($request->wantsJson()) {
            return json_response(['ok' => false, 'message' => (string) reset($errors), 'errors' => $errors], 422);
        }
        Session::flashInput($request->all(), $errors);
        flash('error', (string) reset($errors));
        return back($fallback);
    }

    protected function notFoundUnless(mixed $value): void
    {
        if (!$value) {
            throw new HttpException(404);
        }
    }

    protected function ok(string $message, string $redirect, array $query = []): Response
    {
        flash('success', $message);
        return redirect($redirect, $query);
    }

    /**
     * حذف قابل للتراجع: يلتقط الصفوف (وما يُحذف معها) ثم ينفّذ الحذف، ويعيد رسالة بزر «تراجع».
     * إن كانت اللقطة أكبر من الحد يُنفَّذ الحذف كما هو دون تراجع.
     * $delete تنفّذ الحذف وتسجّله في سجل العمليات.
     */
    protected function deleteWithUndo(string $table, int $id, string $action, string $label, ?string $perm, callable $delete, string $message, string $redirect, array $query = [], ?string $after = null, array $extra = []): Response
    {
        $token = $this->undoable($table, $id, $action, $label, $perm, $delete, $after, $extra);
        if ($token !== null) {
            flash_undo($message, $token);
            return redirect($redirect, $query);
        }
        return $this->ok($message, $redirect, $query);
    }

    /** الحذف مع لقطة التراجع؛ يعيد رمز التراجع (null إن تعذّرت اللقطة، والحذف يتم على كل حال) */
    protected function undoable(string $table, int $id, string $action, string $label, ?string $perm, callable $delete, ?string $after = null, array $extra = []): ?string
    {
        try {
            $snap = \App\Services\Undo::capture($table, [$id], $extra);
        } catch (\Throwable) {
            $snap = null;
        }
        $delete();
        if ($snap === null || !$snap['rows']) {
            return null;
        }
        try {
            return \App\Services\Undo::record($action, $label, $perm, $snap, $after, $id);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::report($e);
            return null;
        }
    }
}
