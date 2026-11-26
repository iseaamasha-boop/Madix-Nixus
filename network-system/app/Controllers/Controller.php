<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
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

    /**
     * التعديل المباشر في الجدول (البند 79): حقل واحد معروف مسبقاً، بتحقق وصلاحية المسار، ويُسجَّل في سجل العمليات.
     * $fields: الحقل => [الاسم الظاهر، دالة تتحقق وتعيد القيمة المحفوظة (أو ترمي RuntimeException برسالة)، دالة العرض اختيارية]
     * $after: يُستدعى بعد الحفظ (مزامنة جهاز MikroTik مثلاً) بالحقل والقيمة
     */
    protected function inlineField(Request $request, string $table, array $row, array $fields, string $audit, string $entity, string $title, ?callable $after = null): Response
    {
        $field = $request->str('field');
        if (!isset($fields[$field])) {
            return json_response(['ok' => false, 'message' => 'هذا الحقل لا يُعدَّل من الجدول.'], 422);
        }
        [$label, $check] = $fields[$field];
        $show = $fields[$field][2] ?? static fn ($v) => (string) $v;
        try {
            $value = $check(is_scalar($request->input('value')) ? trim((string) $request->input('value')) : '');
        } catch (\RuntimeException $e) {
            return json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $old = $row[$field] ?? null;
        if ((string) $old !== (string) $value) {
            $data = [$field => $value];
            if (array_key_exists('updated_at', $row)) {
                $data['updated_at'] = now();
            }
            try {
                DB::update($table, $data, 'id = ?', [(int) $row['id']]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    return json_response(['ok' => false, 'message' => 'القيمة مستخدمة من قبل.'], 422);
                }
                throw $e;
            }
            Audit::log($audit, 'تعديل ' . $title . ': ' . $label . ' من «' . (string) $old . '» إلى «' . (string) $value . '»', $entity, (int) $row['id'], ['field' => $field, 'from' => $old, 'to' => $value]);
            if ($after) {
                $after($field, $value);
            }
        }
        return json_response(['ok' => true, 'message' => 'تم الحفظ.', 'value' => (string) $value, 'display' => $show($value)]);
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
