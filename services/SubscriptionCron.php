<?php
declare(strict_types=1);

final class SubscriptionCron
{
    /** Persists grace / expired states and sends renewal reminders (7, 3, 1 days before and on expiry). */
    public static function run(string $today): array
    {
        $grace = Settings::int('grace_days', 7);
        $toGrace = DB::exec("UPDATE owner_subscriptions SET status = 'grace', grace_until = DATE_ADD(ends_on, INTERVAL ? DAY)
                             WHERE is_current = 1 AND status IN ('trial','active') AND ends_on < ?", [$grace, $today]);
        $toExpired = DB::exec("UPDATE owner_subscriptions SET status = 'expired'
                               WHERE is_current = 1 AND status = 'grace' AND grace_until < ?", [$today]);
        Subscription::flush();
        $reminded = 0;
        foreach ([7, 3, 1, 0] as $days) {
            $date = date('Y-m-d', strtotime("$today +$days days"));
            $subs = DB::all("SELECT s.id, s.academy_id, s.ends_on, p.name AS package_name, a.owner_user_id FROM owner_subscriptions s
                             JOIN packages p ON p.id = s.package_id JOIN academies a ON a.id = s.academy_id
                             WHERE s.is_current = 1 AND s.status IN ('trial','active') AND s.ends_on = ?", [$date]);
            foreach ($subs as $s) {
                if (!$s['owner_user_id']) {
                    continue;
                }
                $c = NotificationService::toUsers((int) $s['academy_id'], 'subscription_expiring', [(int) $s['owner_user_id']], [
                    'package_name' => $s['package_name'], 'date' => date('d M Y', strtotime($s['ends_on'])),
                ], ['dedupe' => "sub{$s['id']}:$days", 'platform' => true, 'channels' => ['inapp', 'push', 'email', 'whatsapp']]);
                $reminded += array_sum($c);
            }
        }
        return ['to_grace' => $toGrace, 'expired' => $toExpired, 'reminders' => $reminded];
    }
}
