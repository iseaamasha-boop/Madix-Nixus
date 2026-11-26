<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Theme;
use App\Platform\Landing;

/**
 * التصاميم والحركة لكل الأنظمة، ومحتوى صفحة الهبوط.
 */
final class DesignController extends Controller
{
    public function index(Request $request): Response
    {
        return view('admin/design', ['t' => Theme::current()], 'admin');
    }

    /** القيم المرسلة من نموذج التصاميم أو من رابط المعاينة */
    private function values(Request $request): array
    {
        $in = [];
        foreach (array_keys(Theme::CHOICES) as $k) {
            $in[$k] = $request->str($k);
        }
        $in['stagger'] = (string) $request->int('stagger', 20);
        $in['speed'] = (string) $request->int('speed', 200);
        $in['page_fx'] = $request->bool('page_fx') ? '1' : '0';
        $in['haptics'] = $request->bool('haptics') ? '1' : '0';
        $in['oled'] = $request->bool('oled') ? '1' : '0';
        $in['glass'] = $request->bool('glass') ? '1' : '0';
        return Theme::sanitize($in);
    }

    /** البند 108: ترتيب لوحة تحكم الشبكات على كل الأنظمة (كافتراضي أو على الجميع)، أو إرجاعه للأصل */
    public function dashboard(Request $request): Response
    {
        $mode = $request->str('mode');
        if ($mode === 'reset') {
            \App\Services\DashLayout::resetSystem();
            Platform::log(null, 'admin', 'platform.dashboard', 'إرجاع لوحة تحكم الأنظمة إلى الأصل', AdminAuth::user()['name'] ?? null);
            return $this->ok('أُعيدت لوحة تحكم الأنظمة إلى الأصل.', '/admin/design#dash');
        }
        $l = \App\Services\DashLayout::sanitize(\App\Services\DashLayout::NET, $request->str('layout'));
        if (!$l || !in_array($mode, ['default', 'all'], true)) {
            return $this->backWithErrors($request, ['layout' => 'ترتيب غير صالح.'], '/admin/design#dash');
        }
        \App\Services\DashLayout::applySystem($l, $mode === 'all');
        Platform::log(null, 'admin', 'platform.dashboard', $mode === 'all' ? 'تطبيق ترتيب لوحة التحكم على كل الموظفين في كل الأنظمة' : 'ترتيب افتراضي للوحة تحكم الأنظمة', AdminAuth::user()['name'] ?? null);
        return $this->ok($mode === 'all' ? 'طُبّق الترتيب على كل الأنظمة.' : 'صار الترتيب افتراضياً لكل الأنظمة.', '/admin/design#dash');
    }

    public function save(Request $request): Response
    {
        $t = $this->values($request);
        $values = [];
        foreach ($t as $k => $v) {
            $values['design.' . $k] = $v;
        }
        $values['design.rev'] = (string) Theme::REV;
        // لغة الأنظمة الأساسية، وسماح التبديل لكل مستخدم
        $values['platform.locale'] = in_array($request->str('locale'), array_keys(\App\Core\Lang::LOCALES), true) ? $request->str('locale') : 'ar';
        $values['platform.locale_switch'] = $request->bool('locale_switch') ? '1' : '0';
        Platform::setSettings($values);
        Theme::flush();
        $name = $t['preset'] !== 'custom' ? 'تصميم «' . Theme::PRESETS[$t['preset']][0] . '»' : 'تصميم مخصص';
        Platform::log(null, 'admin', 'platform.design', 'تطبيق ' . $name, AdminAuth::user()['name']);
        return $this->ok('تم تطبيق ' . $name . ' على كل الأنظمة.', '/admin/design');
    }

    /** معاينة حيّة لصفحة حقيقية من النظام بالقيم المختارة (داخل إطار في صفحة التصاميم) */
    public function preview(Request $request): Response
    {
        $t = $this->values($request);
        $mode = in_array($request->str('mode'), ['dark', 'black'], true) ? $request->str('mode') : 'light';
        // المعاينة بلغة الأنظمة المختارة في النموذج
        \App\Core\Lang::set($request->str('locale') === 'en' ? 'en' : 'ar');
        $html = \App\Core\View::render('admin/design-preview', ['t' => $t, 'mode' => $mode], '');
        return new Response($html);
    }

    public function landing(Request $request): Response
    {
        return view('admin/landing', [], 'admin');
    }

    public function saveLanding(Request $request): Response
    {
        $values = [];
        // العربية (landing.*) والإنجليزية (landing_en.*، حقولها تبدأ بـ en_)
        foreach (['landing.' => '', 'landing_en.' => 'en_'] as $prefix => $field) {
            foreach (array_keys(Landing::TEXT_FIELDS) as $k) {
                $values[$prefix . $k] = mb_substr(trim($request->str($field . $k)), 0, 400);
            }
            foreach (array_keys(Landing::LIST_FIELDS) as $k) {
                // أسماء خاصة بعناصر القائمة: «features_title» حقل نصي (عنوان القسم)، فلا يُخلط بـ «features_title[]»
                $titles = (array) $request->input($field . $k . '_items_title', []);
                $texts = (array) $request->input($field . $k . '_items_text', []);
                $lines = [];
                foreach ($titles as $i => $title) {
                    $title = trim(str_replace(['|', "\n", "\r"], ' ', (string) $title));
                    $text = trim(str_replace(['|', "\n", "\r"], ' ', (string) ($texts[$i] ?? '')));
                    if ($title !== '') {
                        $lines[] = mb_substr($title, 0, 120) . ($text !== '' ? ' | ' . mb_substr($text, 0, 300) : '');
                    }
                }
                $values[$prefix . $k] = implode("\n", array_slice($lines, 0, 12));
            }
        }
        $video = trim($request->str('video'));
        $videoId = $video === '' ? '' : Landing::videoId($video);
        if ($videoId === null) {
            return $this->backWithErrors($request, ['video' => 'رابط فيديو العرض غير صحيح.'], '/admin/landing');
        }
        $values['landing.video'] = $videoId;
        $values['landing.style'] = isset(Landing::STYLES[$request->str('style')]) ? $request->str('style') : 'classic';
        $values['landing.enabled'] = $request->bool('enabled') ? '1' : '0';
        foreach (array_keys(Landing::SECTIONS) as $k) {
            $values['landing.show_' . $k] = $request->bool('show_' . $k) ? '1' : '0';
        }
        foreach ($values as $k => $v) {
            // العربية الفارغة تعود إلى النص الافتراضي؛ الإنجليزية الفارغة تعني الترجمة التلقائية من القاموس
            if ($v === '' && str_starts_with($k, 'landing.') && !str_starts_with($k, 'landing.show_') && $k !== 'landing.video') {
                $values[$k] = Landing::DEFAULTS[$k] ?? '';
            }
        }
        Platform::setSettings($values);
        Platform::log(null, 'admin', 'platform.landing', 'تعديل صفحة الهبوط', AdminAuth::user()['name']);
        return $this->ok('تم حفظ صفحة الهبوط.', '/admin/landing');
    }
}
