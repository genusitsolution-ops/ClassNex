<?php
declare(strict_types=1);

/** Students, parents, documents and online admissions. */
return static function (Router $r): void {
    $staff = ['roles' => ['owner', 'teacher']];

    $r->get('/students', static function (Ctx $c) {
        [$w, $p] = StudentService::listWhere($c, $_GET);
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

    // Bulk branch assignment / transfer for any number of students.
    // Body: branch_id, optional batch_id (also moves them into that batch), and either student_ids[] or
    // filter{q,branch_id,activity_id,batch_id} ("all students matching"). preview=1 only returns the count.
    $r->post('/students/bulk-branch', static function (Ctx $c) {
        $in = Http::input();
        $res = StudentService::bulkChangeBranch($c, (int) ($in['branch_id'] ?? 0), !empty($in['batch_id']) ? (int) $in['batch_id'] : null,
            isset($in['student_ids']) ? array_map('intval', (array) $in['student_ids']) : null, is_array($in['filter'] ?? null) ? $in['filter'] : null, !empty($in['preview']));
        return Http::ok($res, $res['preview'] ? "{$res['count']} student(s) will be moved." : "{$res['moved']} student(s) moved to {$res['branch_name']}. Student IDs and the student count are unchanged.");
    }, ['roles' => ['owner'], 'perm' => 'students.manage']);

    $r->post('/students/{id}/transfer', static function (Ctx $c, array $p) {
        $in = Http::input();
        return Http::ok(StudentService::transfer($c, (int) $p['id'], (int) ($in['batch_id'] ?? 0), $in['date'] ?? null), 'Batch changed.');
    }, $staff + ['perm' => 'students.manage']);

    $r->post('/students/{id}/enroll', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        $in = Http::input();
        StudentService::enroll($c, $sid, $in);
        return Http::ok(StudentService::get($c, $sid), 'Enrolled in ' . DB::val('SELECT name FROM activities WHERE id = ?', [(int) ($in['activity_id'] ?? 0)]) . '.');
    }, ['roles' => ['owner'], 'perm' => 'students.manage']);

    // Link existing student (from the duplicate check): re-use the profile instead of creating another.
    $r->post('/students/{id}/link', static function (Ctx $c, array $p) {
        return Http::ok(StudentService::linkExisting($c, (int) $p['id'], Http::input()), 'Existing student profile linked. No new student was created.');
    }, $staff + ['perm' => 'students.manage']);

    // Possible duplicates while typing (Add Student form)
    $r->get('/students/duplicates', static function (Ctx $c) {
        $cc = Phone::code(Http::query('country_code') ?: '+91');
        $wa = Http::query('whatsapp') ? Phone::normalize(Http::query('whatsapp'), $cc) : null;
        return Http::ok(StudentService::possibleDuplicates($c->academy(), (string) Http::query('name'), $wa ?: null, Http::query('guardian_name'), Http::query('dob') ?: null));
    }, $staff + ['perm' => 'students.manage']);

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
