<?php
declare(strict_types=1);

/** Students, parents, documents and online admissions. */
return static function (Router $r): void {
    $staff = ['roles' => ['owner', 'teacher']];

    $r->get('/students', static function (Ctx $c) {
        $aid = $c->academy();
        $w = 's.academy_id = ?';
        $p = [$aid];
        if ($q = Http::query('q')) {
            $w .= ' AND (s.name LIKE ? OR s.student_code LIKE ? OR s.whatsapp LIKE ? OR s.guardian_name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            array_push($p, $like, $like, $like, $like);
        }
        $status = Http::query('status', 'active');
        if ($status !== 'all') {
            $w .= ' AND s.status = ?';
            $p[] = $status;
        }
        if ($br = Http::query('branch_id')) {
            if ($br === 'none') {
                $w .= ' AND s.branch_id IS NULL';
            } else {
                $w .= ' AND s.branch_id = ?';
                $p[] = (int) $br;
            }
        }
        foreach (['batch_id' => 'm.batch_id', 'activity_id' => 'm.activity_id'] as $k => $col) {
            if ($v = (int) Http::query($k)) {
                $w .= " AND EXISTS (SELECT 1 FROM student_activity_mapping m WHERE m.student_id = s.id AND m.status = 'active' AND $col = ?)";
                $p[] = $v;
            }
        }
        $scope = $c->batchScope();
        if ($scope !== null) {
            $w .= ' AND EXISTS (SELECT 1 FROM student_activity_mapping m WHERE m.student_id = s.id AND m.status = \'active\' AND m.batch_id IN (' . DB::inList($scope) . '))';
            array_push($p, ...$scope);
        }
        $fees = $c->can('fees.view');
        if ($fees && Http::query('dues') === '1') {
            $w .= " AND EXISTS (SELECT 1 FROM fee_invoices i WHERE i.student_id = s.id AND i.status IN ('unpaid','partial'))";
        }
        $sql = "SELECT s.id, s.student_code, s.name, s.guardian_name, s.branch_id, (SELECT name FROM academy_branches br WHERE br.id = s.branch_id) AS branch_name, s.country_code, s.whatsapp, s.status, s.admission_date, s.photo_path IS NOT NULL AS has_photo, s.is_minor,
                  (SELECT GROUP_CONCAT(CONCAT(a.name, IF(b.name IS NULL,'',CONCAT(' · ', b.name))) SEPARATOR ', ') FROM student_activity_mapping m JOIN activities a ON a.id = m.activity_id LEFT JOIN batches b ON b.id = m.batch_id WHERE m.student_id = s.id AND m.status = 'active') AS enrolled_in"
            . ($fees ? ", (SELECT COALESCE(SUM(i.total_paise - i.paid_paise),0) FROM fee_invoices i WHERE i.student_id = s.id AND i.status IN ('unpaid','partial')) AS pending_paise" : '')
            . " FROM students s WHERE $w ORDER BY " . (Http::query('sort') === 'recent' ? 's.id DESC' : 's.name');
        $res = Http::paged($sql, $p, "SELECT COUNT(*) FROM students s WHERE $w");
        foreach ($res['data'] as &$s) {
            $s['photo_url'] = $s['has_photo'] ? cnx_url('api/v1/files/student-photo/' . $s['id']) : null;
        }
        return $res;
    }, $staff + ['perm' => 'students.view']);

    $r->post('/students', static function (Ctx $c) {
        return Http::ok(StudentService::create($c, Http::input()), 'Student registered successfully.');
    }, $staff + ['perm' => 'students.manage']);

    $r->get('/students/{id}', static fn(Ctx $c, array $p) => Http::ok(StudentService::get($c, (int) $p['id'])), $staff + ['perm' => 'students.view']);

    $r->post('/students/{id}', static function (Ctx $c, array $p) {
        $id = (int) $p['id'];
        $s = $c->assertStudent($id);
        $in = Http::input();
        $rules = ['name' => 'required|max:120', 'whatsapp' => 'required|phone', 'email' => 'email|max:160', 'dob' => 'date', 'gender' => 'in:male,female,other',
                  'guardian_name' => 'max:120', 'guardian_relation' => 'max:30', 'address' => 'max:500', 'school_name' => 'max:160', 'blood_group' => 'max:5',
                  'medical_notes' => 'max:255', 'emergency_contact' => 'phone', 'alt_mobile' => 'phone', 'notes' => 'max:1000', 'is_minor' => 'bool',
                  'status' => 'in:active,inactive,left', 'whatsapp_opt_in' => 'bool', 'consent_given' => 'bool', 'admission_date' => 'date', 'branch_id' => 'int'];
        $d = Validator::make($in, array_intersect_key($rules, $in))->validated();
        if (isset($d['whatsapp'])) {
            $cc = Phone::code($in['country_code'] ?? $s['country_code']);
            $d['whatsapp'] = Phone::normalize($d['whatsapp'], $cc) ?? throw ApiException::validation(['whatsapp' => 'Enter a valid WhatsApp number.']);
            $d['country_code'] = $cc;
        }
        if (!empty($d['consent_given']) && !$s['consent_given']) {
            $d['consent_at'] = date('Y-m-d H:i:s');
            $d['consent_by'] = $d['guardian_name'] ?? $s['guardian_name'] ?? $s['name'];
        }
        if (!empty($d['branch_id']) && !DB::val("SELECT 1 FROM academy_branches WHERE id = ? AND academy_id = ?", [$d['branch_id'], $c->academy()])) {
            throw ApiException::validation(['branch_id' => 'Select a valid branch.']);
        }
        $reactivating = ($d['status'] ?? null) === 'active' && $s['status'] !== 'active';
        if ($photo = Upload::storePhoto('photo', $c->academy())) {
            $d['photo_path'] = $photo;
            Upload::delete($s['photo_path']);
        }
        DB::tx(static function () use ($c, $id, $d, $s, $reactivating) {
            if ($reactivating) {
                Feature::assertStudentCapacity($c->academy()); // re-activation uses a seat of the shared quota
            }
            DB::update('students', $d, 'id = ?', [$id]);
            if (isset($d['status']) && $d['status'] !== 'active' && $s['status'] === 'active') {
                // leaving: close enrolments and stop future billing — history stays
                DB::exec("UPDATE student_activity_mapping SET status = 'completed', end_date = CURDATE() WHERE student_id = ? AND status = 'active'", [$id]);
                DB::exec("UPDATE student_fee_assignments SET status = 'stopped', end_date = CURDATE() WHERE student_id = ? AND status IN ('active','paused')", [$id]);
            }
            [$o, $n] = Audit::diff($s, $d);
            Audit::log($c, 'student.update', 'students', $id, $o, $n);
        });
        return Http::ok(StudentService::get($c, $id), 'Student updated.');
    }, $staff + ['perm' => 'students.manage']);

    $r->post('/students/{id}/branch', static function (Ctx $c, array $p) {
        $in = Http::input();
        return Http::ok(StudentService::changeBranch($c, (int) $p['id'], (int) ($in['branch_id'] ?? 0), !empty($in['batch_id']) ? (int) $in['batch_id'] : null, $in['date'] ?? null), 'Student moved to the new branch. The student count is unchanged.');
    }, ['roles' => ['owner'], 'perm' => 'students.manage']);

    $r->post('/students/{id}/transfer', static function (Ctx $c, array $p) {
        $in = Http::input();
        return Http::ok(StudentService::transfer($c, (int) $p['id'], (int) ($in['batch_id'] ?? 0), $in['date'] ?? null), 'Batch changed.');
    }, $staff + ['perm' => 'students.manage']);

    $r->post('/students/{id}/enroll', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        $aid = $c->academy();
        $in = Http::input();
        $act = Crud::find('activities', (int) ($in['activity_id'] ?? 0), $aid, 'Activity');
        $batch = !empty($in['batch_id']) ? DB::one('SELECT * FROM batches WHERE id = ? AND activity_id = ? AND academy_id = ?', [(int) $in['batch_id'], $act['id'], $aid]) : null;
        if (DB::val("SELECT 1 FROM student_activity_mapping WHERE student_id = ? AND activity_id = ? AND status = 'active'", [$sid, $act['id']])) {
            throw new ApiException('Already enrolled in this activity. Use "Change batch" instead.', 409);
        }
        $start = $in['start_date'] ?? date('Y-m-d');
        $eid = DB::insert('student_activity_mapping', ['academy_id' => $aid, 'student_id' => $sid, 'activity_id' => $act['id'], 'batch_id' => $batch['id'] ?? null, 'start_date' => $start]);
        $monthly = $batch && $batch['monthly_fee_paise'] !== null ? (int) $batch['monthly_fee_paise'] : (int) $act['monthly_fee_paise'];
        if (!empty($in['monthly_fee'])) {
            $monthly = Money::toPaise($in['monthly_fee']);
        }
        if ($monthly > 0 && filter_var($in['assign_fees'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            BillingService::assign($aid, $sid, ['enrollment_id' => $eid, 'name' => $act['name'] . ' Monthly Fee', 'fee_type' => 'recurring', 'frequency_months' => 1, 'amount_paise' => $monthly, 'start_date' => $start], $c);
        }
        Audit::log($c, 'student.enroll', 'students', $sid, null, ['activity' => $act['name']]);
        return Http::ok(StudentService::get($c, $sid), 'Enrolled in ' . $act['name'] . '.');
    }, ['roles' => ['owner'], 'perm' => 'students.manage']);

    $r->post('/students/{id}/invite', static fn(Ctx $c, array $p) => Http::ok(StudentService::inviteFamily($c, (int) $p['id']), 'Activation link sent to the family.'), $staff + ['perm' => 'students.manage']);

    // ------------------------------------------------------------------ parents
    $r->get('/parents', static function (Ctx $c) {
        $w = 'p.academy_id = ?';
        $prm = [$c->academy()];
        if ($q = Http::query('q')) {
            $w .= ' AND (p.name LIKE ? OR p.whatsapp LIKE ?)';
            array_push($prm, "%$q%", "%$q%");
        }
        return Http::paged("SELECT p.*, u.status AS login_status, (SELECT GROUP_CONCAT(s.name SEPARATOR ', ') FROM parent_student_mapping m JOIN students s ON s.id = m.student_id WHERE m.parent_id = p.id) AS children
                            FROM parents p LEFT JOIN users u ON u.id = p.user_id WHERE $w ORDER BY p.name", $prm, "SELECT COUNT(*) FROM parents p WHERE $w");
    }, ['roles' => ['owner'], 'perm' => 'students.view']);

    $r->post('/students/{id}/parents', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        $aid = $c->academy();
        $in = Http::input();
        $cc = Phone::code($in['country_code'] ?? '+91');
        $d = Validator::make($in, ['name' => 'required|max:120', 'whatsapp' => 'required|phone', 'email' => 'email|max:160', 'relation' => 'max:30', 'occupation' => 'max:120'])->validated();
        $wa = Phone::normalize($d['whatsapp'], $cc) ?? throw ApiException::validation(['whatsapp' => 'Enter a valid WhatsApp number.']);
        $pid = DB::val('SELECT id FROM parents WHERE academy_id = ? AND country_code = ? AND whatsapp = ?', [$aid, $cc, $wa]);
        if (!$pid) {
            $pid = DB::insert('parents', ['academy_id' => $aid, 'name' => $d['name'], 'country_code' => $cc, 'whatsapp' => $wa, 'email' => $d['email'] ?? null, 'occupation' => $d['occupation'] ?? null]);
        }
        DB::exec('INSERT IGNORE INTO parent_student_mapping (parent_id, student_id, relation, is_primary) VALUES (?,?,?,0)', [$pid, $sid, $d['relation'] ?? 'Parent']);
        Audit::log($c, 'parent.link', 'students', $sid, null, ['parent_id' => $pid]);
        return Http::ok(StudentService::get($c, $sid), 'Parent linked. Children with the same parent number are grouped automatically.');
    }, $staff + ['perm' => 'students.manage']);

    $r->delete('/students/{id}/parents/{pid}', static function (Ctx $c, array $p) {
        $c->assertStudent((int) $p['id']);
        Crud::find('parents', (int) $p['pid'], $c->academy(), 'Parent');
        DB::exec('DELETE FROM parent_student_mapping WHERE parent_id = ? AND student_id = ?', [(int) $p['pid'], (int) $p['id']]);
        Audit::log($c, 'parent.unlink', 'students', (int) $p['id'], ['parent_id' => (int) $p['pid']]);
        return Http::ok(null, 'Parent unlinked.');
    }, ['roles' => ['owner'], 'perm' => 'students.manage']);

    $r->put('/parents/{id}', static function (Ctx $c, array $p) {
        $pr = Crud::find('parents', (int) $p['id'], $c->academy(), 'Parent');
        $in = Http::input();
        $d = Validator::make($in, array_intersect_key(['name' => 'required|max:120', 'email' => 'email|max:160', 'occupation' => 'max:120', 'address' => 'max:500', 'whatsapp_opt_in' => 'bool', 'whatsapp' => 'phone'], $in))->validated();
        if (isset($d['whatsapp'])) {
            $d['whatsapp'] = Phone::normalize($d['whatsapp'], $pr['country_code']) ?? throw ApiException::validation(['whatsapp' => 'Invalid number.']);
        }
        DB::update('parents', $d, 'id = ?', [$pr['id']]);
        if ($pr['user_id']) {
            DB::update('users', array_intersect_key($d + ['mobile' => $d['whatsapp'] ?? $pr['whatsapp']], array_flip(['name', 'email', 'mobile'])), 'id = ?', [$pr['user_id']]);
        }
        return Http::ok(null, 'Parent updated.');
    }, ['roles' => ['owner'], 'perm' => 'students.manage']);

    // ---------------------------------------------------------------- documents
    $r->get('/students/{id}/documents', static function (Ctx $c, array $p) {
        $c->assertStudent((int) $p['id']);
        return Http::ok(DB::all('SELECT id, doc_type, title, original_name, mime, size_bytes, ocr_fields, retention_until, created_at FROM student_documents WHERE student_id = ? AND deleted_at IS NULL ORDER BY id DESC', [(int) $p['id']]));
    }, ['roles' => ['owner', 'teacher'], 'perm' => 'documents.manage', 'feature' => 'documents']);

    $r->post('/students/{id}/documents', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        $aid = $c->academy();
        $in = Http::input();
        $f = Upload::read('file');
        if (!$f) {
            throw ApiException::validation(['file' => 'Choose a document to upload.']);
        }
        Feature::assertLimit($aid, 'storage_mb', (int) ceil($f['size'] / 1048576));
        $stored = Upload::storeDocument('file', $aid);
        $type = in_array($in['doc_type'] ?? '', ['aadhaar', 'birth_certificate', 'photo_id', 'school_id', 'medical', 'other'], true) ? $in['doc_type'] : 'other';
        $ocr = isset($in['ocr_fields']) ? (is_array($in['ocr_fields']) ? $in['ocr_fields'] : json_decode((string) $in['ocr_fields'], true)) : null;
        if (is_array($ocr)) {
            // never keep identity numbers
            $ocr = array_intersect_key($ocr, array_flip(['name', 'dob', 'gender', 'address', 'guardian_name', 'pincode', 'id_last4']));
        }
        $id = DB::insert('student_documents', [
            'academy_id' => $aid, 'student_id' => $sid, 'doc_type' => $type, 'title' => mb_substr((string) ($in['title'] ?? ''), 0, 120) ?: null,
            'storage_path' => $stored['path'], 'original_name' => $stored['name'], 'mime' => $stored['mime'], 'size_bytes' => $stored['size'],
            'ocr_fields' => $ocr ? json_encode($ocr) : null,
            'retention_until' => date('Y-m-d', strtotime('+' . Settings::int('document_retention_days', 1095) . ' days')),
            'uploaded_by' => $c->userId,
        ]);
        Audit::log($c, 'document.upload', 'student_documents', $id, null, ['student_id' => $sid, 'type' => $type]);
        return Http::ok(['id' => $id], 'Document saved securely.');
    }, ['roles' => ['owner', 'teacher'], 'perm' => 'documents.manage', 'feature' => 'documents']);

    $r->delete('/documents/{id}', static function (Ctx $c, array $p) {
        $d = Crud::find('student_documents', (int) $p['id'], $c->academy(), 'Document');
        DB::update('student_documents', ['deleted_at' => date('Y-m-d H:i:s'), 'storage_path' => 'deleted'], 'id = ?', [$d['id']]);
        Upload::delete($d['storage_path']);
        Audit::log($c, 'document.delete', 'student_documents', $d['id'], ['student_id' => $d['student_id'], 'type' => $d['doc_type']]);
        return Http::ok(null, 'Document permanently deleted.');
    }, ['roles' => ['owner'], 'perm' => 'documents.manage']);

    // --------------------------------------------------------- online admissions
    $r->get('/admissions', static function (Ctx $c) {
        $st = Http::query('status', 'pending');
        $rows = DB::all('SELECT ad.*, a.name AS activity_name, b.name AS batch_name FROM admissions ad LEFT JOIN activities a ON a.id = ad.activity_id LEFT JOIN batches b ON b.id = ad.batch_id
                         WHERE ad.academy_id = ?' . ($st !== 'all' ? ' AND ad.status = ?' : '') . ' ORDER BY ad.id DESC LIMIT 300', $st !== 'all' ? [$c->academy(), $st] : [$c->academy()]);
        foreach ($rows as &$a) {
            $a['photo_url'] = $a['photo_path'] ? cnx_url('api/v1/files/admission-photo/' . $a['id']) : null;
            $a['has_document'] = (bool) $a['document_path'];
            unset($a['document_path'], $a['photo_path']);
        }
        return Http::ok(['items' => $rows, 'link' => cnx_url('admission.php?a=' . DB::val('SELECT slug FROM academies WHERE id = ?', [$c->academy()]))]);
    }, ['roles' => ['owner'], 'perm' => 'admissions.manage']);

    $r->post('/admissions/{id}/approve', static function (Ctx $c, array $p) {
        $aid = $c->academy();
        $ad = Crud::find('admissions', (int) $p['id'], $aid, 'Application');
        if ($ad['status'] !== 'pending' && $ad['status'] !== 'enquiry') {
            throw new ApiException('This application was already ' . $ad['status'] . '.', 409);
        }
        $in = Http::input() + [
            'name' => $ad['student_name'], 'whatsapp' => $ad['whatsapp'], 'country_code' => $ad['country_code'], 'email' => $ad['email'],
            'dob' => $ad['dob'], 'address' => $ad['address'], 'guardian_name' => $ad['parent_name'], 'activity_id' => $ad['activity_id'],
            'batch_id' => $ad['batch_id'], 'consent_given' => 1, 'source' => 'online',
        ];
        $res = StudentService::create($c, $in);
        $sid = (int) $res['student']['id'];
        if ($ad['photo_path']) {
            DB::update('students', ['photo_path' => $ad['photo_path']], 'id = ? AND photo_path IS NULL', [$sid]);
        }
        if ($ad['document_path']) {
            DB::insert('student_documents', ['academy_id' => $aid, 'student_id' => $sid, 'doc_type' => 'other', 'title' => 'Submitted with online admission', 'storage_path' => $ad['document_path'], 'mime' => 'application/octet-stream', 'size_bytes' => 0, 'uploaded_by' => $c->userId]);
        }
        DB::update('admissions', ['status' => 'approved', 'student_id' => $sid, 'reviewed_by' => $c->userId, 'reviewed_at' => date('Y-m-d H:i:s')], 'id = ?', [$ad['id']]);
        Audit::log($c, 'admission.approve', 'admissions', $ad['id'], null, ['student_id' => $sid]);
        return Http::ok($res, 'Admission approved and student registered.');
    }, ['roles' => ['owner'], 'perm' => 'admissions.manage']);

    $r->post('/admissions/{id}/reject', static function (Ctx $c, array $p) {
        $ad = Crud::find('admissions', (int) $p['id'], $c->academy(), 'Application');
        DB::update('admissions', ['status' => 'rejected', 'reviewed_by' => $c->userId, 'reviewed_at' => date('Y-m-d H:i:s')], 'id = ?', [$ad['id']]);
        Audit::log($c, 'admission.reject', 'admissions', $ad['id']);
        return Http::ok(null, 'Application rejected.');
    }, ['roles' => ['owner'], 'perm' => 'admissions.manage']);
};
