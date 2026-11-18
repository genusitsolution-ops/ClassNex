<?php
declare(strict_types=1);

return static function (Router $r): void {
    // Login. client=web starts a session (panels); otherwise returns a bearer token (Android/API).
    $r->post('/auth/login', static function (?Ctx $_, array $p) {
        $in = Http::input();
        $id = trim((string) ($in['identifier'] ?? $in['mobile'] ?? $in['email'] ?? ''));
        $pw = (string) ($in['password'] ?? '');
        if ($id === '' || $pw === '') {
            throw ApiException::validation(['identifier' => 'Enter your mobile number or email and password.']);
        }
        $accounts = Auth::attempt($id, $pw);
        $chosen = null;
        if (!empty($in['account_id'])) {
            foreach ($accounts as $a) {
                if ((int) $a['id'] === (int) $in['account_id']) {
                    $chosen = $a;
                }
            }
        } elseif (count($accounts) === 1) {
            $chosen = $accounts[0];
        }
        if (!$chosen) {
            return Http::ok(['choose_account' => true, 'accounts' => array_map([Auth::class, 'accountLabel'], $accounts)], 'Choose which account to open.');
        }
        if ($chosen['academy_id'] && in_array($chosen['role'], ['parent', 'student'], true) && !Feature::has((int) $chosen['academy_id'], 'parent_access')) {
            throw new ApiException('Parent / student access is not enabled for this academy.', 403);
        }
        $client = $in['client'] ?? 'android';
        Audit::log(null, 'auth.login', 'users', $chosen['id'], null, ['client' => $client], $chosen['academy_id'] ? (int) $chosen['academy_id'] : null);
        if ($client === 'web') {
            Auth::loginSession($chosen);
            return Http::ok(['redirect' => cnx_url(Auth::homeFor($chosen['role'])), 'csrf' => $_SESSION['csrf'] ?? null], 'Welcome back!');
        }
        $token = Auth::issueToken($chosen, $client === 'api' ? 'api' : 'android', $in['device_name'] ?? null);
        Auth::reset();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        return Http::ok(['token' => $token, 'expires_in_days' => (int) Config::get('token_ttl_days', 30), 'me' => me(Auth::require())], 'Welcome back!');
    }, ['auth' => false]);

    $r->post('/auth/logout', static function (Ctx $c) {
        $tok = Http::input()['fcm_token'] ?? null;
        if ($tok) {
            DB::exec('DELETE FROM device_tokens WHERE fcm_token = ? AND user_id = ?', [$tok, $c->userId]);
        }
        Auth::logout();
        return Http::ok(null, 'Logged out.');
    }, ['write' => false]);

    $r->get('/auth/me', static fn(Ctx $c) => Http::ok(me($c)));

    $r->post('/auth/forgot-password', static function () {
        AccountService::requestReset((string) (Http::input()['identifier'] ?? ''));
        return Http::ok(null, 'If an account exists, a reset link has been sent on WhatsApp / email.');
    }, ['auth' => false]);

    $r->post('/auth/activate', static function () {
        $in = Http::input();
        $u = AccountService::activate((string) ($in['token'] ?? ''), (string) ($in['password'] ?? ''));
        return Http::ok($u, 'Your password has been set. You can log in now.');
    }, ['auth' => false]);

    $r->post('/auth/change-password', static function (Ctx $c) {
        $in = Http::input();
        $u = DB::one('SELECT password_hash FROM users WHERE id = ?', [$c->userId]);
        if (!password_verify((string) ($in['current_password'] ?? ''), (string) $u['password_hash'])) {
            throw ApiException::validation(['current_password' => 'Current password is incorrect.']);
        }
        Auth::validatePassword((string) ($in['new_password'] ?? ''));
        DB::update('users', ['password_hash' => password_hash((string) $in['new_password'], PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = ?', [$c->userId]);
        Audit::log($c, 'auth.password_change', 'users', $c->userId);
        return Http::ok(null, 'Password changed.');
    }, ['write' => false]);

    $r->put('/auth/profile', static function (Ctx $c) {
        $d = Validator::make(Http::input(), ['name' => 'required|max:120', 'email' => 'email|max:160'])->validated();
        DB::update('users', $d, 'id = ?', [$c->userId]);
        return Http::ok(me(new Ctx(DB::one('SELECT * FROM users WHERE id = ?', [$c->userId]))), 'Profile updated.');
    }, ['write' => false]);

    $r->post('/devices', static function (Ctx $c) {
        $tok = trim((string) (Http::input()['fcm_token'] ?? ''));
        if ($tok === '' || strlen($tok) > 255) {
            throw ApiException::validation(['fcm_token' => 'Invalid token']);
        }
        DB::exec('INSERT INTO device_tokens (user_id, fcm_token, platform) VALUES (?,?,?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), updated_at = NOW()', [$c->userId, $tok, 'android']);
        return Http::ok(null, 'Device registered.');
    }, ['write' => false]);

    // in-app notifications (all roles)
    $r->get('/notifications', static function (Ctx $c) {
        $w = 'user_id = ?';
        $p = [$c->userId];
        if (Http::query('unread')) {
            $w .= ' AND read_at IS NULL';
        }
        if ($sid = (int) Http::query('student_id')) {
            $w .= ' AND (student_id = ? OR student_id IS NULL)';
            $p[] = $sid;
        }
        $res = Http::paged("SELECT id, event_key, title, body, data, student_id, read_at, created_at FROM notifications WHERE $w ORDER BY id DESC", $p);
        $res['meta']['unread'] = (int) DB::val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$c->userId]);
        return $res;
    });
    $r->post('/notifications/read', static function (Ctx $c) {
        $ids = array_map('intval', (array) (Http::input()['ids'] ?? []));
        $n = $ids ? DB::exec('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN (' . DB::inList($ids) . ')', array_merge([$c->userId], $ids))
                  : DB::exec('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$c->userId]);
        return Http::ok(['updated' => $n]);
    }, ['write' => false]);
};

/** Profile + role context used by every client to build menus and dashboards. */
function me(Ctx $c): array
{
    $u = $c->user;
    $out = [
        'id' => $c->userId, 'name' => $u['name'], 'role' => $c->role, 'email' => $u['email'],
        'mobile' => $u['mobile'], 'country_code' => $u['country_code'], 'must_change_password' => (bool) $u['must_change_password'],
        'permissions' => $c->perms, 'academy' => null, 'subscription' => null, 'features' => [], 'children' => [],
    ];
    if ($c->academyId) {
        $a = DB::one('SELECT id, name, code, slug, logo_path, mobile, whatsapp, email, status FROM academies WHERE id = ?', [$c->academyId]);
        $a['logo_url'] = $a['logo_path'] ? cnx_url($a['logo_path']) : null;
        $out['academy'] = $a;
        $out['subscription'] = Subscription::state($c->academyId);
        $out['features'] = Feature::forAcademy($c->academyId)['features'];
        $out['whatsapp_mode'] = Feature::forAcademy($c->academyId)['whatsapp_mode'];
        if ($c->role === 'teacher') {
            $out['teacher_id'] = $c->teacherId;
        }
        if (in_array($c->role, ['parent', 'student'], true)) {
            $ids = $c->familyStudentIds();
            $out['children'] = $ids ? DB::all('SELECT id, name, student_code, photo_path IS NOT NULL AS has_photo FROM students WHERE id IN (' . DB::inList($ids) . ') ORDER BY name', $ids) : [];
            foreach ($out['children'] as &$ch) {
                $ch['photo_url'] = $ch['has_photo'] ? cnx_url('api/v1/files/student-photo/' . $ch['id']) : null;
            }
        }
    }
    return $out;
}
