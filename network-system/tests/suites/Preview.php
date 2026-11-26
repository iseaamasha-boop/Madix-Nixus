<?php
declare(strict_types=1);

/*
 * البند 73: المعاينة السريعة. بيانات مختصرة بصلاحية العرض نفسها ونطاق أجهزة MikroTik نفسه.
 */

use Tests\Env;
use Tests\T;

$json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

return [
    'subscriber and MikroTik previews for the owner' => static function (Env $env) use ($json): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $sid = (int) $pdo->query('SELECT MIN(id) FROM subscribers')->fetchColumn();
        $r = $h->request('GET', $t['path'] . '/subscribers/' . $sid . '/preview', null, $json);
        T::status(200, $r);
        T::eq($pdo->query('SELECT full_name FROM subscribers WHERE id = ' . $sid)->fetchColumn(), $r['json']['title'] ?? null);
        T::ok(count($r['json']['rows'] ?? []) >= 1 && count($r['json']['badges'] ?? []) >= 1, 'details and state');
        T::contains('no-store', (string) ($r['headers']['cache-control'] ?? ''));
        $rid = (int) $pdo->query('SELECT MIN(id) FROM routers')->fetchColumn();
        $r = $h->request('GET', $t['path'] . '/routers/' . $rid . '/preview', null, $json);
        T::status(200, $r);
        T::eq($pdo->query('SELECT name FROM routers WHERE id = ' . $rid)->fetchColumn(), $r['json']['title'] ?? null);
    },
    'permissions and MikroTik scope apply to previews' => static function (Env $env) use ($json): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $routers = array_map('intval', $pdo->query('SELECT id FROM routers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        $sid = (int) $pdo->query('SELECT MAX(id) FROM subscribers')->fetchColumn();
        $pdo->prepare('UPDATE subscribers SET router_id = ? WHERE id = ?')->execute([$routers[1], $sid]);
        $env->employee('a', 'scope73', 'ScopePass12345', ['subscribers.view', 'routers.view'], [$routers[0]]);
        $e = $env->owner('a', ['scope73', 'ScopePass12345']);
        T::status(403, $e->request('GET', $t['path'] . '/subscribers/' . $sid . '/preview', null, $json), 'subscriber on another MikroTik device');
        T::status(403, $e->request('GET', $t['path'] . '/routers/' . $routers[1] . '/preview', null, $json), 'another MikroTik device');
        T::status(200, $e->request('GET', $t['path'] . '/routers/' . $routers[0] . '/preview', null, $json), 'own MikroTik device');
        $env->employee('a', 'cards73', 'CardsPass12345', ['cards.view']);
        $c = $env->owner('a', ['cards73', 'CardsPass12345']);
        T::status(403, $c->request('GET', $t['path'] . '/subscribers/' . $sid . '/preview', null, $json), 'no subscribers.view');
    },
];
