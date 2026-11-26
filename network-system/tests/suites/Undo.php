<?php
declare(strict_types=1);

/*
 * البند 71: «تراجع» بدل «هل أنت متأكد؟». الحذف فوري ويعيده التراجع كما كان (بمعرّفاته وتوابعه وما انفصل عنه)،
 * لصاحب الحذف وبصلاحيته فقط، مرة واحدة وخلال مدة محددة، ويُسجَّل في سجل العمليات.
 */

use Tests\Env;
use Tests\T;

/** حذف ثم قراءة رمز التراجع من رسالة الصفحة التالية */
$deleteAndToken = static function (\Tests\Http $h, string $path, string $back): string {
    $h->get($back);
    $r = $h->post($path);
    T::status(302, $r, 'delete redirects');
    $page = $h->get($r['location']);
    T::ok(preg_match('#<script type="application/json" id="flash-data">(.*?)</script>#s', $page['body'], $m) === 1, 'flash data');
    $flash = json_decode($m[1], true) ?: [];
    $token = '';
    foreach ($flash as $f) {
        $token = (string) ($f['undo'] ?? $token);
    }
    T::ok(preg_match('/^[a-f0-9]{32}$/', $token) === 1, 'undo token in the success message');
    return $token;
};
$undo = static function (\Tests\Http $h, string $base, string $token): array {
    return $h->request('POST', $base . '/undo/' . $token, [], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'Content-Type' => 'application/json', 'X-CSRF-Token' => $h->csrf]);
};

return [
    'expense: deleted at once, undo brings it back with the same id and logs it' => static function (Env $env) use ($deleteAndToken, $undo): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $pdo->exec("INSERT INTO expenses (category, amount, spent_on, description, created_at) VALUES ('other', 42.5, CURDATE(), 'اختبار التراجع', NOW())");
        $id = (int) $pdo->lastInsertId();
        $h = $env->owner('a');
        $token = $deleteAndToken($h, $t['path'] . '/expenses/' . $id . '/delete', $t['path'] . '/expenses');
        T::eq(0, (int) $pdo->query('SELECT COUNT(*) FROM expenses WHERE id = ' . $id)->fetchColumn(), 'deleted');
        $r = $undo($h, $t['path'], $token);
        T::status(200, $r);
        T::eq(true, $r['json']['ok'] ?? null);
        $row = $pdo->query('SELECT amount, description FROM expenses WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        T::eq('اختبار التراجع', $row['description'] ?? null, 'same row, same id');
        T::eq(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'expense.undo'")->fetchColumn(), 'undo in the audit log');
        $again = $undo($h, $t['path'], $token);
        T::status(422, $again, 'only once');
        T::eq(1, (int) $pdo->query('SELECT COUNT(*) FROM expenses WHERE id = ' . $id)->fetchColumn(), 'not inserted twice');
    },
    'area: links set to empty by the delete are restored' => static function (Env $env) use ($deleteAndToken, $undo): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $pdo->exec("INSERT INTO areas (name, created_at) VALUES ('منطقة التراجع', NOW())");
        $area = (int) $pdo->lastInsertId();
        $subs = $pdo->query('SELECT id FROM subscribers ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('UPDATE subscribers SET area_id = ' . $area . ' WHERE id IN (' . implode(',', $subs) . ')');
        $h = $env->owner('a');
        $token = $deleteAndToken($h, $t['path'] . '/areas/' . $area . '/delete', $t['path'] . '/areas');
        T::eq(0, (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE area_id = ' . $area)->fetchColumn(), 'unlinked by the delete');
        T::status(200, $undo($h, $t['path'], $token));
        T::eq(3, (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE area_id = ' . $area)->fetchColumn(), 'subscribers back in the area');
    },
    'subscriber: payments, login name and MikroTik sync come back' => static function (Env $env) use ($deleteAndToken, $undo): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $id = (int) $pdo->query('SELECT MAX(id) FROM subscribers WHERE router_id IS NOT NULL')->fetchColumn();
        $tx = (int) $pdo->query('SELECT COUNT(*) FROM subscriber_transactions WHERE subscriber_id = ' . $id)->fetchColumn();
        $login = (int) $pdo->query("SELECT COUNT(*) FROM logins WHERE owner_type = 'subscriber' AND owner_id = " . $id)->fetchColumn();
        $h = $env->owner('a');
        $page = $h->get($t['path'] . '/subscribers/' . $id);
        T::ok(preg_match('#action="[^"]+/subscribers/' . $id . '/delete"[^>]*data-confirm#', $page['body']) === 0, 'no "are you sure" before deleting');
        $token = $deleteAndToken($h, $t['path'] . '/subscribers/' . $id . '/delete', $t['path'] . '/subscribers/' . $id);
        T::eq(0, (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE id = ' . $id)->fetchColumn(), 'deleted');
        T::status(200, $undo($h, $t['path'], $token));
        T::eq(1, (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE id = ' . $id)->fetchColumn(), 'subscriber back');
        T::eq($tx, (int) $pdo->query('SELECT COUNT(*) FROM subscriber_transactions WHERE subscriber_id = ' . $id)->fetchColumn(), 'payments back');
        T::eq($login, (int) $pdo->query("SELECT COUNT(*) FROM logins WHERE owner_type = 'subscriber' AND owner_id = " . $id)->fetchColumn(), 'login name reserved again');
        T::ok((int) $pdo->query("SELECT COUNT(*) FROM router_tasks WHERE target = 'sub:" . $id . "' AND action = 'sub_provision'")->fetchColumn() >= 1
            || (int) $pdo->query("SELECT COUNT(*) FROM router_tasks WHERE target = 'sub:" . $id . "'")->fetchColumn() >= 1, 'sent back to the MikroTik device');
    },
    'instant forms get JSON: message, undo token, errors as failures' => static function (Env $env) use ($undo): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $pdo->exec("INSERT INTO areas (name, created_at) VALUES ('منطقة فورية', NOW())");
        $area = (int) $pdo->lastInsertId();
        $h = $env->owner('a');
        $h->get($t['path'] . '/areas');
        $hdr = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'X-Instant' => '1', 'Content-Type' => 'application/x-www-form-urlencoded'];
        $r = $h->request('POST', $t['path'] . '/areas/' . $area . '/delete', http_build_query(['_token' => $h->csrf]), $hdr);
        T::status(200, $r);
        T::eq(true, $r['json']['ok'] ?? null);
        T::ok(preg_match('/^[a-f0-9]{32}$/', (string) ($r['json']['undo'] ?? '')) === 1, 'undo token in the JSON answer');
        T::status(200, $undo($h, $t['path'], (string) $r['json']['undo']));
        T::eq(1, (int) $pdo->query('SELECT COUNT(*) FROM areas WHERE id = ' . $area)->fetchColumn(), 'restored');
        // خطأ (قالب الطباعة الوحيد مثلاً) يصل فشلاً لا تحويلاً
        $only = $pdo->query('SELECT COUNT(*) FROM print_templates')->fetchColumn();
        if ((int) $only === 1) {
            $id = (int) $pdo->query('SELECT id FROM print_templates LIMIT 1')->fetchColumn();
            $e = $h->request('POST', $t['path'] . '/print/templates/' . $id . '/delete', http_build_query(['_token' => $h->csrf]), $hdr);
            T::status(422, $e, 'refused action answers as a failure');
            T::eq(false, $e['json']['ok'] ?? null);
        }
    },
    'only the same employee with the same permission, within the time limit' => static function (Env $env) use ($deleteAndToken, $undo): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $pdo->exec("INSERT INTO expenses (category, amount, spent_on, description, created_at) VALUES ('other', 7, CURDATE(), 'صلاحية التراجع', NOW())");
        $id = (int) $pdo->lastInsertId();
        $owner = $env->owner('a');
        $token = $deleteAndToken($owner, $t['path'] . '/expenses/' . $id . '/delete', $t['path'] . '/expenses');
        $env->employee('a', 'undo71', 'UndoPass12345', ['expenses.view', 'expenses.manage']);
        $e = $env->owner('a', ['undo71', 'UndoPass12345']);
        $e->get($t['path'] . '/expenses');
        $r = $undo($e, $t['path'], $token);
        T::status(422, $r, 'another employee cannot undo');
        T::eq(0, (int) $pdo->query('SELECT COUNT(*) FROM expenses WHERE id = ' . $id)->fetchColumn());
        $pdo->prepare('UPDATE undo_actions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token = ?')->execute([$token]);
        T::status(422, $undo($owner, $t['path'], $token), 'expired');
        T::eq(0, (int) $pdo->query('SELECT COUNT(*) FROM expenses WHERE id = ' . $id)->fetchColumn(), 'still deleted');
    },
];
