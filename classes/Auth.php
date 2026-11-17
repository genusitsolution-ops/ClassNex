<?php
declare(strict_types=1);

/**
 * One authentication system for web and Android.
 *  - Android / API clients: "Authorization: Bearer <token>" (token stored as SHA-256 hash).
 *  - Web panels: PHP session cookie + X-CSRF-Token header for every state-changing request.
 */
final class Auth
{
    private static ?Ctx $ctx = null;
    private static bool $resolved = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name((string) Config::get('session_name', 'CNXSESS'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function reset(): void
    {
        self::$ctx = null;
        self::$resolved = false;
    }

    /**
     * Returns the list of accounts matching identifier+password.
     * One person can have several accounts (e.g. parent in two academies).
     */
    public static function attempt(string $identifier, string $password): array
    {
        $identifier = trim($identifier);
        RateLimiter::hit('login:' . Http::ip(), 30, 900);
        RateLimiter::hit('login-id:' . strtolower($identifier), 8, 900);

        if (str_contains($identifier, '@')) {
            $rows = DB::all("SELECT * FROM users WHERE email = ? AND status IN ('active','invited')", [strtolower($identifier)]);
        } else {
            $digits = Phone::digits($identifier);
            $rows = DB::all("SELECT * FROM users WHERE mobile = ? AND status IN ('active','invited')", [$digits]);
        }
        $ok = [];
        foreach ($rows as $u) {
            if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
                throw new ApiException('Too many failed attempts. Try again in 15 minutes.', 429);
            }
            if ($u['password_hash'] && password_verify($password, $u['password_hash'])) {
                if ($u['status'] === 'invited') {
                    continue;
                }
                $ok[] = $u;
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                    DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
                }
            } else {
                $fails = (int) $u['failed_logins'] + 1;
                DB::update('users', [
                    'failed_logins' => $fails,
                    'locked_until'  => $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null,
                ], 'id = ?', [$u['id']]);
            }
        }
        if (!$ok) {
            throw new ApiException('Incorrect mobile number / email or password.', 401, [], 'invalid_credentials');
        }
        foreach ($ok as $u) {
            DB::update('users', ['failed_logins' => 0, 'locked_until' => null], 'id = ?', [$u['id']]);
        }
        return $ok;
    }

    public static function accountLabel(array $u): array
    {
        $academy = $u['academy_id'] ? DB::val('SELECT name FROM academies WHERE id = ?', [$u['academy_id']]) : 'ClassNex Platform';
        return ['id' => (int) $u['id'], 'role' => $u['role'], 'name' => $u['name'], 'academy' => $academy];
    }

    public static function issueToken(array $user, string $client = 'android', ?string $device = null): string
    {
        $plain = bin2hex(random_bytes(32));
        $ttl = (int) Config::get('token_ttl_days', 30);
        DB::insert('api_tokens', [
            'user_id'     => $user['id'],
            'token_hash'  => hash('sha256', $plain),
            'client'      => $client,
            'device_name' => $device ? mb_substr($device, 0, 120) : null,
            'ip'          => Http::ip(),
            'expires_at'  => date('Y-m-d H:i:s', time() + $ttl * 86400),
        ]);
        DB::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        return $plain;
    }

    public static function loginSession(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['exp'] = time() + 3600 * (int) Config::get('session_ttl_hours', 12);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        DB::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
    }

    public static function logout(): void
    {
        $bearer = self::bearer();
        if ($bearer) {
            DB::exec('UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = ?', [hash('sha256', $bearer)]);
        }
        self::startSession();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::reset();
    }

    private static function bearer(): ?string
    {
        $h = Http::header('Authorization');
        if ($h && preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    public static function optional(): ?Ctx
    {
        if (self::$resolved) {
            return self::$ctx;
        }
        self::$resolved = true;
        $bearer = self::bearer();
        if ($bearer) {
            $row = DB::one('SELECT t.id AS token_id, u.* FROM api_tokens t JOIN users u ON u.id = t.user_id
                            WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()', [hash('sha256', $bearer)]);
            if ($row && $row['status'] === 'active') {
                DB::exec('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?', [$row['token_id']]);
                unset($row['token_id']);
                return self::$ctx = new Ctx($row, 'token');
            }
            return null;
        }
        self::startSession();
        if (!empty($_SESSION['uid']) && ($_SESSION['exp'] ?? 0) > time()) {
            $u = DB::one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['uid']]);
            if ($u) {
                if (!in_array(Http::method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                    $sent = Http::header('X-CSRF-Token') ?? (string) (Http::input()['_csrf'] ?? '');
                    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
                        throw new ApiException('Your session has expired. Please refresh the page.', 419, [], 'csrf');
                    }
                }
                return self::$ctx = new Ctx($u, 'session');
            }
        }
        return null;
    }

    public static function require(): Ctx
    {
        $ctx = self::optional();
        if (!$ctx) {
            throw new ApiException('Please log in to continue.', 401, [], 'unauthenticated');
        }
        return $ctx;
    }

    /** For server-rendered panel pages: returns user or redirects to login. */
    public static function webUser(array $roles): array
    {
        self::startSession();
        $u = null;
        if (!empty($_SESSION['uid']) && ($_SESSION['exp'] ?? 0) > time()) {
            $u = DB::one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['uid']]);
        }
        if (!$u || !in_array($u['role'], $roles, true)) {
            header('Location: ' . cnx_url('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/')));
            exit;
        }
        return $u;
    }

    public static function homeFor(string $role): string
    {
        return match ($role) {
            'super_admin' => 'admin/',
            'owner'       => 'owner/',
            'teacher'     => 'teacher/',
            default       => 'student/',
        };
    }

    /** Creates a one-time activation / reset token and returns the plain value. */
    public static function oneTimeToken(int $userId, string $purpose, int $ttlSeconds): string
    {
        $plain = bin2hex(random_bytes(24));
        DB::exec('UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$userId, $purpose]);
        DB::insert('user_tokens', [
            'user_id'    => $userId,
            'purpose'    => $purpose,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
        return $plain;
    }

    public static function consumeToken(string $plain, string $purpose): ?array
    {
        $row = DB::one('SELECT * FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $plain), $purpose]);
        return $row ?: null;
    }

    public static function validatePassword(string $p): void
    {
        if (mb_strlen($p) < 8 || !preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
            throw ApiException::validation(['password' => 'Password must be at least 8 characters with letters and numbers.']);
        }
    }
}
