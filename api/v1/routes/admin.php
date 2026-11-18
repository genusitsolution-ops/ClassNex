<?php
declare(strict_types=1);

/** Super Admin platform management. */
return static function (Router $r): void {
    $sa = ['roles' => ['super_admin']];

    $r->get('/admin/dashboard', static function () {
        $m0 = date('Y-m-01');
        return Http::ok([
            'total_owners'      => (int) DB::val("SELECT COUNT(*) FROM users WHERE role = 'owner'"),
            'active_owners'     => (int) DB::val("SELECT COUNT(*) FROM users WHERE role = 'owner' AND status = 'active'"),
            'inactive_owners'   => (int) DB::val("SELECT COUNT(*) FROM users WHERE role = 'owner' AND status <> 'active'"),
            'total_academies'   => (int) DB::val('SELECT COUNT(*) FROM academies'),
            'total_students'    => (int) DB::val("SELECT COUNT(*) FROM students WHERE status = 'active'"),
            'active_subscriptions'  => (int) DB::val("SELECT COUNT(*) FROM owner_subscriptions WHERE is_current = 1 AND status IN ('trial','active') AND (ends_on IS NULL OR ends_on >= CURDATE())"),
            'trial_subscriptions'   => (int) DB::val("SELECT COUNT(*) FROM owner_subscriptions WHERE is_current = 1 AND status = 'trial' AND ends_on >= CURDATE()"),
            'expired_subscriptions' => (int) DB::val("SELECT COUNT(*) FROM owner_subscriptions WHERE is_current = 1 AND (status IN ('expired','grace') OR ends_on < CURDATE())"),
            'expiring_7_days'   => DB::all("SELECT a.id AS academy_id, a.name, p.name AS package, s.ends_on, s.status FROM owner_subscriptions s JOIN academies a ON a.id = s.academy_id JOIN packages p ON p.id = s.package_id
                                            WHERE s.is_current = 1 AND s.ends_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY s.ends_on LIMIT 10"),
            'monthly_revenue_paise' => (int) DB::val("SELECT COALESCE(SUM(amount_paise),0) FROM subscription_payments WHERE status = 'paid' AND paid_at >= ?", [$m0]),
            'pending_subscription_payments' => (int) DB::val("SELECT COUNT(*) FROM subscription_payments WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
            'package_stats'     => DB::all("SELECT p.name, COUNT(s.id) AS academies FROM packages p LEFT JOIN owner_subscriptions s ON s.package_id = p.id AND s.is_current = 1 GROUP BY p.id ORDER BY p.sort_order"),
            'whatsapp_month'    => (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE channel = 'whatsapp' AND created_at >= ?", [$m0]),
            'notification_stats'=> DB::all("SELECT channel, status, COUNT(*) AS total FROM notification_queue WHERE created_at >= ? GROUP BY channel, status", [$m0]),
            'new_enquiries'     => (int) DB::val("SELECT COUNT(*) FROM enquiries WHERE status = 'new'"),
            'open_tickets'      => (int) DB::val("SELECT COUNT(*) FROM support_tickets WHERE scope = 'platform' AND status IN ('open','in_progress')"),
            'revenue_trend'     => DB::all("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, SUM(amount_paise) AS total FROM subscription_payments WHERE status = 'paid' AND paid_at >= DATE_SUB(?, INTERVAL 5 MONTH) GROUP BY month ORDER BY month", [$m0]),
            'recent_academies'  => DB::all('SELECT a.id, a.name, a.city, a.created_at, u.name AS owner FROM academies a LEFT JOIN users u ON u.id = a.owner_user_id ORDER BY a.id DESC LIMIT 6'),
            'last_cron'         => DB::one('SELECT task, started_at, status FROM cron_runs ORDER BY id DESC LIMIT 1'),
        ]);
    }, $sa);

    // ----------------------------------------------------------- owners/academies
    $r->get('/admin/owners', static function () {
        $w = '1=1';
        $p = [];
        if ($q = Http::query('q')) {
            $w .= ' AND (a.name LIKE ? OR u.name LIKE ? OR u.mobile LIKE ? OR u.email LIKE ? OR a.code LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($st = Http::query('status')) {
            $w .= ' AND a.status = ?';
            $p[] = $st;
        }
        if ($ss = Http::query('subscription')) {
            $w .= ' AND s.status = ?';
            $p[] = $ss;
        }
        return Http::paged("SELECT a.id AS academy_id, a.name AS academy_name, a.code, a.slug, a.city, a.status AS academy_status, a.created_at,
                              u.id AS owner_id, u.name AS owner_name, u.country_code, u.mobile, u.email, u.status AS owner_status, u.last_login_at,
                              s.id AS subscription_id, s.status AS sub_status, s.ends_on, p.name AS package_name, p.id AS package_id,
                              (SELECT COUNT(*) FROM students st WHERE st.academy_id = a.id AND st.status = 'active') AS students,
                              (SELECT COUNT(*) FROM academy_branches ab WHERE ab.academy_id = a.id AND ab.status = 'active') AS branches,
                              p.student_limit
                            FROM academies a LEFT JOIN users u ON u.id = a.owner_user_id
                            LEFT JOIN owner_subscriptions s ON s.academy_id = a.id AND s.is_current = 1 LEFT JOIN packages p ON p.id = s.package_id
                            WHERE $w ORDER BY a.id DESC", $p, "SELECT COUNT(*) FROM academies a LEFT JOIN users u ON u.id = a.owner_user_id LEFT JOIN owner_subscriptions s ON s.academy_id = a.id AND s.is_current = 1 WHERE $w");
    }, $sa);

    $r->post('/admin/owners', static function (Ctx $c) {
        $res = AcademyService::createOwner(Http::input(), $c, false);
        return Http::ok($res, 'Academy and Class Admin created.' . ($res['invite'] ? ' Activation link sent.' : ''));
    }, $sa);

    $r->get('/admin/academies/{id}', static function (Ctx $c, array $p) {
        $a = DB::one('SELECT a.*, u.name AS owner_name, u.mobile AS owner_mobile, u.email AS owner_email, u.status AS owner_status FROM academies a LEFT JOIN users u ON u.id = a.owner_user_id WHERE a.id = ?', [(int) $p['id']]);
        if (!$a) {
            throw ApiException::notFound('Academy');
        }
        $a['subscription'] = Subscription::state((int) $a['id']);
        $a['usage'] = Feature::summary((int) $a['id']);
        $a['history'] = DB::all('SELECT s.*, p.name AS package_name FROM owner_subscriptions s JOIN packages p ON p.id = s.package_id WHERE s.academy_id = ? ORDER BY s.id DESC', [$a['id']]);
        $a['payments'] = DB::all('SELECT sp.*, p.name AS package_name FROM subscription_payments sp JOIN packages p ON p.id = sp.package_id WHERE sp.academy_id = ? ORDER BY sp.id DESC', [$a['id']]);
        $a['branches'] = BranchService::summary((int) $a['id'], date('Y-m-01'), date('Y-m-d'));
        $a['counts'] = [
            'teachers' => (int) DB::val('SELECT COUNT(*) FROM teachers WHERE academy_id = ?', [$a['id']]),
            'batches' => (int) DB::val('SELECT COUNT(*) FROM batches WHERE academy_id = ?', [$a['id']]),
            'payments_month_paise' => (int) DB::val("SELECT COALESCE(SUM(amount_paise),0) FROM payments WHERE academy_id = ? AND status='success' AND paid_on >= ?", [$a['id'], date('Y-m-01')]),
        ];
        return Http::ok($a);
    }, $sa);

    $r->put('/admin/academies/{id}', static function (Ctx $c, array $p) {
        $aid = (int) $p['id'];
        $a = DB::one('SELECT * FROM academies WHERE id = ?', [$aid]) ?? throw ApiException::notFound('Academy');
        $in = Http::input();
        $d = Validator::make($in, array_intersect_key(['name' => 'required|max:160', 'city' => 'max:80', 'address' => 'max:500', 'email' => 'email', 'mobile' => 'phone', 'status' => 'in:active,inactive,suspended'], $in))->validated();
        DB::update('academies', $d, 'id = ?', [$aid]);
        if (isset($in['owner_name']) || isset($in['owner_email']) || isset($in['owner_status']) || isset($in['owner_mobile'])) {
            $u = [];
            if (!empty($in['owner_name'])) { $u['name'] = mb_substr((string) $in['owner_name'], 0, 120); }
            if (isset($in['owner_email'])) { $u['email'] = filter_var($in['owner_email'], FILTER_VALIDATE_EMAIL) ? strtolower($in['owner_email']) : null; }
            if (!empty($in['owner_mobile'])) { $u['mobile'] = Phone::normalize((string) $in['owner_mobile']) ?? throw ApiException::validation(['owner_mobile' => 'Invalid mobile.']); }
            if (in_array($in['owner_status'] ?? '', ['active', 'inactive', 'suspended'], true)) { $u['status'] = $in['owner_status']; }
            DB::update('users', $u, 'id = ?', [$a['owner_user_id']]);
            if (($u['status'] ?? 'active') !== 'active') {
                DB::exec('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$a['owner_user_id']]);
            }
        }
        if (isset($d['status']) && $d['status'] !== 'active') {
            // suspension blocks every login of the academy
            DB::exec('UPDATE api_tokens t JOIN users u ON u.id = t.user_id SET t.revoked_at = NOW() WHERE u.academy_id = ? AND u.role <> \'owner\' AND t.revoked_at IS NULL', [$aid]);
        }
        Subscription::flush();
        [$o, $n] = Audit::diff($a, $d);
        Audit::log($c, 'admin.academy_update', 'academies', $aid, $o, $n + array_intersect_key($in, array_flip(['owner_status'])), $aid);
        return Http::ok(null, 'Academy updated.');
    }, $sa);

    $r->post('/admin/academies/{id}/invite', static function (Ctx $c, array $p) {
        $uid = (int) DB::val('SELECT owner_user_id FROM academies WHERE id = ?', [(int) $p['id']]);
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$uid]) ?? throw ApiException::notFound('Class Admin');
        if ($u['status'] === 'active' && $u['password_hash']) {
            $token = Auth::oneTimeToken($uid, 'password_reset', 86400);
            $link = cnx_url('activate.php?reset=1&t=' . $token);
            NotificationService::direct((int) $p['id'], 'password_reset', ['recipient_name' => $u['name'], 'link' => $link, 'dedupe' => 'sar' . substr($token, 0, 8)], $u['mobile'] ? Phone::wa($u['country_code'], $u['mobile']) : null, $u['email'], $uid);
            Audit::log($c, 'admin.owner_reset_link', 'users', $uid, null, null, (int) $p['id']);
            return Http::ok(['link' => $link], 'Password reset link sent to the Class Admin.');
        }
        return Http::ok(AccountService::invite($uid), 'Activation link sent.');
    }, $sa);

    // -------------------------------------------------------------- packages
    $r->get('/admin/packages', static function () {
        $rows = DB::all('SELECT p.*, (SELECT COUNT(*) FROM owner_subscriptions s WHERE s.package_id = p.id AND s.is_current = 1) AS academies FROM packages p ORDER BY sort_order, id');
        foreach ($rows as &$p) {
            $p['features'] = DB::col('SELECT feature_key FROM package_features WHERE package_id = ? AND enabled = 1', [$p['id']]);
        }
        return Http::ok(['packages' => $rows, 'feature_labels' => Feature::FEATURES]);
    }, $sa);
    $savePackage = static function (Ctx $c, ?int $id) {
        $in = Http::input();
        $rules = ['name' => 'required|max:100', 'description' => 'max:1000', 'price' => 'required|money', 'billing_cycle' => 'required|in:monthly,quarterly,half_yearly,yearly,lifetime,custom', 'billing_days' => 'int', 'whatsapp_mode' => 'in:off,manual,api',
                  'trial_days' => 'int', 'student_limit' => 'int', 'teacher_limit' => 'int', 'activity_limit' => 'int', 'batch_limit' => 'int', 'branch_limit' => 'int',
                  'storage_limit_mb' => 'int', 'monthly_whatsapp_limit' => 'int', 'is_public' => 'bool', 'is_featured' => 'bool', 'sort_order' => 'int', 'status' => 'in:active,inactive'];
        $d = Validator::make($in, $id ? array_intersect_key($rules, $in) : $rules, ['price' => 'Price'])->validated();
        if (array_key_exists('price', $d)) {
            $d['price_paise'] = (int) $d['price'];
            unset($d['price']);
        }
        if (($d['billing_cycle'] ?? null) === 'custom' && (int) ($d['billing_days'] ?? 0) < 1) {
            throw ApiException::validation(['billing_days' => 'Enter the number of days in the custom billing cycle.']);
        }
        if (isset($d['name']) && !$id) {
            $d['slug'] = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($d['name'])), '-') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        }
        return DB::tx(static function () use ($c, $id, $d, $in) {
            if ($id) {
                $old = DB::one('SELECT * FROM packages WHERE id = ?', [$id]) ?? throw ApiException::notFound('Package');
                DB::update('packages', $d, 'id = ?', [$id]);
                [$o, $n] = Audit::diff($old, $d);
                Audit::log($c, 'admin.package_update', 'packages', $id, $o, $n);
            } else {
                $id = DB::insert('packages', $d);
                Audit::log($c, 'admin.package_create', 'packages', $id, null, $d);
            }
            if (isset($in['features']) && is_array($in['features'])) {
                DB::exec('DELETE FROM package_features WHERE package_id = ?', [$id]);
                foreach (array_intersect($in['features'], array_keys(Feature::FEATURES)) as $f) {
                    DB::insert('package_features', ['package_id' => $id, 'feature_key' => $f, 'enabled' => 1]);
                }
                Audit::log($c, 'admin.package_features', 'packages', $id, null, ['features' => array_values($in['features'])]);
            }
            // WhatsApp mode and the WhatsApp feature flag always agree
            $mode = DB::val('SELECT whatsapp_mode FROM packages WHERE id = ?', [$id]);
            if ($mode === 'off') {
                DB::exec("DELETE FROM package_features WHERE package_id = ? AND feature_key = 'whatsapp'", [$id]);
            } elseif (isset($d['whatsapp_mode'])) {
                DB::exec("INSERT IGNORE INTO package_features (package_id, feature_key, enabled) VALUES (?, 'whatsapp', 1)", [$id]);
            }
            Subscription::flush();
            return $id;
        });
    };
    $r->post('/admin/packages', static fn(Ctx $c) => Http::ok(['id' => $savePackage($c, null)], 'Package created.'), $sa);
    $r->put('/admin/packages/{id}', static fn(Ctx $c, array $p) => Http::ok(['id' => $savePackage($c, (int) $p['id'])], 'Package updated. Changes apply to all subscribers immediately.'), $sa);

    // ---------------------------------------------------------- subscriptions
    $r->post('/admin/academies/{id}/subscription', static function (Ctx $c, array $p) {
        $aid = (int) $p['id'];
        DB::one('SELECT id FROM academies WHERE id = ?', [$aid]) ?? throw ApiException::notFound('Academy');
        $in = Http::input();
        $action = $in['action'] ?? 'assign';
        switch ($action) {
            case 'assign':
            case 'trial':
                $d = Validator::make($in, ['package_id' => 'required|int', 'starts_on' => 'date', 'ends_on' => 'date', 'price' => 'money', 'notes' => 'max:255'])->validated();
                $pkg = DB::one('SELECT * FROM packages WHERE id = ?', [$d['package_id']]) ?? throw ApiException::notFound('Package');
                $custom = isset($in['custom_limits']) && is_array($in['custom_limits']) ? array_intersect_key($in['custom_limits'], Feature::LIMITS) : null;
                Subscription::assign($aid, (int) $pkg['id'], $action === 'trial' ? 'trial' : 'active', $d['starts_on'] ?? null, $d['ends_on'] ?? null, $d['price'] ?? (int) $pkg['price_paise'], $c, $d['notes'] ?? null, $custom);
                $msg = $action === 'trial' ? 'Trial activated.' : 'Package assigned.';
                break;
            case 'renew':
                $cur = Subscription::current($aid) ?? throw new ApiException('No subscription to renew. Assign a package first.', 409);
                $pkgId = (int) ($in['package_id'] ?? $cur['package_id']);
                $pkg = DB::one('SELECT * FROM packages WHERE id = ?', [$pkgId]);
                $price = isset($in['price']) && $in['price'] !== '' ? Money::toPaise($in['price']) : (int) $pkg['price_paise'];
                $subId = Subscription::renew($aid, $pkgId, $price, $c, 'Renewed by Super Admin');
                if (!empty($in['record_payment'])) {
                    $s = DB::one('SELECT * FROM owner_subscriptions WHERE id = ?', [$subId]);
                    DB::insert('subscription_payments', ['academy_id' => $aid, 'subscription_id' => $subId, 'package_id' => $pkgId, 'amount_paise' => $price, 'method' => $in['method'] ?? 'manual', 'reference' => $in['reference'] ?? null, 'status' => 'paid', 'period_from' => $s['starts_on'], 'period_to' => $s['ends_on'], 'paid_at' => date('Y-m-d H:i:s'), 'recorded_by' => $c->userId]);
                }
                $msg = 'Subscription renewed.';
                break;
            case 'extend':
                $cur = Subscription::current($aid) ?? throw new ApiException('No subscription.', 409);
                $days = max(1, min(3650, (int) ($in['days'] ?? 0)));
                $base = max($cur['ends_on'] ?? date('Y-m-d'), date('Y-m-d', strtotime('-1 day')));
                $new = date('Y-m-d', strtotime("$base +$days days"));
                DB::update('owner_subscriptions', ['ends_on' => $new, 'grace_until' => null, 'status' => $cur['status'] === 'trial' ? 'trial' : 'active'], 'id = ?', [$cur['id']]);
                Audit::log($c, 'admin.subscription_extend', 'owner_subscriptions', $cur['id'], ['ends_on' => $cur['ends_on']], ['ends_on' => $new], $aid);
                $msg = "Extended to $new.";
                break;
            case 'suspend':
            case 'unsuspend':
                $cur = Subscription::current($aid) ?? throw new ApiException('No subscription.', 409);
                $st = $action === 'suspend' ? 'suspended' : (($cur['ends_on'] && $cur['ends_on'] < date('Y-m-d')) ? 'expired' : 'active');
                DB::update('owner_subscriptions', ['status' => $st], 'id = ?', [$cur['id']]);
                Audit::log($c, "admin.subscription_$action", 'owner_subscriptions', $cur['id'], ['status' => $cur['status']], ['status' => $st], $aid);
                $msg = $action === 'suspend' ? 'Subscription suspended (data kept, account read-only).' : 'Subscription restored.';
                break;
            default:
                throw ApiException::validation(['action' => 'Unknown action.']);
        }
        Subscription::flush();
        return Http::ok(Subscription::state($aid), $msg);
    }, $sa);

    $r->get('/admin/subscription-payments', static function () {
        $w = '1=1';
        $p = [];
        if ($st = Http::query('status')) {
            $w .= ' AND sp.status = ?';
            $p[] = $st;
        }
        return Http::paged("SELECT sp.*, a.name AS academy_name, p.name AS package_name, u.name AS recorded_by_name FROM subscription_payments sp JOIN academies a ON a.id = sp.academy_id JOIN packages p ON p.id = sp.package_id LEFT JOIN users u ON u.id = sp.recorded_by WHERE $w ORDER BY sp.id DESC", $p, "SELECT COUNT(*) FROM subscription_payments sp WHERE $w");
    }, $sa);
    $r->post('/admin/subscription-payments', static function (Ctx $c) {
        $d = Validator::make(Http::input(), ['academy_id' => 'required|int', 'package_id' => 'required|int', 'amount' => 'required|money', 'method' => 'max:30', 'reference' => 'max:120', 'period_from' => 'date', 'period_to' => 'date', 'paid_on' => 'date'])->validated();
        DB::one('SELECT id FROM academies WHERE id = ?', [$d['academy_id']]) ?? throw ApiException::notFound('Academy');
        $sub = Subscription::current((int) $d['academy_id']);
        $id = DB::insert('subscription_payments', ['academy_id' => $d['academy_id'], 'subscription_id' => $sub['id'] ?? null, 'package_id' => $d['package_id'], 'amount_paise' => $d['amount'], 'method' => $d['method'] ?? 'manual',
            'reference' => $d['reference'] ?? null, 'status' => 'paid', 'period_from' => $d['period_from'] ?? null, 'period_to' => $d['period_to'] ?? null, 'paid_at' => ($d['paid_on'] ?? date('Y-m-d')) . ' ' . date('H:i:s'), 'recorded_by' => $c->userId]);
        Audit::log($c, 'admin.subscription_payment', 'subscription_payments', $id, null, $d, (int) $d['academy_id']);
        return Http::ok(['id' => $id], 'Payment recorded.');
    }, $sa);

    // ---------------------------------------------------------- integrations
    $r->get('/admin/integrations', static function () {
        $wa = DB::one('SELECT * FROM whatsapp_configurations WHERE id = 1');
        $fb = DB::one('SELECT * FROM firebase_configurations WHERE id = 1');
        $em = DB::one('SELECT * FROM email_configurations WHERE id = 1');
        $sms = DB::one('SELECT * FROM sms_configurations WHERE id = 1');
        $pg = DB::one("SELECT * FROM payment_gateway_configs WHERE academy_id IS NULL AND provider = 'razorpay'");
        $sa = json_decode((string) Crypto::decrypt($fb['service_account_enc'] ?? null), true);
        return Http::ok([
            'whatsapp' => ['mode' => $wa['mode'], 'business_account_id' => $wa['business_account_id'], 'phone_number_id' => $wa['phone_number_id'], 'api_version' => $wa['api_version'], 'webhook_verify_token' => $wa['webhook_verify_token'],
                           'access_token' => Crypto::mask($wa['access_token_enc']), 'app_secret' => Crypto::mask($wa['app_secret_enc']), 'rate_per_minute' => $wa['rate_per_minute'],
                           'last_test_at' => $wa['last_test_at'], 'last_test_result' => $wa['last_test_result'], 'webhook_url' => cnx_url('api/v1/webhooks/whatsapp'),
                           'templates' => DB::all("SELECT id, event_key, wa_template_name, wa_language, wa_params, body FROM notification_templates WHERE academy_id IS NULL AND channel = 'whatsapp' ORDER BY event_key")],
            'firebase' => ['mode' => $fb['mode'], 'project_id' => $fb['project_id'], 'service_account_email' => $sa['client_email'] ?? null, 'configured' => (bool) $sa, 'last_test_result' => $fb['last_test_result']],
            'email'    => ['mode' => $em['mode'], 'host' => $em['host'], 'port' => $em['port'], 'encryption' => $em['encryption'], 'username' => $em['username'], 'password' => Crypto::mask($em['password_enc']), 'from_email' => $em['from_email'], 'from_name' => $em['from_name'], 'last_test_result' => $em['last_test_result']],
            'sms'      => ['mode' => $sms['mode'], 'provider' => $sms['provider'], 'endpoint_url' => $sms['endpoint_url'], 'http_method' => $sms['http_method'], 'params_template' => $sms['params_template'], 'api_key' => Crypto::mask($sms['api_key_enc']), 'sender_id' => $sms['sender_id']],
            'razorpay' => ['key_id' => $pg['key_id'] ?? '', 'key_secret' => Crypto::mask($pg['key_secret_enc'] ?? null), 'webhook_secret' => Crypto::mask($pg['webhook_secret_enc'] ?? null), 'is_enabled' => (bool) ($pg['is_enabled'] ?? false), 'webhook_url' => cnx_url('api/v1/webhooks/razorpay/platform')],
            'ocr'      => ['provider' => Settings::get('ocr_provider', 'client'), 'google_vision_key' => Crypto::mask(Settings::get('google_vision_key_enc'))],
        ]);
    }, $sa);

    $r->post('/admin/integrations/{name}', static function (Ctx $c, array $p) {
        $in = Http::input();
        $secret = static fn(string $k, ?string $existing) => (isset($in[$k]) && trim((string) $in[$k]) !== '' && !str_contains((string) $in[$k], '•')) ? Crypto::encrypt(trim((string) $in[$k])) : $existing;
        $mode = in_array($in['mode'] ?? '', ['off', 'test', 'live'], true) ? $in['mode'] : null;
        switch ($p['name']) {
            case 'whatsapp':
                $cur = DB::one('SELECT * FROM whatsapp_configurations WHERE id = 1');
                DB::update('whatsapp_configurations', array_filter([
                    'mode' => $mode, 'business_account_id' => trim((string) ($in['business_account_id'] ?? $cur['business_account_id'])), 'phone_number_id' => trim((string) ($in['phone_number_id'] ?? $cur['phone_number_id'])),
                    'api_version' => preg_match('/^v\d+\.\d+$/', (string) ($in['api_version'] ?? '')) ? $in['api_version'] : $cur['api_version'],
                    'webhook_verify_token' => trim((string) ($in['webhook_verify_token'] ?? $cur['webhook_verify_token'])) ?: bin2hex(random_bytes(12)),
                    'access_token_enc' => $secret('access_token', $cur['access_token_enc']), 'app_secret_enc' => $secret('app_secret', $cur['app_secret_enc']),
                    'rate_per_minute' => max(1, (int) ($in['rate_per_minute'] ?? $cur['rate_per_minute'])),
                ], static fn($v) => $v !== null), 'id = 1');
                foreach ((array) ($in['templates'] ?? []) as $t) {
                    DB::update('notification_templates', ['wa_template_name' => mb_substr((string) ($t['wa_template_name'] ?? ''), 0, 120) ?: null, 'wa_language' => mb_substr((string) ($t['wa_language'] ?? 'en'), 0, 10)],
                        'id = ? AND academy_id IS NULL AND channel = \'whatsapp\'', [(int) ($t['id'] ?? 0)]);
                }
                break;
            case 'firebase':
                $cur = DB::one('SELECT * FROM firebase_configurations WHERE id = 1');
                $saEnc = $cur['service_account_enc'];
                $pid = trim((string) ($in['project_id'] ?? $cur['project_id']));
                if (!empty($in['service_account_json'])) {
                    $j = json_decode((string) $in['service_account_json'], true);
                    if (!$j || empty($j['private_key']) || empty($j['client_email'])) {
                        throw ApiException::validation(['service_account_json' => 'Paste the full service account JSON downloaded from Firebase.']);
                    }
                    $saEnc = Crypto::encrypt(json_encode($j));
                    $pid = $pid ?: $j['project_id'];
                }
                DB::update('firebase_configurations', array_filter(['mode' => $mode, 'project_id' => $pid, 'service_account_enc' => $saEnc], static fn($v) => $v !== null), 'id = 1');
                break;
            case 'email':
                $cur = DB::one('SELECT * FROM email_configurations WHERE id = 1');
                DB::update('email_configurations', array_filter([
                    'mode' => $mode, 'host' => trim((string) ($in['host'] ?? $cur['host'])), 'port' => (int) ($in['port'] ?? $cur['port']),
                    'encryption' => in_array($in['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $in['encryption'] : $cur['encryption'],
                    'username' => trim((string) ($in['username'] ?? $cur['username'])), 'password_enc' => $secret('password', $cur['password_enc']),
                    'from_email' => trim((string) ($in['from_email'] ?? $cur['from_email'])), 'from_name' => trim((string) ($in['from_name'] ?? $cur['from_name'])),
                ], static fn($v) => $v !== null), 'id = 1');
                break;
            case 'sms':
                $cur = DB::one('SELECT * FROM sms_configurations WHERE id = 1');
                if (!empty($in['params_template']) && json_decode((string) $in['params_template'], true) === null) {
                    throw ApiException::validation(['params_template' => 'Parameters must be valid JSON.']);
                }
                DB::update('sms_configurations', array_filter([
                    'mode' => $mode, 'provider' => $in['provider'] ?? $cur['provider'], 'endpoint_url' => $in['endpoint_url'] ?? $cur['endpoint_url'],
                    'http_method' => in_array($in['http_method'] ?? '', ['GET', 'POST'], true) ? $in['http_method'] : $cur['http_method'],
                    'params_template' => $in['params_template'] ?? $cur['params_template'], 'api_key_enc' => $secret('api_key', $cur['api_key_enc']), 'sender_id' => $in['sender_id'] ?? $cur['sender_id'],
                ], static fn($v) => $v !== null), 'id = 1');
                break;
            case 'razorpay':
                $cur = DB::one("SELECT * FROM payment_gateway_configs WHERE academy_id IS NULL AND provider = 'razorpay'");
                $row = ['provider' => 'razorpay', 'key_id' => trim((string) ($in['key_id'] ?? '')), 'key_secret_enc' => $secret('key_secret', $cur['key_secret_enc'] ?? null), 'webhook_secret_enc' => $secret('webhook_secret', $cur['webhook_secret_enc'] ?? null), 'is_enabled' => !empty($in['is_enabled']) ? 1 : 0];
                $cur ? DB::update('payment_gateway_configs', $row, 'id = ?', [$cur['id']]) : DB::insert('payment_gateway_configs', $row + ['academy_id' => null]);
                break;
            case 'ocr':
                Settings::set('ocr_provider', in_array($in['provider'] ?? '', ['client', 'google_vision'], true) ? $in['provider'] : 'client');
                $enc = $secret('google_vision_key', Settings::get('google_vision_key_enc'));
                Settings::set('google_vision_key_enc', $enc);
                break;
            default:
                throw ApiException::notFound('Integration');
        }
        Audit::log($c, 'admin.integration_' . $p['name'], null, null, null, ['mode' => $mode]);
        return Http::ok(null, 'Settings saved.');
    }, $sa);

    // send a test message through a channel
    $r->post('/admin/integrations/{name}/test', static function (Ctx $c, array $p) {
        $in = Http::input();
        $job = ['id' => 0, 'academy_id' => null, 'event_key' => 'test', 'recipient_name' => $c->user['name'], 'subject' => 'ClassNex test', 'body' => 'This is a test message from ClassNex. Your integration works!', 'payload' => '{}'];
        switch ($p['name']) {
            case 'whatsapp':
                $to = Phone::normalize((string) ($in['to'] ?? ''), Phone::code($in['country_code'] ?? '+91')) ?? throw ApiException::validation(['to' => 'Enter a WhatsApp number to test.']);
                $job['recipient'] = Phone::wa(Phone::code($in['country_code'] ?? '+91'), $to);
                // hello_world is pre-approved on every new WhatsApp Business account
                $job['payload'] = json_encode(['template' => $in['template'] ?? 'hello_world', 'language' => $in['language'] ?? 'en_US', 'params' => []]);
                $res = WhatsAppChannel::send($job);
                DB::update('whatsapp_configurations', ['last_test_at' => date('Y-m-d H:i:s'), 'last_test_result' => mb_substr($res['ok'] ? 'OK ' . ($res['id'] ?? '') . (!empty($res['simulated']) ? ' (test mode – not sent)' : '') : 'Failed: ' . ($res['error'] ?? ''), 0, 255)], 'id = 1');
                break;
            case 'email':
                $job['recipient'] = (string) ($in['to'] ?? $c->user['email'] ?? '');
                $res = EmailChannel::send($job);
                DB::update('email_configurations', ['last_test_result' => mb_substr($res['ok'] ? 'OK' . (!empty($res['simulated']) ? ' (test mode – not sent)' : '') : 'Failed: ' . ($res['error'] ?? ''), 0, 255)], 'id = 1');
                break;
            case 'firebase':
                $job['recipient'] = (string) $c->userId;
                $res = FcmChannel::send($job);
                DB::update('firebase_configurations', ['last_test_result' => mb_substr($res['ok'] ? 'OK' : 'Failed: ' . ($res['error'] ?? ''), 0, 255)], 'id = 1');
                break;
            case 'sms':
                $job['recipient'] = '+' . ltrim(Phone::code($in['country_code'] ?? '+91'), '+') . (Phone::normalize((string) ($in['to'] ?? '')) ?? throw ApiException::validation(['to' => 'Enter a mobile number.']));
                $res = SmsChannel::send($job);
                break;
            default:
                throw ApiException::notFound('Integration');
        }
        if (!$res['ok']) {
            throw new ApiException('Test failed: ' . ($res['error'] ?? 'unknown error'), 422);
        }
        return Http::ok($res, !empty($res['simulated']) ? 'Test passed in TEST mode (nothing was actually sent). Switch to LIVE to send real messages.' : 'Test message sent successfully.');
    }, $sa);

    // ----------------------------------------------------- templates & rules
    $r->get('/admin/templates', static fn() => Http::ok(['templates' => DB::all('SELECT * FROM notification_templates WHERE academy_id IS NULL ORDER BY event_key, channel'), 'events' => NotificationService::EVENTS + ['account_invite' => 'Account Invite', 'password_reset' => 'Password Reset', 'subscription_expiring' => 'Subscription Expiring']]), $sa);
    $r->put('/admin/templates/{id}', static function (Ctx $c, array $p) {
        $in = Http::input();
        $d = Validator::make($in, array_intersect_key(['title' => 'max:160', 'body' => 'required|max:4000', 'wa_template_name' => 'max:120', 'wa_language' => 'max:10', 'is_active' => 'bool'], $in))->validated();
        if (isset($in['wa_params'])) {
            $params = is_array($in['wa_params']) ? $in['wa_params'] : array_filter(array_map('trim', explode(',', (string) $in['wa_params'])));
            $d['wa_params'] = json_encode(array_values($params));
        }
        DB::update('notification_templates', $d, 'id = ? AND academy_id IS NULL', [(int) $p['id']]);
        Audit::log($c, 'admin.template_update', 'notification_templates', (int) $p['id']);
        return Http::ok(null, 'Template saved.');
    }, $sa);
    $r->get('/admin/reminder-rules', static fn() => Http::ok(DB::all('SELECT * FROM reminder_rules WHERE academy_id IS NULL ORDER BY offset_days')), $sa);
    $r->post('/admin/reminder-rules', static function (Ctx $c) {
        $offsets = array_values(array_unique(array_filter(array_map('intval', (array) (Http::input()['offsets'] ?? [])), static fn($o) => $o >= -30 && $o <= 60)));
        DB::tx(static function () use ($offsets) {
            DB::exec('DELETE FROM reminder_rules WHERE academy_id IS NULL');
            foreach ($offsets as $o) {
                DB::insert('reminder_rules', ['academy_id' => null, 'offset_days' => $o, 'is_enabled' => 1]);
            }
        });
        Audit::log($c, 'admin.reminder_rules', null, null, null, ['offsets' => $offsets]);
        return Http::ok(null, 'Default reminder schedule saved. It applies to newly generated dues.');
    }, $sa);

    // -------------------------------------------------------- queue monitor
    $r->get('/admin/queue', static function () {
        $w = '1=1';
        $p = [];
        foreach (['status', 'channel'] as $k) {
            if ($v = Http::query($k)) {
                $w .= " AND q.$k = ?";
                $p[] = $v;
            }
        }
        if ($a = (int) Http::query('academy_id')) {
            $w .= ' AND q.academy_id = ?';
            $p[] = $a;
        }
        $res = Http::paged("SELECT q.id, q.academy_id, a.name AS academy_name, q.event_key, q.channel, q.recipient, q.recipient_name, q.status, q.attempts, q.last_error, q.provider_message_id, q.created_at, q.sent_at, q.next_attempt_at
                            FROM notification_queue q LEFT JOIN academies a ON a.id = q.academy_id WHERE $w ORDER BY q.id DESC", $p, "SELECT COUNT(*) FROM notification_queue q WHERE $w");
        $res['meta']['paused'] = ['whatsapp' => Settings::get('queue_paused_whatsapp') === '1', 'push' => Settings::get('queue_paused_push') === '1', 'email' => Settings::get('queue_paused_email') === '1', 'sms' => Settings::get('queue_paused_sms') === '1'];
        $res['meta']['counts'] = DB::all('SELECT status, COUNT(*) AS n FROM notification_queue GROUP BY status');
        return $res;
    }, $sa);
    $r->get('/admin/queue/{id}/logs', static fn(Ctx $c, array $p) => Http::ok(DB::all('SELECT * FROM notification_logs WHERE queue_id = ? ORDER BY id', [(int) $p['id']])), $sa);
    $r->post('/admin/queue/action', static function (Ctx $c) {
        $in = Http::input();
        $ids = array_map('intval', (array) ($in['ids'] ?? []));
        switch ($in['action'] ?? '') {
            case 'retry':
                $n = QueueProcessor::retry($ids);
                break;
            case 'cancel':
                $n = $ids ? DB::exec("UPDATE notification_queue SET status = 'cancelled' WHERE status IN ('pending','failed','paused') AND id IN (" . DB::inList($ids) . ')', $ids) : 0;
                break;
            case 'pause':
            case 'resume':
                $ch = $in['channel'] ?? '';
                if (!in_array($ch, ['whatsapp', 'push', 'email', 'sms'], true)) {
                    throw ApiException::validation(['channel' => 'Choose a channel.']);
                }
                Settings::set("queue_paused_$ch", $in['action'] === 'pause' ? '1' : '0');
                $n = 1;
                break;
            case 'run':
                $n = QueueProcessor::run(Settings::int('queue_batch_size', 50));
                break;
            default:
                throw ApiException::validation(['action' => 'Unknown action.']);
        }
        Audit::log($c, 'admin.queue_' . ($in['action'] ?? ''), null, null, null, ['ids' => $ids, 'channel' => $in['channel'] ?? null]);
        return Http::ok($n, 'Done.');
    }, $sa);

    // ---------------------------------------------------------- website CMS
    $r->get('/admin/cms', static function () {
        $settings = [];
        foreach (DB::all('SELECT setting_key, setting_value FROM website_settings') as $row) {
            $settings[$row['setting_key']] = json_decode((string) $row['setting_value'], true) ?? $row['setting_value'];
        }
        return Http::ok(['settings' => $settings, 'pages' => DB::all('SELECT id, slug, title, status, meta_title, meta_description, updated_at FROM cms_pages ORDER BY slug'), 'testimonials' => DB::all('SELECT * FROM testimonials ORDER BY sort_order, id')]);
    }, $sa);
    $r->post('/admin/cms/settings', static function (Ctx $c) {
        $allowed = ['hero', 'features', 'categories', 'steps', 'stats', 'about', 'contact', 'social', 'seo', 'footer', 'banners', 'faq'];
        $in = Http::input();
        foreach ($allowed as $k) {
            if (array_key_exists($k, $in)) {
                $v = is_string($in[$k]) ? json_decode($in[$k], true) : $in[$k];
                if ($v === null) {
                    throw ApiException::validation([$k => 'Invalid content for ' . $k . '.']);
                }
                Settings::setWeb($k, $v);
            }
        }
        if ($img = Upload::storePublicImage('image', 'cms', 1600)) {
            return Http::ok(['url' => cnx_url($img), 'path' => $img], 'Image uploaded.');
        }
        Audit::log($c, 'admin.cms_settings', null, null, null, ['keys' => array_values(array_intersect($allowed, array_keys($in)))]);
        return Http::ok(null, 'Website content saved.');
    }, $sa);
    $r->get('/admin/cms/pages/{id}', static fn(Ctx $c, array $p) => Http::ok(DB::one('SELECT * FROM cms_pages WHERE id = ?', [(int) $p['id']]) ?? throw ApiException::notFound('Page')), $sa);
    $r->post('/admin/cms/pages', static function (Ctx $c) {
        $in = Http::input();
        $d = Validator::make($in, ['slug' => 'required|max:80', 'title' => 'required|max:160', 'content' => 'max:200000', 'meta_title' => 'max:160', 'meta_description' => 'max:300', 'status' => 'in:published,draft'])->validated();
        $d['slug'] = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($d['slug'])), '-');
        $d['content'] = HtmlSanitizer::clean((string) ($d['content'] ?? ''));
        $id = (int) ($in['id'] ?? 0);
        $id ? DB::update('cms_pages', $d, 'id = ?', [$id]) : ($id = DB::insert('cms_pages', $d));
        Audit::log($c, 'admin.cms_page', 'cms_pages', $id);
        return Http::ok(['id' => $id, 'url' => cnx_url('page.php?p=' . $d['slug'])], 'Page saved.');
    }, $sa);
    $r->post('/admin/testimonials', static function (Ctx $c) {
        $in = Http::input();
        $d = Validator::make($in, ['name' => 'required|max:120', 'designation' => 'max:160', 'message' => 'required|max:1000', 'rating' => 'int', 'sort_order' => 'int', 'status' => 'in:active,inactive'])->validated();
        $id = (int) ($in['id'] ?? 0);
        $id ? DB::update('testimonials', $d, 'id = ?', [$id]) : ($id = DB::insert('testimonials', $d));
        return Http::ok(['id' => $id], 'Saved.');
    }, $sa);
    $r->delete('/admin/testimonials/{id}', static fn(Ctx $c, array $p) => Http::ok(DB::exec('DELETE FROM testimonials WHERE id = ?', [(int) $p['id']]), 'Deleted.'), $sa);

    $r->get('/admin/enquiries', static fn() => Http::paged('SELECT * FROM enquiries' . (Http::query('status') ? ' WHERE status = ?' : '') . ' ORDER BY id DESC', Http::query('status') ? [Http::query('status')] : []), $sa);
    $r->put('/admin/enquiries/{id}', static function (Ctx $c, array $p) {
        $st = Http::input()['status'] ?? '';
        if (!in_array($st, ['new', 'contacted', 'converted', 'closed'], true)) {
            throw ApiException::validation(['status' => 'Invalid status.']);
        }
        DB::update('enquiries', ['status' => $st], 'id = ?', [(int) $p['id']]);
        return Http::ok(null, 'Updated.');
    }, $sa);

    $r->get('/admin/support', static fn() => Http::paged("SELECT t.*, a.name AS academy_name, u.name AS user_name FROM support_tickets t LEFT JOIN academies a ON a.id = t.academy_id LEFT JOIN users u ON u.id = t.user_id WHERE t.scope = 'platform'" . (Http::query('status') ? ' AND t.status = ?' : '') . " ORDER BY t.status = 'closed', t.updated_at DESC", Http::query('status') ? [Http::query('status')] : []), $sa);

    $r->get('/admin/audit-logs', static function () {
        $w = '1=1';
        $p = [];
        if ($a = (int) Http::query('academy_id')) {
            $w .= ' AND l.academy_id = ?';
            $p[] = $a;
        }
        if ($q = Http::query('action')) {
            $w .= ' AND l.action LIKE ?';
            $p[] = "%$q%";
        }
        return Http::paged("SELECT l.*, u.name AS user_name, a.name AS academy_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN academies a ON a.id = l.academy_id WHERE $w ORDER BY l.id DESC", $p, "SELECT COUNT(*) FROM audit_logs l WHERE $w");
    }, $sa);

    // ------------------------------------------------------- global settings
    $r->get('/admin/settings', static fn() => Http::ok(array_column(DB::all("SELECT setting_key, setting_value FROM system_settings WHERE setting_key NOT LIKE '%_enc' AND setting_key NOT IN ('fcm_token_cache')"), 'setting_value', 'setting_key')), $sa);
    $r->post('/admin/settings', static function (Ctx $c) {
        $allowed = ['platform_name', 'support_email', 'support_phone', 'default_country_code', 'grace_days', 'invoice_advance_days', 'queue_batch_size', 'attendance_notify_quiet_minutes', 'document_retention_days', 'self_registration_enabled', 'default_trial_package_id'];
        foreach (array_intersect_key(Http::input(), array_flip($allowed)) as $k => $v) {
            Settings::set($k, mb_substr(trim((string) $v), 0, 255));
        }
        Audit::log($c, 'admin.settings', null, null, null, array_intersect_key(Http::input(), array_flip($allowed)));
        return Http::ok(null, 'Settings saved.');
    }, $sa);

    // ---------------------------------------------------------- system health
    $r->get('/admin/health', static function () {
        $storage = Config::storage();
        return Http::ok([
            'php_version'   => PHP_VERSION,
            'db_version'    => DB::val('SELECT VERSION()'),
            'extensions'    => array_map(static fn($e) => ['name' => $e, 'loaded' => extension_loaded($e)], ['pdo_mysql', 'sodium', 'gd', 'curl', 'mbstring', 'openssl', 'zip', 'fileinfo', 'intl']),
            'storage_writable' => is_writable($storage),
            'disk_free_mb'  => @disk_free_space($storage) ? (int) (disk_free_space($storage) / 1048576) : null,
            'app_key_set'   => (bool) Config::get('app_key'),
            'debug'         => (bool) Config::get('debug'),
            'https'         => str_starts_with((string) Config::get('app_url'), 'https://'),
            'cron'          => DB::all('SELECT task, MAX(started_at) AS last_run, SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY id DESC), ",", 1) AS last_status FROM cron_runs GROUP BY task'),
            'cron_recent'   => DB::all('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 30'),
            'queue'         => DB::all('SELECT channel, status, COUNT(*) AS n FROM notification_queue GROUP BY channel, status'),
            'migrations'    => DB::col('SELECT version FROM schema_migrations ORDER BY version'),
            'backups'       => array_map(static fn($f) => ['name' => basename($f), 'size_kb' => (int) (filesize($f) / 1024), 'created' => date('Y-m-d H:i', filemtime($f))], array_reverse(glob(Config::storage('private/backups/*.sql.gz')) ?: [])),
            'error_log_tail'=> is_file(Config::storage('logs/php-error.log')) ? array_slice(file(Config::storage('logs/php-error.log')) ?: [], -30) : [],
        ]);
    }, $sa);
    $r->post('/admin/backups', static function (Ctx $c) {
        $file = BackupService::create();
        Audit::log($c, 'admin.backup', null, null, null, ['file' => basename($file)]);
        return Http::ok(['file' => basename($file)], 'Backup created.');
    }, $sa);
    $r->get('/admin/backups/{name}', static function (Ctx $c, array $p) {
        $name = basename($p['name']);
        $path = Config::storage('private/backups/' . $name);
        if (!preg_match('/^classnex-[\w-]+\.sql\.gz$/', $name) || !is_file($path)) {
            throw ApiException::notFound('Backup');
        }
        Audit::log($c, 'admin.backup_download', null, null, null, ['file' => $name]);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }, $sa);
};
