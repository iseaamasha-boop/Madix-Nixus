<?php
declare(strict_types=1);

/*
 * المرحلة 3 (الحركة) والقاعدة 15: الحركة بـ transform وopacity فقط، وتقليل الحركة يلغيها.
 * يفحص كل @keyframes وكل transition في ملفات الأنماط: لا خاصية تخطيط (width، height، top، left، margin...) تتحرك.
 */

use Tests\Env;
use Tests\T;

$css = static function (): array {
    $files = array_merge([PUBLIC_PATH . '/assets/css/app.css'], glob(PUBLIC_PATH . '/assets/css/pages/*.css') ?: [],
        [PUBLIC_PATH . '/assets/css/landing.css', PUBLIC_PATH . '/assets/css/landing-classic.css']);
    $out = [];
    foreach ($files as $f) {
        $out[basename($f)] = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($f));
    }
    return $out;
};

return [
    'keyframes animate transform and opacity only' => static function (Env $env) use ($css): void {
        // حركات صفحة الهبوط القديمة: تُحوَّل في المرحلة 7 (البند 104) ثم تُحذف من هذه القائمة
        $landingLater = ['landing.css:lp-spin', 'landing-classic.css:lp-spin', 'landing-classic.css:lp-floor', 'landing-classic.css:lp-draw'];
        $allowed = ['transform', 'opacity', 'scale', 'translate', 'rotate', 'visibility'];
        $bad = [];
        foreach ($css() as $file => $s) {
            preg_match_all('/@keyframes\s+([\w-]+)\s*\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/', $s, $m, PREG_SET_ORDER);
            foreach ($m as [, $name, $body]) {
                preg_match_all('/([\w-]+)\s*:/', $body, $p);
                $props = array_diff(array_unique(array_map('strtolower', $p[1])), $allowed);
                if ($props && !in_array($file . ':' . $name, $landingLater, true)) {
                    $bad[] = $file . ' @keyframes ' . $name . ': ' . implode(', ', $props);
                }
            }
        }
        T::eq([], $bad, 'non transform/opacity keyframes');
    },
    'transitions never animate layout properties' => static function (Env $env) use ($css): void {
        $layout = '/\b(width|height|top|left|right|bottom|margin[\w-]*|padding[\w-]*|inset[\w-]*|max-height|max-width|min-height|min-width|font-size|grid-template[\w-]*|flex[\w-]*|border-width|all)\b/';
        $bad = [];
        foreach ($css() as $file => $s) {
            if (str_starts_with($file, 'landing')) {
                continue;
            }
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $s, $rules, PREG_SET_ORDER);
            foreach ($rules as [, $sel, $body]) {
                preg_match_all('/transition(?:-property)?\s*:\s*([^;]+)/', $body, $t);
                foreach ($t[1] as $v) {
                    if (preg_match($layout, $v)) {
                        $bad[] = $file . ' ' . trim($sel) . ' → ' . trim($v);
                    }
                }
            }
        }
        T::eq([], $bad, 'layout transitions');
    },
    'reduced motion turns the new motion off' => static function (Env $env) use ($css): void {
        $s = $css()['app.css'];
        T::contains('@media (prefers-reduced-motion: reduce) { .skeleton::after', $s, 'skeleton shimmer');
        T::contains('.ripple-wrap { display: none; }', $s, 'press ripple');
        T::ok(preg_match('/:root \{ --dur-1: \.01ms;[^}]*--dur-8: \.01ms; \}/', $s) === 1, 'all duration tokens zeroed under reduced motion');
        $js = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');
        T::contains("matchMedia('(prefers-reduced-motion: reduce)')", $js, 'counter and ripple check reduced motion');
        T::contains('scale: var(--p, 0%) 1', $s, 'progress bar uses scale');
    },
    'menu icons, empty states and staggered cards (64, 63, 83)' => static function (Env $env) use ($css): void {
        $s = $css()['app.css'];
        T::contains('.nav-link.is-active .icon { animation: nav-icon-in', $s, 'active menu icon pops');
        T::contains('animation: nav-bar-in', $s, 'active menu bar grows');
        T::contains('.nav-link.is-active .icon, .nav-link.is-active::before { animation: none; }', $s, 'menu motion off under reduced motion');
        T::contains('.empty:has(> .icon:first-child)::before {', $s, 'empty state illustration');
        T::contains("--empty-art: url(\"data:image/svg+xml,", $s, 'illustration is an inline SVG (no request)');
        T::contains('@media (prefers-reduced-motion: reduce) { .empty:has(> .icon:first-child)::before { animation: none; } }', $s, 'illustration still under reduced motion');
        T::contains('.mx.mx-wait { animation-play-state: paused; }', $s, 'cards below the fold wait on their first frame');
        $js = (string) file_get_contents(PUBLIC_PATH . '/assets/js/app.js');
        T::contains("el.classList.add('mx', 'mx-wait')", $js, 'below-fold cards are marked');
        T::contains("Math.min(n++, 5)", $js, 'stagger delay is capped');
    },
    'page transitions and card morph follow the design option (53, 81)' => static function (Env $env) use ($css): void {
        $s = $css()['app.css'];
        T::contains('@keyframes mnx-vt-in { from { opacity: 0; transform: translateY(6px); } }', $s, 'new page rises in');
        T::contains('::view-transition-group(mnx-morph) { animation: none; }', $s, 'no size animation on the morph group');
        T::contains('::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation: none !important; }', $s, 'transitions off under reduced motion');
        T::eq('', \App\Core\Theme::morph(['page_fx' => '0']), 'no morph script when the option is off');
        $js = \App\Core\Theme::morph(['page_fx' => '1']);
        T::contains('pageswap', $js, 'old page names the clicked card');
        T::contains('pagereveal', $js, 'new page names its header');
        T::contains('prefers-reduced-motion: reduce', $js, 'skipped under reduced motion');
        T::contains('a.stat[href]', $js, 'number cards morph');
        T::ok(!str_contains($js, 'scale('), 'morph moves with translate only');
        T::ok(!str_contains($js, '</') || substr_count($js, '</') === 1, 'one closing tag only');
        foreach (['app', 'admin'] as $l) {
            T::contains('Theme::morph() ?>', (string) file_get_contents(APP_PATH . '/Views/layouts/' . $l . '.php'), $l . ' layout includes the morph script');
        }
    },
];
