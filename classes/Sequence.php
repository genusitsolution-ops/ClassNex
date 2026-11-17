<?php
declare(strict_types=1);

/** Gap-free per-academy counters (student IDs, invoice and receipt numbers). Must run inside a transaction. */
final class Sequence
{
    public static function next(int $academyId, string $name): int
    {
        return DB::tx(static function () use ($academyId, $name) {
            DB::exec('INSERT IGNORE INTO sequences (academy_id, seq_name, next_value) VALUES (?, ?, 1)', [$academyId, $name]);
            $v = (int) DB::val('SELECT next_value FROM sequences WHERE academy_id = ? AND seq_name = ? FOR UPDATE', [$academyId, $name]);
            DB::exec('UPDATE sequences SET next_value = next_value + 1 WHERE academy_id = ? AND seq_name = ?', [$academyId, $name]);
            return $v;
        });
    }

    /** Indian financial year label, e.g. 2026-27 */
    public static function fy(?string $date = null): string
    {
        $t = strtotime($date ?? 'today');
        $y = (int) date('Y', $t);
        if ((int) date('n', $t) < 4) {
            $y--;
        }
        return $y . '-' . substr((string) ($y + 1), -2);
    }
}
