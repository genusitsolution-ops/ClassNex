<?php
declare(strict_types=1);

/** Tenant-scoped create/update helper for simple tables. Table names always come from code. */
final class Crud
{
    /** Loads a row that must belong to the caller's academy. */
    public static function find(string $table, int $id, int $academyId, string $what = 'Record'): array
    {
        $row = DB::one("SELECT * FROM `$table` WHERE id = ? AND academy_id = ?", [$id, $academyId]);
        if (!$row) {
            throw ApiException::notFound($what);
        }
        return $row;
    }

    /**
     * Validates input against $rules and inserts or updates.
     * $fixed: columns forced by the server (academy_id etc.), never taken from input.
     */
    public static function save(?Ctx $ctx, string $table, ?int $id, array $rules, array $input, array $fixed = [], array $labels = [], ?int $academyId = null): int
    {
        if ($id) {
            // partial update: only validate fields that were sent, but keep "required" for them
            $rules = array_intersect_key($rules, $input);
        }
        $data = Validator::make($input, $rules, $labels)->validated();
        $data = array_merge($data, $fixed);
        $aid = $academyId ?? $ctx?->academyId;
        if ($id) {
            $old = $aid ? self::find($table, $id, $aid) : DB::one("SELECT * FROM `$table` WHERE id = ?", [$id]);
            if (!$old) {
                throw ApiException::notFound();
            }
            unset($data['academy_id']);
            DB::update($table, $data, 'id = ?', [$id]);
            [$o, $n] = Audit::diff($old, $data);
            if ($n) {
                Audit::log($ctx, "$table.update", $table, $id, $o, $n, $aid);
            }
            return $id;
        }
        $newId = DB::insert($table, $data);
        Audit::log($ctx, "$table.create", $table, $newId, null, $data, $aid);
        return $newId;
    }
}
