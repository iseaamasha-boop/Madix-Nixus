<?php
declare(strict_types=1);

/* المنصة: الحسابات المنفصلة، العزل بين الشبكات، لوحة المسؤول، صفحة الهبوط */

use Tests\Env;
use Tests\T;

return [
    'landing page and admin login answer' => static function (Env $env): void {
        $h = $env->http();
        $r = $h->get('/');
        T::status(200, $r);
        T::cleanHtml($r);
        T::contains('<title>', $r['body']);
        T::status(200, $h->get('/admin/login'));
        T::status(200, $h->get('/health'));
    },
    'admin panel opens after login' => static function (Env $env): void {
        $h = $env->admin();
        $r = $h->get('/admin');
        T::status(200, $r);
        T::cleanHtml($r);
    },
    'tenant owner logs in and sees the dashboard' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $r = $h->get($t['path'] . '/');
        T::status(200, $r);
        T::cleanHtml($r);
        T::contains('شبكة a', $r['body']);
    },
    'wrong password is rejected' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->http();
        $r = $h->login($t['path'] . '/login', Env::OWNER[0], 'wrong-password');
        T::ok(str_contains($r['location'], '/login'), 'should go back to login');
        T::status(302, $h->get($t['path'] . '/'));
    },
    'session of one network never opens another network' => static function (Env $env): void {
        $a = $env->tenant('a');
        $b = $env->tenant('b', 5, 20);
        $h = $env->owner('a');
        // نفس اسم المستخدم وكلمة المرور في الشبكتين: جلسة الشبكة الأولى لا تُستخدم في الثانية
        $r = $h->get($b['path'] . '/');
        T::status(302, $r, 'network b must require its own login');
        T::contains('/login', $r['location']);
        $r = $h->ajax('GET', $b['path'] . '/dashboard/data');
        T::status([401, 302], $r, 'json data of b');
        T::status(200, $h->get($a['path'] . '/'));
    },
    'data stays in its own database' => static function (Env $env): void {
        $a = $env->tenant('a');
        $b = $env->tenant('b', 5, 20);
        $na = (int) $env->db($a['db'])->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
        $nb = (int) $env->db($b['db'])->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
        T::eq(40, $na, 'subscribers in a');
        T::eq(5, $nb, 'subscribers in b');
        $hb = $env->owner('b');
        $r = $hb->get($b['path'] . '/subscribers');
        T::status(200, $r);
        // لا يظهر أي مشترك من الشبكة الأولى في الثانية
        $nameA = (string) $env->db($a['db'])->query("SELECT full_name FROM subscribers ORDER BY id DESC LIMIT 1")->fetchColumn();
        $inB = (int) $env->db($b['db'])->query('SELECT COUNT(*) FROM subscribers WHERE full_name = ' . $env->db()->quote($nameA))->fetchColumn();
        if ($inB === 0) {
            T::notContains(e($nameA), $r['body'], 'name from network a shown in b');
        }
    },
    'unknown network answers 404' => static function (Env $env): void {
        T::status(404, $env->http()->get('/no-such-net-' . $env->run . '/login'));
    },
];
