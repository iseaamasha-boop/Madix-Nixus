<?php
declare(strict_types=1);

/*
 * البند 72 (البحث العربي الذكي) والبند 69 (لوحة الأوامر): صور الحروف والتشكيل لا تمنع التطابق،
 * ونتائج لوحة الأوامر بصلاحيات الموظف ونطاق أجهزة MikroTik المسموحة له.
 */

use App\Support\ArabicSearch;
use Tests\Env;
use Tests\T;

$json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

return [
    'normalization and pattern cover letter forms, marks and digits' => static function (Env $env): void {
        T::eq(ArabicSearch::normalize('مُؤمن  أحمد الشريفة ٣'), ArabicSearch::normalize('مومن احمد الشريفه 3'));
        T::eq('مصطفي', ArabicSearch::normalize('مصطفى'));
        T::eq('محمد', ArabicSearch::normalize('محـمّد'));
        $re = ArabicSearch::regex('احمد (ابو) شعبان');
        T::contains('[اأإآٱ]', $re);
        T::contains('\\(', $re, 'regex characters are escaped');
        T::ok(@preg_match('/' . $re . '/u', 'أحمد (أبو) شعبان') === 1, 'pattern matches the written form in PHP too');
    },
    'subscriber list finds names whatever the letter forms' => static function (Env $env): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $id = (int) $pdo->query('SELECT MIN(id) FROM subscribers')->fetchColumn();
        $pdo->prepare('UPDATE subscribers SET full_name = ? WHERE id = ?')->execute(['مُؤمن إبراهيم الشريفة', $id]);
        $h = $env->owner('a');
        foreach (['مومن', 'ابراهيم', 'الشريفه', 'مؤمن ابراهيم', 'إبراهيم'] as $q) {
            $r = $h->get($t['path'] . '/subscribers?q=' . rawurlencode($q));
            T::status(200, $r);
            T::contains('/subscribers/' . $id . '"', $r['body'], 'found by «' . $q . '»');
        }
        $r = $h->get($t['path'] . '/subscribers?q=' . rawurlencode('غيرموجود'));
        T::notContains('/subscribers/' . $id . '"', $r['body'], 'no false match');
        $r = $h->get($t['path'] . '/subscribers?q=' . rawurlencode('(['));
        T::status(200, $r, 'regex characters in the query are safe');
    },
    'palette search: short JSON, quick actions by permission, scope of MikroTik devices' => static function (Env $env) use ($json): void {
        $t = $env->tenant('a');
        $pdo = $env->db($t['db']);
        $h = $env->owner('a');
        $r = $h->request('GET', $t['path'] . '/search?q=' . rawurlencode('مومن') . '&actions=1', null, $json);
        T::status(200, $r);
        T::ok(count($r['json']['items'] ?? []) >= 1, 'subscriber found');
        T::eq('subscriber', $r['json']['items'][0]['type'] ?? '');
        T::ok(in_array('مشترك جديد', array_column($r['json']['actions'] ?? [], 'title'), true), 'owner sees all actions');
        T::contains('no-store', (string) ($r['headers']['cache-control'] ?? ''), 'not stored by the browser');

        // موظف بصلاحية العرض فقط على جهاز MikroTik واحد: لا إجراء إضافة، ولا مشتركو الأجهزة الأخرى
        $routers = $pdo->query('SELECT id FROM routers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        $id = (int) $pdo->query('SELECT MIN(id) FROM subscribers')->fetchColumn();
        $pdo->prepare('UPDATE subscribers SET router_id = ? WHERE id = ?')->execute([(int) $routers[1], $id]);
        $env->employee('a', 'viewer72', 'ViewerPass123', ['subscribers.view'], [(int) $routers[0]]);
        $e = $env->owner('a', ['viewer72', 'ViewerPass123']);
        $r = $e->request('GET', $t['path'] . '/search?q=' . rawurlencode('مومن') . '&actions=1', null, $json);
        T::status(200, $r);
        T::eq([], array_column($r['json']['actions'] ?? [], 'title'), 'no actions without their permissions');
        T::notContains('/subscribers/' . $id, json_encode($r['json']['items'] ?? []), 'subscriber of another MikroTik device hidden');
        $page = $e->get($t['path'] . '/search?q=' . rawurlencode('مومن'));
        T::notContains('/subscribers/' . $id . '"', $page['body'], 'full search page respects the scope too');
    },
    'palette script loads on demand only' => static function (Env $env): void {
        $t = $env->tenant('a');
        $r = $env->owner('a')->get($t['path'] . '/subscribers');
        T::ok(preg_match('#<meta name="palette-src" content="[^"]+js/palette\.js\?v=[^"]+" data-search="[^"]+/search">#', $r['body']) === 1, 'meta with versioned script');
        T::ok(preg_match('#<script src="[^"]*palette\.js#', $r['body']) === 0, 'not loaded with the page');
    },
];
