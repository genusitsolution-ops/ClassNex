<?php
declare(strict_types=1);

final class SupportAccess
{
    /** Loads a ticket the caller may see: own tickets; owners see their academy's; super admin sees platform tickets. */
    public static function ticket(Ctx $c, int $id): array
    {
        $t = DB::one('SELECT * FROM support_tickets WHERE id = ?', [$id]);
        $ok = $t && (
            (int) $t['user_id'] === $c->userId
            || ($c->role === 'super_admin' && $t['scope'] === 'platform')
            || ($c->role === 'owner' && (int) $t['academy_id'] === $c->academyId)
        );
        if (!$ok) {
            throw ApiException::notFound('Ticket');
        }
        return $t;
    }
}
