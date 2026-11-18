<?php
declare(strict_types=1);

return static function (Router $r): void {
    $r->get('/public/packages', static function () {
        $rows = DB::all("SELECT id, name, slug, description, price_paise, billing_cycle, billing_days, trial_days, student_limit, whatsapp_mode, is_featured
                         FROM packages WHERE status = 'active' AND is_public = 1 ORDER BY sort_order, price_paise");
        foreach ($rows as &$p) {
            $p['features'] = DB::col('SELECT feature_key FROM package_features WHERE package_id = ? AND enabled = 1', [$p['id']]);
        }
        return Http::ok(['packages' => $rows, 'feature_labels' => Feature::FEATURES]);
    }, ['auth' => false]);

    $r->post('/public/enquiry', static function () {
        RateLimiter::hit('enquiry:' . Http::ip(), 5, 3600);
        $in = Http::input();
        if (!empty($in['website'])) { // honeypot
            return Http::ok(null, 'Thank you! We will contact you shortly.');
        }
        $d = Validator::make($in, ['name' => 'required|max:120', 'mobile' => 'phone', 'email' => 'email|max:160', 'academy_name' => 'max:160', 'category' => 'max:80', 'message' => 'max:2000', 'source' => 'max:30'])->validated();
        if (empty($d['mobile']) && empty($d['email'])) {
            throw ApiException::validation(['mobile' => 'Enter a mobile number or email so we can reach you.']);
        }
        DB::insert('enquiries', $d + ['ip' => Http::ip()]);
        return Http::ok(null, 'Thank you! Our team will contact you shortly.');
    }, ['auth' => false]);

    $r->post('/public/register-owner', static function () {
        RateLimiter::hit('register:' . Http::ip(), 5, 3600);
        if (Settings::get('self_registration_enabled', '1') !== '1') {
            throw new ApiException('Online registration is closed. Please contact us.', 403);
        }
        $in = Http::input();
        if (!empty($in['website'])) {
            throw ApiException::validation(['name' => 'Invalid submission.']);
        }
        if (empty($in['accept_terms'])) {
            throw ApiException::validation(['accept_terms' => 'Please accept the Terms and Privacy Policy.']);
        }
        $res = AcademyService::createOwner($in, null, true);
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$res['user_id']]);
        if (($in['client'] ?? 'web') === 'web') {
            Auth::loginSession($user);
            return Http::ok(['redirect' => cnx_url('owner/#/setup')], 'Your academy is ready! Your free trial has started.');
        }
        return Http::ok(['token' => Auth::issueToken($user, 'android')], 'Your academy is ready! Your free trial has started.');
    }, ['auth' => false]);

    // online admission form for an academy
    $r->get('/public/academy/{slug}', static function ($_, array $p) {
        $a = DB::one("SELECT id, name, slug, logo_path, address, city, mobile, email, online_admission_enabled FROM academies WHERE slug = ? AND status = 'active'", [$p['slug']]);
        if (!$a || !$a['online_admission_enabled'] || !Feature::has((int) $a['id'], 'online_admission') || !Subscription::state((int) $a['id'])['writable']) {
            throw new ApiException('Online admission is not available for this academy.', 404);
        }
        $a['logo_url'] = $a['logo_path'] ? cnx_url($a['logo_path']) : null;
        $a['activities'] = DB::all("SELECT id, name, description, monthly_fee_paise, registration_fee_paise FROM activities WHERE academy_id = ? AND status = 'active' ORDER BY name", [$a['id']]);
        $a['batches'] = DB::all("SELECT b.id, b.activity_id, b.name, b.start_time, b.end_time FROM batches b WHERE b.academy_id = ? AND b.status = 'active' ORDER BY b.name", [$a['id']]);
        $a['fields'] = Settings::academy((int) $a['id'])['online_admission_fields'];
        unset($a['id'], $a['logo_path']);
        return Http::ok($a);
    }, ['auth' => false]);

    $r->post('/public/academy/{slug}/admission', static function ($_, array $p) {
        RateLimiter::hit('admission:' . Http::ip(), 10, 3600);
        $a = DB::one("SELECT id, owner_user_id, online_admission_enabled FROM academies WHERE slug = ? AND status = 'active'", [$p['slug']]);
        if (!$a || !$a['online_admission_enabled'] || !Feature::has((int) $a['id'], 'online_admission')) {
            throw new ApiException('Online admission is not available for this academy.', 404);
        }
        $aid = (int) $a['id'];
        $in = Http::input();
        if (!empty($in['website'])) {
            return Http::ok(null, 'Submitted.');
        }
        $cc = Phone::code($in['country_code'] ?? '+91');
        $d = Validator::make($in, [
            'student_name' => 'required|max:120', 'parent_name' => 'max:120', 'whatsapp' => 'required|phone', 'email' => 'email|max:160',
            'dob' => 'date', 'address' => 'max:500', 'activity_id' => 'int', 'batch_id' => 'int', 'message' => 'max:1000',
        ], ['whatsapp' => 'WhatsApp number', 'student_name' => 'Student name'])->validated();
        $wa = Phone::normalize($d['whatsapp'], $cc);
        if (!$wa) {
            throw ApiException::validation(['whatsapp' => 'Enter a valid WhatsApp number.']);
        }
        if (empty($in['consent_given'])) {
            throw ApiException::validation(['consent_given' => 'Please confirm parent / guardian consent.']);
        }
        if (!empty($d['activity_id']) && !DB::val('SELECT 1 FROM activities WHERE id = ? AND academy_id = ?', [$d['activity_id'], $aid])) {
            unset($d['activity_id']);
        }
        if (!empty($d['batch_id']) && !DB::val('SELECT 1 FROM batches WHERE id = ? AND academy_id = ?', [$d['batch_id'], $aid])) {
            unset($d['batch_id']);
        }
        $photo = Upload::storePhoto('photo', $aid, 'admissions');
        $doc = Feature::has($aid, 'documents') ? Upload::storeDocument('document', $aid) : null;
        $id = DB::insert('admissions', $d + [
            'academy_id' => $aid, 'country_code' => $cc, 'whatsapp' => $wa, 'photo_path' => $photo, 'document_path' => $doc['path'] ?? null,
            'consent_given' => 1, 'status' => 'pending', 'ip' => Http::ip(),
        ]);
        if ($a['owner_user_id']) {
            NotificationService::toUsers($aid, 'manual_message', [(int) $a['owner_user_id']], ['title' => 'New online admission', 'message' => "{$d['student_name']} has applied online. Review it in Admissions."], ['channels' => ['inapp', 'push'], 'dedupe' => "adm$id"]);
        }
        return Http::ok(['reference' => 'ADM' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)], 'Application submitted! The academy will contact you on WhatsApp after review.');
    }, ['auth' => false]);

    $r->post('/ocr/parse', static function (Ctx $c) {
        $text = (string) (Http::input()['text'] ?? '');
        if (mb_strlen($text) > 20000) {
            throw ApiException::validation(['text' => 'Text too long.']);
        }
        return Http::ok(OcrService::parse($text));
    }, ['write' => false]);

    $r->post('/ocr/extract', static function (Ctx $c) {
        $f = Upload::read('image', 8_388_608);
        if (!$f || !in_array($f['mime'], Upload::IMAGE_MIMES, true)) {
            throw ApiException::validation(['image' => 'Upload a photo of the document.']);
        }
        $text = OcrService::visionText(Upload::compressImage($f['bytes'], 2000, 85));
        if ($text === null) {
            throw new ApiException('Server OCR is not configured. The app reads the document on your device instead.', 409, [], 'ocr_unavailable');
        }
        return Http::ok(OcrService::parse($text) + ['source' => 'server']);
    }, ['write' => false]);
};
