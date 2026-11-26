<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Request;

/**
 * ترتيب الجداول بالنقر على رأس العمود (البند 76): الترتيب في الخادم على كل النتائج لا على الصفحة الظاهرة فقط،
 * وبأعمدة معروفة مسبقاً فقط (لا يصل نص من الرابط إلى SQL).
 */
final class Sort
{
    /**
     * @param array<string, string> $columns المفتاح في الرابط => تعبير SQL
     * @return array{key: string, dir: string, sql: string, query: array}
     */
    public static function pick(Request $request, array $columns, string $defaultKey, string $defaultDir = 'desc', string $tie = ''): array
    {
        $key = $request->str('sort');
        $dir = strtolower($request->str('dir')) === 'asc' ? 'asc' : 'desc';
        if (!isset($columns[$key])) {
            $key = $defaultKey;
            $dir = $defaultDir;
        }
        // ترتيب ثانٍ ثابت (بالرقم) للصفوف المتساوية، إلا إن كان العمود نفسه
        $sql = $columns[$key] . ' ' . strtoupper($dir) . ($tie !== '' && stripos($tie, $columns[$key] . ' ') !== 0 ? ', ' . $tie : '');
        $query = $key === $defaultKey && $dir === $defaultDir ? [] : ['sort' => $key, 'dir' => $dir];
        return ['key' => $key, 'dir' => $dir, 'sql' => $sql, 'query' => $query];
    }

    /** رأس عمود قابل للترتيب: النقر يرتب تصاعدياً ثم تنازلياً */
    public static function th(string $label, string $key, array $sort, string $path, array $query = [], string $class = ''): string
    {
        $active = $sort['key'] === $key;
        $next = $active && $sort['dir'] === 'asc' ? 'desc' : 'asc';
        unset($query['page']);
        $href = url($path, array_merge($query, ['sort' => $key, 'dir' => $next]));
        $aria = $active ? ($sort['dir'] === 'asc' ? 'ascending' : 'descending') : 'none';
        $cls = trim('sortable ' . ($active ? 'is-' . $sort['dir'] . ' ' : '') . $class);
        // سهم الترتيب من CSS (لا أيقونة في كل رأس): صفحة أخف
        return '<th class="' . e($cls) . '" aria-sort="' . $aria . '"><a href="' . e($href) . '">' . e($label) . '</a></th>';
    }
}
