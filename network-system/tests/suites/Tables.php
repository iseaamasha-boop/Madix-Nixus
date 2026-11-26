<?php
declare(strict_types=1);

/*
 * البند 76: ترتيب الجداول بالنقر على رأس العمود في الخادم (على كل النتائج)، بأعمدة معروفة فقط،
 * ويبقى الترتيب مع الصفحات والتصفية وتبديل الحالة.
 */

use Tests\Env;
use Tests\T;

$names = static function (string $html): array {
    preg_match_all('#<a class="cell-title" href="[^"]+/subscribers/\d+">([^<]+)</a>#u', $html, $m);
    return array_map('html_entity_decode', $m[1]);
};

return [
    'subscribers sort by name, balance and expiry on the server' => static function (Env $env) use ($names): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $r = $h->get($t['path'] . '/subscribers?sort=name&dir=asc&per=25');
        T::status(200, $r);
        $list = $names($r['body']);
        T::ok(count($list) >= 10, 'rows listed');
        $pdo = $env->db($t['db']);
        $first = $pdo->query('SELECT full_name FROM subscribers ORDER BY full_name ASC, id DESC LIMIT 1')->fetchColumn();
        T::eq($first, $list[0] ?? null, 'first row is the first name of all subscribers, not of the page');
        T::contains('aria-sort="ascending"', $r['body']);
        $desc = $names($h->get($t['path'] . '/subscribers?sort=name&dir=desc&per=25')['body']);
        T::eq($pdo->query('SELECT full_name FROM subscribers ORDER BY full_name DESC, id DESC LIMIT 1')->fetchColumn(), $desc[0] ?? null, 'descending');
        $bal = $h->get($t['path'] . '/subscribers?sort=balance&dir=desc');
        T::status(200, $bal);
        T::contains('aria-sort="descending"', $bal['body']);
    },
    'unknown sort keys are ignored and the sort stays with pages, filters and tabs' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $bad = $h->get($t['path'] . '/subscribers?sort=' . rawurlencode('full_name;DROP TABLE x') . '&dir=up');
        T::status(200, $bad);
        T::notContains('aria-sort="ascending"', $bad['body']);
        $r = $h->get($t['path'] . '/subscribers?sort=name&dir=asc&per=25');
        T::ok(preg_match('#class="pager-link[^"]*" href="[^"]*sort=name[^"]*dir=asc#', $r['body']) === 1, 'page links keep the sort');
        T::ok(preg_match('#<input type="hidden" name="sort" value="name">#', $r['body']) === 1, 'filter form keeps the sort');
        T::ok(preg_match('#class="tab[^"]*" href="[^"]*sort=name#', $r['body']) === 1, 'state tabs keep the sort');
    },
];
