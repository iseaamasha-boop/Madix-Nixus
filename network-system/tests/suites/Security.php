<?php
declare(strict_types=1);

/* الأمان: CSRF، ترويسات الحماية، ملفات تعريف الارتباط، الخروج */

use Tests\Env;
use Tests\T;

return [
    'POST without CSRF token is refused' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $r = $h->request('POST', $t['path'] . '/account/theme', 'theme=dark', ['Content-Type' => 'application/x-www-form-urlencoded']);
        T::status(419, $r);
        $r = $h->request('POST', $t['path'] . '/account/theme', ['theme' => 'dark', '_token' => str_repeat('0', 64)], ['Content-Type' => 'application/x-www-form-urlencoded']);
        T::status(419, $r);
    },
    'protection headers on pages' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $r = $h->get($t['path'] . '/');
        T::eq('nosniff', $r['headers']['x-content-type-options'] ?? '');
        T::eq('SAMEORIGIN', $r['headers']['x-frame-options'] ?? '');
        T::contains('private', $r['headers']['cache-control'] ?? '', 'private pages are never cached by proxies');
    },
    'session cookie is HttpOnly, SameSite and scoped to the network path' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->http();
        $r = $h->get($t['path'] . '/login');
        $cookie = (string) ($r['headers']['set-cookie'] ?? '');
        T::contains('HttpOnly', $cookie);
        T::contains('SameSite=Lax', $cookie);
        T::contains('path=' . $t['path'] . '/', strtolower($cookie));
    },
    'logout ends the session and protected data is gone' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        T::status(200, $h->get($t['path'] . '/subscribers'));
        $r = $h->post($t['path'] . '/logout');
        T::status(302, $r);
        $r = $h->get($t['path'] . '/subscribers');
        T::status(302, $r);
        T::contains('/login', $r['location']);
        T::status(401, $h->ajax('GET', $t['path'] . '/dashboard/data'));
    },
    'no secret or key appears in page HTML' => static function (Env $env): void {
        $t = $env->tenant('a');
        $h = $env->owner('a');
        $cfg = require $env->config;
        $r = $h->get($t['path'] . '/settings');
        T::notContains((string) $cfg['db']['pass'], $r['body'], 'db password');
        T::notContains((string) $cfg['app']['key'], $r['body'], 'app key');
    },
];
