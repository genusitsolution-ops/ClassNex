<?php
declare(strict_types=1);

/** Creates logins for owners, teachers, parents and adult students, and sends invitations. */
final class AccountService
{
    /**
     * Returns an existing user (same academy, role, mobile) or creates one.
     * Without a password the account is "invited" until activated through the link.
     */
    public static function ensureUser(?int $academyId, string $role, string $name, string $cc, ?string $mobile, ?string $email, ?string $password = null): int
    {
        $existing = $mobile ? DB::one('SELECT id FROM users WHERE country_code = ? AND mobile = ? AND role = ? AND academy_id <=> ?', [$cc, $mobile, $role, $academyId]) : null;
        if (!$existing && $email && !$mobile) {
            $existing = DB::one('SELECT id FROM users WHERE email = ? AND role = ? AND academy_id <=> ?', [strtolower($email), $role, $academyId]);
        }
        if ($existing) {
            return (int) $existing['id'];
        }
        if ($password !== null) {
            Auth::validatePassword($password);
        }
        return DB::insert('users', [
            'academy_id'           => $academyId,
            'role'                 => $role,
            'name'                 => mb_substr($name, 0, 120),
            'country_code'         => $cc,
            'mobile'               => $mobile,
            'email'                => $email ? strtolower($email) : null,
            'password_hash'        => $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null,
            'status'               => $password !== null ? 'active' : 'invited',
            'must_change_password' => 0,
        ]);
    }

    /** Sends (or re-sends) an activation link. Returns the link so staff can also share it manually. */
    public static function invite(int $userId): array
    {
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$u) {
            throw ApiException::notFound('User');
        }
        if ($u['status'] === 'active' && $u['password_hash']) {
            return ['link' => null, 'already_active' => true];
        }
        $token = Auth::oneTimeToken($userId, 'activation', 7 * 86400);
        $link = cnx_url('activate.php?t=' . $token);
        $wa = $u['mobile'] ? Phone::wa($u['country_code'], $u['mobile']) : null;
        NotificationService::direct($u['academy_id'] ? (int) $u['academy_id'] : null, 'account_invite', ['recipient_name' => $u['name'], 'link' => $link, 'dedupe' => 'inv' . substr($token, 0, 10)], $wa, $u['email'], $userId);
        $msg = "Hello {$u['name']}, your ClassNex account is ready. Activate it here: $link";
        return [
            'link'          => $link,
            'whatsapp_link' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg) : null,
            'already_active'=> false,
        ];
    }

    public static function activate(string $token, string $password): array
    {
        Auth::validatePassword($password);
        $t = Auth::consumeToken($token, 'activation') ?? Auth::consumeToken($token, 'password_reset');
        if (!$t) {
            throw new ApiException('This link has expired or was already used. Ask your academy to send a new one.', 410);
        }
        DB::tx(static function () use ($t, $password) {
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active', 'must_change_password' => 0, 'failed_logins' => 0, 'locked_until' => null, 'mobile_verified_at' => date('Y-m-d H:i:s')], 'id = ?', [$t['user_id']]);
            DB::exec('UPDATE user_tokens SET used_at = NOW() WHERE id = ?', [$t['id']]);
            DB::exec('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$t['user_id']]);
        });
        return DB::one('SELECT id, name, role FROM users WHERE id = ?', [$t['user_id']]);
    }

    public static function requestReset(string $identifier): void
    {
        RateLimiter::hit('reset:' . Http::ip(), 10, 3600);
        $identifier = trim($identifier);
        $users = str_contains($identifier, '@')
            ? DB::all("SELECT * FROM users WHERE email = ? AND status IN ('active','invited')", [strtolower($identifier)])
            : DB::all("SELECT * FROM users WHERE mobile = ? AND status IN ('active','invited')", [Phone::digits($identifier)]);
        foreach ($users as $u) {
            $token = Auth::oneTimeToken((int) $u['id'], 'password_reset', 3600);
            $link = cnx_url('activate.php?reset=1&t=' . $token);
            NotificationService::direct($u['academy_id'] ? (int) $u['academy_id'] : null, 'password_reset', ['recipient_name' => $u['name'], 'link' => $link, 'dedupe' => 'pr' . substr($token, 0, 10)], $u['mobile'] ? Phone::wa($u['country_code'], $u['mobile']) : null, $u['email'], (int) $u['id']);
        }
        // Always behave the same, so the endpoint cannot be used to discover accounts.
    }
}
