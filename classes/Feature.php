<?php
declare(strict_types=1);

/** Package feature flags and usage limits — always enforced on the backend. */
final class Feature
{
    public const FEATURES = [
        'attendance'        => 'Attendance',
        'fees'              => 'Fee Management',
        'inapp'             => 'In-App Notifications',
        'push'              => 'Push Notifications',
        'whatsapp'          => 'WhatsApp Messages',
        'email'             => 'Email Notifications',
        'sms'               => 'SMS',
        'auto_reminders'    => 'Automatic Reminders',
        'manual_reminders'  => 'Manual Reminders',
        'custom_templates'  => 'Custom Message Templates',
        'reports'           => 'Reports & Exports',
        'advanced_reports'  => 'Advanced Reports',
        'parent_access'     => 'Parent / Student App Access',
        'staff_management'  => 'Staff Management',
        'documents'         => 'Document Management & OCR',
        'online_admission'  => 'Online Admission Form',
        'online_payments'   => 'Online Fee Payments',
        'api_access'        => 'API Access',
        'priority_support'  => 'Priority Support',
    ];

    public const LIMITS = [
        'students' => ['col' => 'student_limit',  'sql' => "SELECT COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'"],
        'teachers' => ['col' => 'teacher_limit',  'sql' => "SELECT COUNT(*) FROM teachers WHERE academy_id = ? AND status = 'active'"],
        'activities' => ['col' => 'activity_limit', 'sql' => "SELECT COUNT(*) FROM activities WHERE academy_id = ? AND status = 'active'"],
        'batches'  => ['col' => 'batch_limit',    'sql' => "SELECT COUNT(*) FROM batches WHERE academy_id = ? AND status = 'active'"],
        'branches' => ['col' => 'branch_limit',   'sql' => "SELECT COUNT(*) FROM academy_branches WHERE academy_id = ? AND status = 'active'"],
        'storage_mb' => ['col' => 'storage_limit_mb', 'sql' => "SELECT COALESCE(CEIL(SUM(size_bytes)/1048576),0) FROM student_documents WHERE academy_id = ? AND deleted_at IS NULL"],
        'whatsapp_month' => ['col' => 'monthly_whatsapp_limit', 'sql' => "SELECT COUNT(*) FROM notification_queue WHERE academy_id = ? AND channel = 'whatsapp' AND status IN ('sent','delivered','read','pending','processing') AND created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')"],
    ];

    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    public static function forAcademy(int $academyId): array
    {
        if (isset(self::$cache[$academyId])) {
            return self::$cache[$academyId];
        }
        $st = Subscription::state($academyId);
        if (!$st['package_id']) {
            return self::$cache[$academyId] = ['features' => [], 'limits' => [], 'package' => null, 'whatsapp_mode' => 'off'];
        }
        $pkg = DB::one('SELECT * FROM packages WHERE id = ?', [$st['package_id']]);
        $features = DB::col('SELECT feature_key FROM package_features WHERE package_id = ? AND enabled = 1', [$st['package_id']]);
        $limits = [];
        foreach (self::LIMITS as $k => $def) {
            $limits[$k] = $pkg[$def['col']] === null ? null : (int) $pkg[$def['col']];
        }
        foreach ($st['custom_limits'] ?? [] as $k => $v) {
            if (array_key_exists($k, $limits)) {
                $limits[$k] = $v === null || $v === '' ? null : (int) $v;
            }
        }
        // WhatsApp package mode (set by Super Admin on the package):
        //   off    – no WhatsApp at all
        //   manual – click-to-send: ClassNex prepares the message, the user sends it from WhatsApp / WhatsApp Business
        //   api    – official WhatsApp Business Cloud API, sent in the background by the queue (+ click-to-send)
        $waMode = in_array($pkg['whatsapp_mode'] ?? 'api', ['off', 'manual', 'api'], true) ? $pkg['whatsapp_mode'] : 'api';
        $pkgHasWhatsApp = in_array('whatsapp', $features, true);
        $features = array_values(array_diff($features, ['whatsapp', 'whatsapp_manual']));
        if ($waMode !== 'off') {
            $features[] = 'whatsapp_manual';
        }
        if ($waMode === 'api' && $pkgHasWhatsApp) {
            $features[] = 'whatsapp';
        }
        return self::$cache[$academyId] = ['features' => $features, 'limits' => $limits, 'package' => $pkg['name'], 'whatsapp_mode' => $waMode];
    }

    public static function has(int $academyId, string $feature): bool
    {
        return in_array($feature, self::forAcademy($academyId)['features'], true);
    }

    public static function assert(int $academyId, string $feature): void
    {
        if (!self::has($academyId, $feature)) {
            $label = self::FEATURES[$feature] ?? $feature;
            throw new ApiException("$label is not included in your current plan. Upgrade to use it.", 402, [], 'feature_locked');
        }
    }

    public static function usage(int $academyId, string $limit): int
    {
        return (int) DB::val(self::LIMITS[$limit]['sql'], [$academyId]);
    }

    /** Throws when adding $adding more items would exceed the package limit. */
    public static function assertLimit(int $academyId, string $limit, int $adding = 1): void
    {
        $max = self::forAcademy($academyId)['limits'][$limit] ?? null;
        if ($max === null) {
            return;
        }
        if (self::usage($academyId, $limit) + $adding > $max) {
            $label = str_replace('_', ' ', $limit);
            throw new ApiException("Your plan allows up to $max $label. Upgrade your plan to add more.", 402, [], 'limit_reached');
        }
    }

    /**
     * Central student quota (one per academy, shared by every branch).
     * Counts unique active student profiles across all branches. MUST run inside DB::tx():
     * the academy row is locked so concurrent registrations cannot exceed the package limit.
     */
    public static function assertStudentCapacity(int $academyId, int $adding = 1): void
    {
        DB::val('SELECT id FROM academies WHERE id = ? FOR UPDATE', [$academyId]);
        $max = self::forAcademy($academyId)['limits']['students'] ?? null;
        if ($max === null) {
            return;
        }
        $active = (int) DB::val("SELECT COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'", [$academyId]);
        if ($active + $adding > $max) {
            throw new ApiException(
                "Your package allows $max students. You have already registered $active active students across all branches. Please upgrade your package to add more students.",
                402,
                [],
                'limit_reached'
            );
        }
    }

    public static function summary(int $academyId): array
    {
        $f = self::forAcademy($academyId);
        $usage = [];
        foreach (self::LIMITS as $k => $_) {
            $usage[$k] = ['used' => self::usage($academyId, $k), 'limit' => $f['limits'][$k] ?? null];
        }
        return ['package' => $f['package'], 'features' => $f['features'], 'usage' => $usage];
    }
}
