<?php
declare(strict_types=1);

/*
 * المرحلة 7 (صفحة الهبوط).
 * 104: التصميم الكلاسيكي هو الافتراضي (طلب صاحب المنصة)، وفيه قصة بالتمرير: فصول نصية تُبنى في الخادم (ظاهرة بلا JavaScript
 * ولمحركات البحث) وشاشة لكل فصل، وتُعدَّل الفصول من لوحة المسؤول ويُخفى القسم منها، والتصميم الآخر يبقى كما هو.
 */

use Tests\Env;
use Tests\T;

/** حفظ صفحة الهبوط من لوحة المسؤول كما يرسلها النموذج */
$save = static function (Env $env, array $data): array {
    $h = $env->admin();
    $h->get('/admin/landing');
    return $h->post('/admin/landing', $data + ['enabled' => '1', 'show_story' => '1', 'show_features' => '1', 'show_stats' => '1']);
};

return [
    'classic landing is the default, with the scroll story built on the server (104)' => static function (Env $env): void {
        $env->db()->exec("DELETE FROM platform_settings WHERE `key` LIKE 'landing.%' OR `key` LIKE 'landing_en.%'");
        $r = $env->http()->get('/');
        T::status(200, $r);
        T::cleanHtml($r);
        T::contains('css/landing-classic.css', $r['body'], 'classic design by default');
        T::contains('id="story" data-lp-story', $r['body'], 'story section');
        T::contains('<h2>شبكتك كلها أمامك</h2>', $r['body'], 'story title in the HTML');
        T::eq(6, substr_count($r['body'], 'data-lp-chapter="'), 'six chapters');
        T::eq(6, substr_count($r['body'], 'data-scr="'), 'a screen for each chapter');
        T::contains('<h3>لوحة تحكم حيّة</h3>', $r['body'], 'chapter text rendered on the server');
        T::contains('class="lp-scr is-on" data-scr="0"', $r['body'], 'first screen shown without JavaScript');
        T::contains('href="#story"', $r['body'], 'menu link to the story');
        T::contains('aria-hidden="true"', substr($r['body'], strpos($r['body'], 'lp-story-stage'), 80), 'mock screens hidden from screen readers (text is in the chapters)');
    },
    'story chapters and section titles are edited from the admin panel, section hidden, other design unchanged (104)' => static function (Env $env) use ($save): void {
        $r = $save($env, [
            'style' => 'classic',
            'story_title' => 'جولة سريعة',
            'story_items_title' => ['الفصل الأول', 'الفصل الثاني'],
            'story_items_text' => ['نص أول', 'نص ثانٍ'],
            // عنوان قسم المميزات مع عناصره: كان العنوان يضيع لأن عناصر القائمة تحمل الاسم نفسه (features_title[])
            'features_title' => 'مميزات المنصة',
            'features_items_title' => ['ميزة واحدة'],
            'features_items_text' => ['وصفها'],
        ]);
        T::status(302, $r, 'saved');
        $page = $env->http()->get('/')['body'];
        T::contains('<h2>جولة سريعة</h2>', $page, 'story title from the admin panel');
        T::eq(2, substr_count($page, 'data-lp-chapter="'), 'chapters from the admin panel');
        T::eq(2, substr_count($page, 'data-scr="'), 'one screen per chapter');
        T::contains('<h3>الفصل الثاني</h3>', $page, 'edited chapter');
        T::contains('<h2>مميزات المنصة</h2>', $page, 'section title kept beside its list');
        T::contains('<h3>ميزة واحدة</h3>', $page, 'its list kept too');
        $form = $env->admin()->get('/admin/landing')['body'];
        T::contains('name="story_items_title[]" value="الفصل الأول"', $form, 'list rows in the admin form');
        T::contains('name="story_title" value="جولة سريعة"', $form, 'section title in the admin form');
        // إخفاء القسم من لوحة المسؤول
        $h = $env->admin();
        $h->get('/admin/landing');
        T::status(302, $h->post('/admin/landing', ['style' => 'classic', 'enabled' => '1']), 'saved without the story section');
        $page = $env->http()->get('/')['body'];
        T::notContains('data-lp-story', $page, 'story hidden');
        T::notContains('href="#story"', $page, 'menu link hidden with it');
        // التصميم الآخر كما هو
        T::status(302, $save($env, ['style' => 'pastel']), 'pastel chosen');
        $page = $env->http()->get('/')['body'];
        T::contains('css/landing.css', $page, 'pastel design when chosen');
        T::notContains('data-lp-story', $page, 'pastel unchanged');
        $env->db()->exec("DELETE FROM platform_settings WHERE `key` LIKE 'landing.%' OR `key` LIKE 'landing_en.%'");
    },
];
