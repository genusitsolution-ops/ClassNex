<?php
declare(strict_types=1);

/** Effective subscription state of an academy, computed live from dates (cron only persists it). */
final class Subscription
{
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
        Feature::flush();
    }

    public static function current(int $academyId): ?array
    {
        return DB::one('SELECT s.*, p.name AS package_name, p.slug AS package_slug FROM owner_subscriptions s
                        JOIN packages p ON p.id = s.package_id WHERE s.academy_id = ? AND s.is_current = 1
                        ORDER BY s.id DESC LIMIT 1', [$academyId]);
    }

    public static function state(int $academyId): array
    {
        if (isset(self::$cache[$academyId])) {
            return self::$cache[$academyId];
        }
        $acad = DB::one('SELECT status FROM academies WHERE id = ?', [$academyId]);
        $s = self::current($academyId);
        $today = date('Y-m-d');
        if (!$s) {
            return self::$cache[$academyId] = ['status' => 'none', 'writable' => false, 'package_id' => null, 'package_name' => null, 'ends_on' => null, 'days_left' => null];
        }
        $status = $s['status'];
        if (in_array($status, ['trial', 'active', 'grace'], true) && $s['ends_on'] !== null && $s['ends_on'] < $today) {
            $graceUntil = $s['grace_until'] ?: date('Y-m-d', strtotime($s['ends_on'] . ' +' . Settings::int('grace_days', 7) . ' days'));
            $status = $graceUntil >= $today ? 'grace' : 'expired';
        }
        if (($acad['status'] ?? 'active') === 'suspended') {
            $status = 'suspended';
        }
        $daysLeft = $s['ends_on'] ? (int) floor((strtotime($s['ends_on']) - strtotime($today)) / 86400) : null;
        return self::$cache[$academyId] = [
            'id'           => (int) $s['id'],
            'status'       => $status,
            'writable'     => in_array($status, ['trial', 'active', 'grace'], true),
            'package_id'   => (int) $s['package_id'],
            'package_name' => $s['package_name'],
            'starts_on'    => $s['starts_on'],
            'ends_on'      => $s['ends_on'],
            'days_left'    => $daysLeft,
            'custom_limits'=> $s['custom_limits'] ? json_decode($s['custom_limits'], true) : [],
        ];
    }

    /** Starts / replaces the current subscription. */
    public static function assign(int $academyId, int $packageId, string $status, ?string $startsOn, ?string $endsOn, int $pricePaise, ?Ctx $by, ?string $notes = null, ?array $customLimits = null): int
    {
        $pkg = DB::one('SELECT * FROM packages WHERE id = ?', [$packageId]);
        if (!$pkg) {
            throw ApiException::notFound('Package');
        }
        $startsOn ??= date('Y-m-d');
        if ($endsOn === null && $pkg['billing_cycle'] !== 'lifetime') {
            $endsOn = $status === 'trial'
                ? date('Y-m-d', strtotime("$startsOn +" . max(1, (int) $pkg['trial_days']) . ' days'))
                : self::addCycle($startsOn, $pkg['billing_cycle']);
        }
        return DB::tx(static function () use ($academyId, $packageId, $status, $startsOn, $endsOn, $pricePaise, $by, $notes, $customLimits, $pkg) {
            DB::exec('UPDATE owner_subscriptions SET is_current = 0 WHERE academy_id = ? AND is_current = 1', [$academyId]);
            $id = DB::insert('owner_subscriptions', [
                'academy_id'    => $academyId,
                'package_id'    => $packageId,
                'status'        => $status,
                'is_current'    => 1,
                'starts_on'     => $startsOn,
                'ends_on'       => $endsOn,
                'price_paise'   => $pricePaise,
                'billing_cycle' => $pkg['billing_cycle'],
                'custom_limits' => $customLimits ? json_encode($customLimits) : null,
                'notes'         => $notes,
                'created_by'    => $by?->userId,
            ]);
            Audit::log($by, 'subscription.assign', 'owner_subscriptions', $id, null, ['package' => $pkg['name'], 'status' => $status, 'ends_on' => $endsOn], $academyId);
            self::flush();
            return $id;
        });
    }

    public static function addCycle(string $from, string $cycle): ?string
    {
        $m = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12][$cycle] ?? null;
        if ($m === null) {
            return null;
        }
        return date('Y-m-d', strtotime("$from +$m months -1 day"));
    }

    /** Renews: extends from max(today, ends_on) by one cycle of the given package. */
    public static function renew(int $academyId, int $packageId, int $pricePaise, ?Ctx $by, string $note = 'Renewal'): int
    {
        $cur = self::current($academyId);
        $pkg = DB::one('SELECT * FROM packages WHERE id = ?', [$packageId]);
        $base = date('Y-m-d');
        if ($cur && $cur['ends_on'] && $cur['ends_on'] >= $base && (int) $cur['package_id'] === $packageId && $cur['status'] !== 'trial') {
            $base = date('Y-m-d', strtotime($cur['ends_on'] . ' +1 day'));
        }
        $ends = self::addCycle($base, $pkg['billing_cycle']);
        $custom = $cur && $cur['custom_limits'] ? json_decode($cur['custom_limits'], true) : null;
        $start = $cur && (int) $cur['package_id'] === $packageId && $cur['status'] !== 'trial' ? $cur['starts_on'] : date('Y-m-d');
        return self::assign($academyId, $packageId, 'active', $start, $ends, $pricePaise, $by, $note, $custom);
    }
}
