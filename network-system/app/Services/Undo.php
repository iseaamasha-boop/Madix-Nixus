<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use RuntimeException;

/**
 * التراجع عن الحذف (البند 71): بدل «هل أنت متأكد؟» يُحذف العنصر فوراً ويظهر زر «تراجع» لدقائق.
 *
 * قبل الحذف تُلتقط صفوف العنصر وكل ما يُحذف معه بالمفاتيح الأجنبية (CASCADE)، ومعرّفات ما ينفصل عنه (SET NULL)،
 * من بنية قاعدة الشبكة نفسها، فلا يُنسى جدول تابع. التراجع يعيدها كما كانت بمعرّفاتها في معاملة واحدة، ويُسجَّل في سجل العمليات.
 * ما يمس أجهزة MikroTik يمر بدالة لاحقة (after) تعيد مزامنته.
 */
final class Undo
{
    /** مدة إتاحة التراجع على الخادم */
    public const WINDOW = 600;
    /** حد الصفوف الملتقطة: حذف أكبر من ذلك يبقى بتأكيد */
    private const MAX_ROWS = 5000;

    private static ?array $fks = null;

    /** دوال ما بعد التراجع (مزامنة أجهزة MikroTik مثلاً)، بالاسم المخزّن مع اللقطة */
    private const AFTER = [
        'subscriber.sync' => [SubscriberService::class, 'sync'],
    ];

    /** المفاتيح الأجنبية في قاعدة الشبكة: الجدول المرجعي => [[الجدول التابع، العمود، قاعدة الحذف]] */
    private static function fks(): array
    {
        if (self::$fks === null) {
            self::$fks = [];
            $rows = DB::rows(
                'SELECT rc.REFERENCED_TABLE_NAME AS parent, rc.TABLE_NAME AS child, k.COLUMN_NAME AS col, k.REFERENCED_COLUMN_NAME AS ref, rc.DELETE_RULE AS rule
                 FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                 JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME
                 WHERE rc.CONSTRAINT_SCHEMA = DATABASE()'
            );
            foreach ($rows as $r) {
                if ($r['ref'] === 'id') {
                    self::$fks[$r['parent']][] = [$r['child'], $r['col'], $r['rule']];
                }
            }
        }
        return self::$fks;
    }

    /**
     * لقطة الصفوف قبل حذفها (تُستدعى قبل DELETE).
     * $extra: صفوف أخرى تُحذف مع العنصر بلا مفتاح أجنبي: [[الجدول، شرط WHERE، القيم]]
     */
    public static function capture(string $table, array $ids, array $extra = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $p = ['rows' => [], 'relink' => []];
        $count = 0;
        self::collect($table, $ids, $p, $count, 0);
        foreach ($extra as [$t, $where, $params]) {
            $rows = DB::rows('SELECT * FROM `' . $t . '` WHERE ' . $where, $params);
            if ($rows) {
                self::add($p, $count, $t, $rows);
            }
        }
        return $p;
    }

    private static function collect(string $table, array $ids, array &$p, int &$count, int $depth): void
    {
        if (!$ids || $depth > 5) {
            return;
        }
        $rows = DB::rows('SELECT * FROM `' . $table . '` WHERE id IN (' . DB::in($ids) . ')', $ids);
        if (!$rows) {
            return;
        }
        self::add($p, $count, $table, $rows);
        foreach (self::fks()[$table] ?? [] as [$child, $col, $rule]) {
            if ($rule === 'CASCADE') {
                $kids = DB::rows('SELECT * FROM `' . $child . '` WHERE `' . $col . '` IN (' . DB::in($ids) . ')', $ids);
                if (!$kids) {
                    continue;
                }
                if (array_key_exists('id', $kids[0])) {
                    // الجدول التابع له توابعه أيضاً: تُلتقط بالترتيب نفسه (الأب قبل الابن)
                    self::collect($child, array_map(static fn ($k) => (int) $k['id'], $kids), $p, $count, $depth + 1);
                } else {
                    self::add($p, $count, $child, $kids);
                }
            } elseif ($rule === 'SET NULL') {
                $links = DB::rows('SELECT id, `' . $col . '` AS ref FROM `' . $child . '` WHERE `' . $col . '` IN (' . DB::in($ids) . ')', $ids);
                if ($links) {
                    $count += count($links);
                    $p['relink'][] = [$child, $col, array_map(static fn ($l) => [(int) $l['id'], (int) $l['ref']], $links)];
                }
            }
        }
    }

    private static function add(array &$p, int &$count, string $table, array $rows): void
    {
        $count += count($rows);
        if ($count > self::MAX_ROWS) {
            throw new RuntimeException('too many rows to keep for undo');
        }
        $p['rows'][] = [$table, $rows];
    }

    /**
     * حفظ اللقطة بعد نجاح الحذف، وإرجاع رمز التراجع.
     * $action: اسم العملية في سجل العمليات (subscriber.delete مثلاً)، $perm: الصلاحية اللازمة للتراجع.
     */
    public static function record(string $action, string $label, ?string $perm, array $payload, ?string $after = null, ?int $afterId = null): string
    {
        $token = bin2hex(random_bytes(16));
        $payload['after'] = $after !== null && isset(self::AFTER[$after]) ? [$after, $afterId] : null;
        DB::insert('undo_actions', [
            'token' => $token, 'employee_id' => Auth::id(), 'action' => $action, 'label' => mb_substr($label, 0, 255), 'perm' => $perm,
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + self::WINDOW),
        ]);
        // اللقطات المنتهية تُحذف فوراً (قد تحوي كلمة مرور PPPoE للمشترك): لا نسخة تبقى بعد مدة التراجع
        DB::delete('undo_actions', 'expires_at < ?', [now()]);
        return $token;
    }

    /**
     * التراجع: إعادة الصفوف وربط ما انفصل، ثم دالة ما بعد التراجع وسجل العمليات.
     * @return array{ok:bool, message:string}
     */
    public static function restore(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return ['ok' => false, 'message' => 'لم يعد التراجع متاحاً.'];
        }
        $u = DB::row('SELECT * FROM undo_actions WHERE token = ?', [$token]);
        if (!$u || $u['undone_at'] !== null || strtotime((string) $u['expires_at']) < time()) {
            return ['ok' => false, 'message' => 'لم يعد التراجع متاحاً.'];
        }
        // صاحب الحذف نفسه فقط، وبالصلاحية نفسها ما زالت لديه
        if ((int) $u['employee_id'] !== (int) Auth::id() || ($u['perm'] !== null && !Auth::can((string) $u['perm']))) {
            return ['ok' => false, 'message' => 'لا تملك صلاحية التراجع عن هذه العملية.'];
        }
        $p = json_decode((string) $u['payload'], true);
        if (!is_array($p)) {
            return ['ok' => false, 'message' => 'لم يعد التراجع متاحاً.'];
        }
        try {
            DB::transaction(static function () use ($p, $u): void {
                // يُعلَّم أولاً داخل المعاملة: طلبا تراجع متزامنان لا يعيدان الصفوف مرتين
                if (DB::update('undo_actions', ['undone_at' => now()], 'id = ? AND undone_at IS NULL', [(int) $u['id']]) !== 1) {
                    throw new RuntimeException('already undone');
                }
                foreach ($p['rows'] ?? [] as [$table, $rows]) {
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::insertMany($table, $chunk);
                    }
                }
                foreach ($p['relink'] ?? [] as [$table, $col, $pairs]) {
                    foreach ($pairs as [$id, $ref]) {
                        DB::update($table, [$col => $ref], 'id = ? AND `' . $col . '` IS NULL', [$id]);
                    }
                }
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage() === 'already undone'
                ? 'تم التراجع عن هذه العملية من قبل.'
                : 'تعذّر التراجع: أُضيفت بيانات تتعارض مع العنصر المحذوف بعد حذفه.'];
        }
        $note = '';
        if (!empty($p['after']) && isset(self::AFTER[$p['after'][0]])) {
            try {
                $r = call_user_func(self::AFTER[$p['after'][0]], (int) $p['after'][1]);
                if (is_array($r) && isset($r['ok']) && !$r['ok']) {
                    $note = ' ' . trim((string) ($r['message'] ?? ''));
                }
            } catch (\Throwable $e) {
                $note = ' تعذّرت المزامنة مع جهاز MikroTik، وستُعاد تلقائياً.';
            }
        }
        $first = $p['rows'][0] ?? null;
        Audit::log(
            (string) preg_replace('/\.delete$/', '', (string) $u['action']) . '.undo',
            'تراجع عن: ' . $u['label'],
            $first ? (string) preg_replace('/s$/', '', (string) $first[0]) : null,
            $first ? (int) ($first[1][0]['id'] ?? 0) : null
        );
        return ['ok' => true, 'message' => 'تم التراجع.' . $note];
    }
}
