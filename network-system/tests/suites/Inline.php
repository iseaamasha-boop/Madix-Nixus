<?php
declare(strict_types=1);

/*
 * البند 79: التعديل المباشر في الجدول. حقول معروفة فقط، بتحقق، بصلاحية الإدارة ونطاق أجهزة MikroTik،
 * وكل تعديل في سجل العمليات.
 */

use Tests\Env;
use Tests\T;

$post = static function (\Tests\Http $h, string $path, array $data): array {
    return $h->request('POST', $path, $data, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'X-Instant' => '1', 'Content-Type' => 'application/json', 'X-CSRF-Token' => $h->csrf]);
};

return [
    'plan price and area name are saved, shown formatted and logged' => static function (Env $env) use ($post): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $h->get($t['path'] . '/plans');
        $plan = (int) $pdo->query('SELECT MIN(id) FROM plans')->fetchColumn();
        $r = $post($h, $t['path'] . '/plans/' . $plan . '/inline', ['field' => 'price', 'value' => '٤٥']);
        T::status(200, $r);
        T::eq('45.00', $r['json']['value'] ?? null, 'Arabic digits accepted');
        T::contains('45', (string) ($r['json']['display'] ?? ''));
        T::eq('45.00', (string) $pdo->query('SELECT price FROM plans WHERE id = ' . $plan)->fetchColumn());
        T::eq(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'plan.update' AND entity_id = " . $plan)->fetchColumn(), 'in the audit log');
        $pdo->exec("INSERT INTO areas (name, created_at) VALUES ('منطقة أ79', NOW()), ('منطقة ب79', NOW())");
        $a = (int) $pdo->query("SELECT id FROM areas WHERE name = 'منطقة أ79'")->fetchColumn();
        T::status(200, $post($h, $t['path'] . '/areas/' . $a . '/inline', ['field' => 'name', 'value' => 'منطقة ج79']));
        T::eq('منطقة ج79', $pdo->query('SELECT name FROM areas WHERE id = ' . $a)->fetchColumn());
        $dup = $post($h, $t['path'] . '/areas/' . $a . '/inline', ['field' => 'name', 'value' => 'منطقة ب79']);
        T::status(422, $dup, 'duplicate name refused');
        T::eq('منطقة ج79', $pdo->query('SELECT name FROM areas WHERE id = ' . $a)->fetchColumn(), 'unchanged');
    },
    'unknown fields and bad values are refused' => static function (Env $env) use ($post): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $h->get($t['path'] . '/plans');
        $plan = (int) $pdo->query('SELECT MIN(id) FROM plans')->fetchColumn();
        T::status(422, $post($h, $t['path'] . '/plans/' . $plan . '/inline', ['field' => 'is_active', 'value' => '0']), 'field not editable from the table');
        T::status(422, $post($h, $t['path'] . '/plans/' . $plan . '/inline', ['field' => 'price', 'value' => '-5']), 'negative price');
        $sid = (int) $pdo->query('SELECT MIN(id) FROM subscribers')->fetchColumn();
        T::status(422, $post($h, $t['path'] . '/subscribers/' . $sid . '/inline', ['field' => 'phone', 'value' => 'abc']), 'bad phone');
        T::status(200, $post($h, $t['path'] . '/subscribers/' . $sid . '/inline', ['field' => 'phone', 'value' => '٠٥٩ ١٢٣ ٤٥٦٧']));
        T::eq('0591234567', $pdo->query('SELECT phone FROM subscribers WHERE id = ' . $sid)->fetchColumn(), 'normalised');
    },
    'drag and drop order: saved, validated, permitted, logged (item 80)' => static function (Env $env) use ($post): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $h->get($t['path'] . '/plans');
        $ids = array_map('intval', $pdo->query('SELECT id FROM plans ORDER BY sort, price, name')->fetchAll(PDO::FETCH_COLUMN));
        T::ok(count($ids) >= 2, 'plans to order');
        $new = array_reverse($ids);
        $r = $post($h, $t['path'] . '/plans/order', ['ids' => $new]);
        T::status(200, $r);
        T::eq($new, array_map('intval', $pdo->query('SELECT id FROM plans ORDER BY sort, price, name')->fetchAll(PDO::FETCH_COLUMN)), 'new order stored');
        T::ok((int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'plan.order'")->fetchColumn() >= 1, 'in the audit log');
        T::status(422, $post($h, $t['path'] . '/plans/order', ['ids' => [999999, $ids[0]]]), 'unknown id refused');
        T::status(422, $post($h, $t['path'] . '/plans/order', ['ids' => []]), 'empty refused');
        $custom = $pdo->query('SELECT id FROM categories WHERE is_custom = 1 LIMIT 1')->fetchColumn();
        if ($custom) {
            $normal = (int) $pdo->query('SELECT id FROM categories WHERE is_custom = 0 LIMIT 1')->fetchColumn();
            T::status(422, $post($h, $t['path'] . '/categories/order', ['ids' => [$normal, (int) $custom]]), 'custom card package cannot be ordered');
        }
        $env->employee('a', 'order80', 'OrderPass12345', ['plans.view']);
        $v = $env->owner('a', ['order80', 'OrderPass12345']);
        $page = $v->get($t['path'] . '/plans');
        T::notContains('class="drag-handle"', $page['body'], 'no drag handles without permission');
        T::notContains('<tbody data-sortable', $page['body'], 'list not sortable without permission');
        T::status(403, $post($v, $t['path'] . '/plans/order', ['ids' => $ids]), 'no permission');
        $post($h, $t['path'] . '/plans/order', ['ids' => $ids]);
    },
    'permissions and MikroTik scope apply' => static function (Env $env) use ($post): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $routers = array_map('intval', $pdo->query('SELECT id FROM routers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        $sid = (int) $pdo->query('SELECT MAX(id) FROM subscribers')->fetchColumn();
        $pdo->prepare('UPDATE subscribers SET router_id = ? WHERE id = ?')->execute([$routers[1], $sid]);
        $env->employee('a', 'edit79', 'EditPass12345', ['subscribers.view', 'subscribers.manage'], [$routers[0]]);
        $e = $env->owner('a', ['edit79', 'EditPass12345']);
        $e->get($t['path'] . '/subscribers');
        T::status(403, $post($e, $t['path'] . '/subscribers/' . $sid . '/inline', ['field' => 'phone', 'value' => '0599999999']), 'subscriber of another MikroTik device');
        $env->employee('a', 'view79', 'ViewPass12345', ['subscribers.view', 'plans.view']);
        $v = $env->owner('a', ['view79', 'ViewPass12345']);
        $v->get($t['path'] . '/plans');
        $plan = (int) $pdo->query('SELECT MIN(id) FROM plans')->fetchColumn();
        T::status(403, $post($v, $t['path'] . '/plans/' . $plan . '/inline', ['field' => 'price', 'value' => '1']), 'view only');
        $page = $v->get($t['path'] . '/plans');
        T::notContains('data-edit=', $page['body'], 'no editable cells without permission');
    },
];
