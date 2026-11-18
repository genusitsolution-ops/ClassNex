<?php
declare(strict_types=1);

/** Academy profile, branches, activities, batches and teachers. */
return static function (Router $r): void {
    $staff = ['roles' => ['owner', 'teacher']];
    $own = ['roles' => ['owner']];

    // ------------------------------------------------------------ academy profile
    $r->get('/academy', static function (Ctx $c) {
        $a = DB::one('SELECT * FROM academies WHERE id = ?', [$c->academy()]);
        $a['settings'] = Settings::academy((int) $a['id']);
        $a['logo_url'] = $a['logo_path'] ? cnx_url($a['logo_path']) : null;
        $a['signature_url'] = $a['signature_path'] ? cnx_url($a['signature_path']) : null;
        $a['admission_link'] = cnx_url('admission.php?a=' . $a['slug']);
        return Http::ok($a);
    }, $staff);

    $r->post('/academy', static function (Ctx $c) {
        $aid = $c->academy();
        $in = Http::input();
        $rules = [
            'name' => 'required|max:160', 'address' => 'max:500', 'city' => 'max:80', 'state' => 'max:80', 'pincode' => 'max:10',
            'mobile' => 'phone', 'whatsapp' => 'phone', 'email' => 'email|max:160', 'website' => 'max:200', 'contact_person' => 'max:120',
            'gstin' => 'max:20', 'business_details' => 'max:1000', 'receipt_footer' => 'max:255', 'online_admission_enabled' => 'bool',
        ];
        $d = Validator::make($in, array_intersect_key($rules, $in))->validated();
        $before = DB::one('SELECT * FROM academies WHERE id = ?', [$aid]);
        DB::update('academies', $d, 'id = ?', [$aid]);
        [$o, $n] = Audit::diff($before, $d);
        if ($n) {
            Audit::log($c, 'academy.update', 'academies', $aid, $o, $n);
        }
        $old = DB::one('SELECT logo_path, signature_path FROM academies WHERE id = ?', [$aid]);
        if ($logo = Upload::storePublicImage('logo', "logos/$aid", 400)) {
            DB::update('academies', ['logo_path' => $logo], 'id = ?', [$aid]);
            if ($old['logo_path'] && is_file(CNX_ROOT . '/' . $old['logo_path'])) {
                unlink(CNX_ROOT . '/' . $old['logo_path']);
            }
        }
        if ($sig = Upload::storePublicImage('signature', "logos/$aid", 400)) {
            DB::update('academies', ['signature_path' => $sig], 'id = ?', [$aid]);
        }
        if (isset($in['settings']) && is_array($in['settings'])) {
            $cur = Settings::academy($aid);
            $allowed = array_keys($cur);
            $new = array_intersect_key($in['settings'], array_flip($allowed)) + $cur;
            DB::update('academies', ['settings' => json_encode($new)], 'id = ?', [$aid]);
        }
        return Http::ok(null, 'Academy profile saved.');
    }, $own + ['perm' => 'settings.manage']);

    // ----------------------------------------------------------------- branches
    // Branches are unlimited by default and share the academy's one subscription and student quota.
    $r->get('/branches', static function (Ctx $c) {
        $rows = DB::all("SELECT br.*, t.name AS manager_teacher_name,
                           (SELECT COUNT(*) FROM students s WHERE s.branch_id = br.id AND s.status = 'active') AS students,
                           (SELECT COUNT(*) FROM teachers x WHERE x.branch_id = br.id AND x.status = 'active') AS teachers,
                           (SELECT COUNT(*) FROM batches b WHERE b.branch_id = br.id AND b.status = 'active') AS batches
                         FROM academy_branches br LEFT JOIN teachers t ON t.id = br.manager_teacher_id
                         WHERE br.academy_id = ?" . (Http::query('status') ? ' AND br.status = ?' : '') . ' ORDER BY br.status, br.name', array_filter([$c->academy(), Http::query('status')]));
        return Http::ok($rows);
    }, $staff);
    $r->get('/branches/summary', static function (Ctx $c) {
        return Http::ok(BranchService::summary($c->academy(), Http::query('from') ?: date('Y-m-01'), Http::query('to') ?: date('Y-m-d')));
    }, $own + ['perm' => 'reports.view']);
    $r->get('/branches/{id}', static function (Ctx $c, array $p) {
        return Http::ok(BranchService::detail($c->academy(), (int) $p['id'], Http::query('from') ?: date('Y-m-01'), Http::query('to') ?: date('Y-m-d')));
    }, $own);
    $branchRules = ['name' => 'required|max:120', 'address' => 'max:500', 'contact_number' => 'phone', 'email' => 'email|max:160', 'manager_name' => 'max:120', 'manager_teacher_id' => 'int', 'status' => 'in:active,inactive'];
    $saveBranch = static function (Ctx $c, ?int $id) use ($branchRules) {
        $aid = $c->academy();
        $in = Http::input();
        if (!empty($in['manager_teacher_id'])) {
            $t = Crud::find('teachers', (int) $in['manager_teacher_id'], $aid, 'Teacher');
            $in['manager_name'] = $in['manager_name'] ?? $t['name'];
        }
        if (!$id) {
            Feature::assertLimit($aid, 'branches'); // only applies if a special enterprise branch restriction was configured
        }
        return Crud::save($c, 'academy_branches', $id, $branchRules, $in, $id ? [] : ['academy_id' => $aid]);
    };
    $r->post('/branches', static fn(Ctx $c) => Http::ok(['id' => $saveBranch($c, null)], 'Branch added. Branches are unlimited and share your student quota.'), $own + ['perm' => 'structure.manage']);
    $r->put('/branches/{id}', static fn(Ctx $c, array $p) => Http::ok(['id' => $saveBranch($c, (int) $p['id'])], 'Branch updated.'), $own + ['perm' => 'structure.manage']);

    // bulk-assign existing students / teachers / batches to a branch (same profiles, quota unchanged)
    $r->post('/branches/{id}/assign', static function (Ctx $c, array $p) {
        $aid = $c->academy();
        $br = Crud::find('academy_branches', (int) $p['id'], $aid, 'Branch');
        $in = Http::input();
        $n = DB::tx(static function () use ($c, $aid, $br, $in) {
            $n = 0;
            foreach (['students' => 'student_ids', 'teachers' => 'teacher_ids', 'batches' => 'batch_ids'] as $table => $key) {
                $ids = array_values(array_unique(array_map('intval', (array) ($in[$key] ?? []))));
                if ($ids) {
                    $n += DB::exec("UPDATE $table SET branch_id = ? WHERE academy_id = ? AND id IN (" . DB::inList($ids) . ')', array_merge([$br['id'], $aid], $ids));
                    Audit::log($c, 'branch.assign_' . $table, 'academy_branches', $br['id'], null, [$key => $ids]);
                }
            }
            return $n;
        });
        return Http::ok(['updated' => $n], "$n record(s) assigned to {$br['name']}.");
    }, $own + ['perm' => 'structure.manage']);

    // ---------------------------------------------------------------- activities
    $r->get('/activities', static function (Ctx $c) {
        $rows = DB::all("SELECT a.*, t.name AS instructor_name, br.name AS branch_name,
                           (SELECT COUNT(*) FROM batches b WHERE b.activity_id = a.id AND b.status = 'active') AS batch_count,
                           (SELECT COUNT(DISTINCT m.student_id) FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.activity_id = a.id AND m.status = 'active' AND s.status = 'active') AS student_count
                         FROM activities a LEFT JOIN teachers t ON t.id = a.instructor_id LEFT JOIN academy_branches br ON br.id = a.branch_id
                         WHERE a.academy_id = ?" . (Http::query('status') ? ' AND a.status = ?' : '') . ' ORDER BY a.status, a.name', array_filter([$c->academy(), Http::query('status')]));
        foreach ($rows as &$a) {
            $a['image_url'] = $a['image_path'] ? cnx_url($a['image_path']) : null;
        }
        return Http::ok($rows);
    }, $staff);
    $actRules = ['name' => 'required|max:120', 'description' => 'max:1000', 'registration_fee' => 'money', 'one_time_fee' => 'money', 'monthly_fee' => 'money',
                 'duration' => 'max:60', 'instructor_id' => 'int', 'branch_id' => 'int', 'status' => 'in:active,inactive'];
    $saveActivity = static function (Ctx $c, ?int $id) use ($actRules) {
        $aid = $c->academy();
        $in = Http::input();
        if (!$id) {
            Feature::assertLimit($aid, 'activities');
        }
        $d = Validator::make($in, $id ? array_intersect_key($actRules, $in) : $actRules, ['name' => 'Activity name'])->validated();
        foreach (['registration_fee' => 'registration_fee_paise', 'one_time_fee' => 'one_time_fee_paise', 'monthly_fee' => 'monthly_fee_paise'] as $k => $col) {
            if (array_key_exists($k, $d)) {
                $d[$col] = (int) ($d[$k] ?? 0);
                unset($d[$k]);
            }
        }
        if (!empty($d['instructor_id'])) {
            Crud::find('teachers', (int) $d['instructor_id'], $aid, 'Teacher');
        }
        if (!empty($d['branch_id'])) {
            Crud::find('academy_branches', (int) $d['branch_id'], $aid, 'Branch');
        }
        if ($img = Upload::storePublicImage('image', "activities/$aid", 800)) {
            $d['image_path'] = $img;
        }
        if ($id) {
            $old = Crud::find('activities', $id, $aid, 'Activity');
            DB::update('activities', $d, 'id = ?', [$id]);
            [$o, $n] = Audit::diff($old, $d);
            Audit::log($c, 'activity.update', 'activities', $id, $o, $n);
            return $id;
        }
        $id = DB::insert('activities', $d + ['academy_id' => $aid]);
        Audit::log($c, 'activity.create', 'activities', $id, null, $d);
        return $id;
    };
    $r->post('/activities', static fn(Ctx $c) => Http::ok(['id' => $saveActivity($c, null)], 'Activity created.'), $own + ['perm' => 'structure.manage']);
    $r->put('/activities/{id}', static fn(Ctx $c, array $p) => Http::ok(['id' => $saveActivity($c, (int) $p['id'])], 'Activity updated.'), $own + ['perm' => 'structure.manage']);

    // ------------------------------------------------------------------- batches
    $r->get('/batches', static function (Ctx $c) {
        $w = 'b.academy_id = ?';
        $p = [$c->academy()];
        if ($aid = (int) Http::query('activity_id')) {
            $w .= ' AND b.activity_id = ?';
            $p[] = $aid;
        }
        if ($br = (int) Http::query('branch_id')) {
            $w .= ' AND b.branch_id = ?';
            $p[] = $br;
        }
        if ($st = Http::query('status')) {
            $w .= ' AND b.status = ?';
            $p[] = $st;
        }
        $scope = $c->batchScope();
        if ($scope !== null) {
            $w .= ' AND b.id IN (' . DB::inList($scope) . ')';
            array_push($p, ...$scope);
        }
        $rows = DB::all("SELECT b.*, a.name AS activity_name, t.name AS teacher_name, (SELECT name FROM academy_branches x WHERE x.id = b.branch_id) AS branch_name,
                           (SELECT COUNT(*) FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.batch_id = b.id AND m.status = 'active' AND s.status = 'active') AS student_count,
                           (SELECT GROUP_CONCAT(weekday ORDER BY weekday) FROM batch_schedules bs WHERE bs.batch_id = b.id) AS weekdays,
                           EXISTS(SELECT 1 FROM attendance_sessions se WHERE se.batch_id = b.id AND se.session_date = CURDATE()) AS marked_today
                         FROM batches b JOIN activities a ON a.id = b.activity_id LEFT JOIN teachers t ON t.id = b.teacher_id
                         WHERE $w ORDER BY b.status, a.name, b.start_time, b.name", $p);
        foreach ($rows as &$b) {
            $b['weekdays'] = $b['weekdays'] ? array_map('intval', explode(',', $b['weekdays'])) : [];
            $b['extra_teacher_ids'] = array_map('intval', DB::col('SELECT teacher_id FROM teacher_batches WHERE batch_id = ?', [$b['id']]));
        }
        return Http::ok($rows);
    }, $staff);

    $batchRules = ['name' => 'required|max:120', 'activity_id' => 'required|int', 'branch_id' => 'int', 'teacher_id' => 'int', 'start_date' => 'date', 'end_date' => 'date',
                   'start_time' => 'time', 'end_time' => 'time', 'capacity' => 'int', 'location' => 'max:160', 'monthly_fee' => 'money', 'status' => 'in:active,inactive,completed'];
    $saveBatch = static function (Ctx $c, ?int $id) use ($batchRules) {
        $aid = $c->academy();
        $in = Http::input();
        if (!$id) {
            Feature::assertLimit($aid, 'batches');
        }
        $d = Validator::make($in, $id ? array_intersect_key($batchRules, $in) : $batchRules, ['activity_id' => 'Activity', 'name' => 'Batch name'])->validated();
        if (array_key_exists('monthly_fee', $d)) {
            $d['monthly_fee_paise'] = $d['monthly_fee'];
            unset($d['monthly_fee']);
        }
        if (!empty($d['activity_id'])) {
            Crud::find('activities', (int) $d['activity_id'], $aid, 'Activity');
        }
        if (!empty($d['teacher_id'])) {
            Crud::find('teachers', (int) $d['teacher_id'], $aid, 'Teacher');
        }
        if (!empty($d['branch_id'])) {
            Crud::find('academy_branches', (int) $d['branch_id'], $aid, 'Branch');
        } elseif (!$id && !array_key_exists('branch_id', $d) && !empty($d['activity_id'])) {
            $d['branch_id'] = DB::val('SELECT branch_id FROM activities WHERE id = ?', [$d['activity_id']]);
        }
        if (!empty($d['start_date']) && !empty($d['end_date']) && $d['end_date'] < $d['start_date']) {
            throw ApiException::validation(['end_date' => 'End date must be after start date.']);
        }
        $id = DB::tx(static function () use ($c, $id, $d, $aid, $in) {
            if ($id) {
                $old = Crud::find('batches', $id, $aid, 'Batch');
                DB::update('batches', $d, 'id = ?', [$id]);
                [$o, $n] = Audit::diff($old, $d);
                Audit::log($c, 'batch.update', 'batches', $id, $o, $n);
            } else {
                $id = DB::insert('batches', $d + ['academy_id' => $aid]);
                Audit::log($c, 'batch.create', 'batches', $id, null, $d);
            }
            if (isset($in['weekdays']) && is_array($in['weekdays'])) {
                DB::exec('DELETE FROM batch_schedules WHERE batch_id = ?', [$id]);
                foreach (array_unique(array_map('intval', $in['weekdays'])) as $wd) {
                    if ($wd >= 1 && $wd <= 7) {
                        DB::insert('batch_schedules', ['batch_id' => $id, 'weekday' => $wd, 'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null]);
                    }
                }
            }
            if (isset($in['extra_teacher_ids']) && is_array($in['extra_teacher_ids'])) {
                DB::exec('DELETE FROM teacher_batches WHERE batch_id = ?', [$id]);
                foreach (array_unique(array_map('intval', $in['extra_teacher_ids'])) as $tid) {
                    if (DB::val('SELECT 1 FROM teachers WHERE id = ? AND academy_id = ?', [$tid, $aid])) {
                        DB::insert('teacher_batches', ['teacher_id' => $tid, 'batch_id' => $id]);
                    }
                }
            }
            return $id;
        });
        return $id;
    };
    $r->post('/batches', static fn(Ctx $c) => Http::ok(['id' => $saveBatch($c, null)], 'Batch created.'), $own + ['perm' => 'structure.manage']);
    $r->put('/batches/{id}', static fn(Ctx $c, array $p) => Http::ok(['id' => $saveBatch($c, (int) $p['id'])], 'Batch updated.'), $own + ['perm' => 'structure.manage']);
    $r->get('/batches/{id}/students', static function (Ctx $c, array $p) {
        $c->assertBatch((int) $p['id']);
        return Http::ok(DB::all("SELECT s.id, s.student_code, s.name, CONCAT(s.country_code,' ',s.whatsapp) AS whatsapp, m.start_date
                                 FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.batch_id = ? AND m.status = 'active' AND s.status = 'active' ORDER BY s.name", [(int) $p['id']]));
    }, $staff);

    // timetable: batches by weekday
    $r->get('/timetable', static function (Ctx $c) {
        $scope = $c->batchScope();
        $w = 'b.academy_id = ? AND b.status = \'active\'';
        $p = [$c->academy()];
        if ($scope !== null) {
            $w .= ' AND b.id IN (' . DB::inList($scope) . ')';
            array_push($p, ...$scope);
        }
        return Http::ok(DB::all("SELECT bs.weekday, COALESCE(bs.start_time, b.start_time) AS start_time, COALESCE(bs.end_time, b.end_time) AS end_time, b.id AS batch_id, b.name AS batch_name, a.name AS activity_name, b.location, t.name AS teacher_name
                                 FROM batch_schedules bs JOIN batches b ON b.id = bs.batch_id JOIN activities a ON a.id = b.activity_id LEFT JOIN teachers t ON t.id = b.teacher_id
                                 WHERE $w ORDER BY bs.weekday, start_time", $p));
    }, $staff);

    // ------------------------------------------------------------------ teachers
    $r->get('/teachers', static function (Ctx $c) {
        $rows = DB::all("SELECT t.*, u.status AS login_status, u.last_login_at,
                           (SELECT GROUP_CONCAT(b.name SEPARATOR ', ') FROM batches b WHERE (b.teacher_id = t.id OR b.id IN (SELECT batch_id FROM teacher_batches WHERE teacher_id = t.id)) AND b.status = 'active') AS batch_names
                         FROM teachers t LEFT JOIN users u ON u.id = t.user_id WHERE t.academy_id = ? ORDER BY t.status, t.name", [$c->academy()]);
        foreach ($rows as &$t) {
            $t['photo_url'] = $t['photo_path'] ? cnx_url('api/v1/files/teacher-photo/' . $t['id']) : null;
            $t['permissions'] = $t['user_id'] ? DB::col('SELECT perm_key FROM user_permissions WHERE user_id = ? AND granted = 1', [$t['user_id']]) : [];
            if (!$c->can('teachers.manage')) {
                unset($t['salary_paise'], $t['salary_type']);
            }
        }
        return Http::ok($rows);
    }, $own);

    $saveTeacher = static function (Ctx $c, ?int $id) {
        $aid = $c->academy();
        $in = Http::input();
        $rules = ['name' => 'required|max:120', 'mobile' => 'required|phone', 'email' => 'email|max:160', 'address' => 'max:500', 'specialization' => 'max:160',
                  'joining_date' => 'date', 'salary_type' => 'in:none,monthly,per_class,per_student,other', 'salary' => 'money', 'status' => 'in:active,inactive', 'branch_id' => 'int'];
        $d = Validator::make($in, $id ? array_intersect_key($rules, $in) : $rules)->validated();
        $cc = Phone::code($in['country_code'] ?? '+91');
        if (isset($d['mobile'])) {
            $d['mobile'] = Phone::normalize($d['mobile'], $cc) ?? throw ApiException::validation(['mobile' => 'Enter a valid mobile number.']);
            $d['country_code'] = $cc;
        }
        if (array_key_exists('salary', $d)) {
            $d['salary_paise'] = $d['salary'];
            unset($d['salary']);
        }
        if (!$id) {
            Feature::assertLimit($aid, 'teachers');
            if ((int) DB::val('SELECT COUNT(*) FROM teachers WHERE academy_id = ?', [$aid]) >= 1) {
                Feature::assert($aid, 'staff_management');
            }
        }
        if (!empty($d['branch_id'])) {
            Crud::find('academy_branches', (int) $d['branch_id'], $aid, 'Branch');
        }
        $photo = Upload::storePhoto('photo', $aid, 'teachers');
        if ($photo) {
            $d['photo_path'] = $photo;
        }
        $result = DB::tx(static function () use ($c, $id, $d, $aid, $in) {
            if ($id) {
                $old = Crud::find('teachers', $id, $aid, 'Teacher');
                DB::update('teachers', $d, 'id = ?', [$id]);
                if ($old['user_id']) {
                    $sync = array_intersect_key($d, array_flip(['name', 'email', 'mobile', 'country_code']));
                    if (isset($d['status'])) {
                        $sync['status'] = $d['status'] === 'active' ? (DB::val('SELECT password_hash FROM users WHERE id = ?', [$old['user_id']]) ? 'active' : 'invited') : 'inactive';
                    }
                    DB::update('users', $sync, 'id = ?', [$old['user_id']]);
                    if (($d['status'] ?? '') === 'inactive') {
                        DB::exec('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$old['user_id']]);
                    }
                }
                [$o, $n] = Audit::diff($old, $d);
                Audit::log($c, 'teacher.update', 'teachers', $id, $o, $n);
                $uid = $old['user_id'];
            } else {
                $id = DB::insert('teachers', $d + ['academy_id' => $aid]);
                $uid = AccountService::ensureUser($aid, 'teacher', $d['name'], $d['country_code'], $d['mobile'], $d['email'] ?? null, !empty($in['password']) ? (string) $in['password'] : null);
                DB::update('teachers', ['user_id' => $uid], 'id = ?', [$id]);
                Audit::log($c, 'teacher.create', 'teachers', $id, null, $d);
            }
            if (isset($in['permissions']) && is_array($in['permissions']) && $uid) {
                DB::exec('DELETE FROM user_permissions WHERE user_id = ?', [$uid]);
                $valid = DB::col('SELECT perm_key FROM permissions');
                foreach (array_intersect($in['permissions'], $valid) as $perm) {
                    DB::insert('user_permissions', ['user_id' => $uid, 'perm_key' => $perm, 'granted' => 1]);
                }
                Audit::log($c, 'teacher.permissions', 'teachers', $id, null, ['permissions' => array_values($in['permissions'])]);
            }
            if (isset($in['batch_ids']) && is_array($in['batch_ids'])) {
                DB::exec('DELETE tb FROM teacher_batches tb JOIN batches b ON b.id = tb.batch_id WHERE tb.teacher_id = ? AND b.academy_id = ?', [$id, $aid]);
                foreach (array_unique(array_map('intval', $in['batch_ids'])) as $bid) {
                    if (DB::val('SELECT 1 FROM batches WHERE id = ? AND academy_id = ?', [$bid, $aid])) {
                        DB::exec('INSERT IGNORE INTO teacher_batches (teacher_id, batch_id) VALUES (?,?)', [$id, $bid]);
                    }
                }
            }
            return ['id' => $id, 'user_id' => $uid];
        });
        $invite = null;
        if (!empty($in['send_invite']) || (!$id && empty($in['password']))) {
            $invite = AccountService::invite((int) $result['user_id']);
        }
        return ['id' => $result['id'], 'invite' => $invite];
    };
    $r->post('/teachers', static fn(Ctx $c) => Http::ok($saveTeacher($c, null), 'Teacher added. An activation link has been sent.'), $own + ['perm' => 'teachers.manage']);
    $r->put('/teachers/{id}', static fn(Ctx $c, array $p) => Http::ok($saveTeacher($c, (int) $p['id']), 'Teacher updated.'), $own + ['perm' => 'teachers.manage']);
    $r->post('/teachers/{id}/invite', static function (Ctx $c, array $p) {
        $t = Crud::find('teachers', (int) $p['id'], $c->academy(), 'Teacher');
        return Http::ok(AccountService::invite((int) $t['user_id']), 'Activation link sent.');
    }, $own + ['perm' => 'teachers.manage']);
    $r->get('/permissions', static fn(Ctx $c) => Http::ok(DB::all('SELECT * FROM permissions ORDER BY module, perm_key')), $own);

    // teacher leave requests (owner side)
    $r->get('/leaves', static function (Ctx $c) {
        $w = 'l.academy_id = ?';
        $p = [$c->academy()];
        if ($c->role === 'teacher') {
            $w .= ' AND l.teacher_id = ?';
            $p[] = $c->teacherId ?? 0;
        }
        return Http::ok(DB::all("SELECT l.*, t.name AS teacher_name FROM teacher_leaves l JOIN teachers t ON t.id = l.teacher_id WHERE $w ORDER BY l.from_date DESC LIMIT 200", $p));
    }, $staff);
    $r->post('/leaves', static function (Ctx $c) {
        if ($c->role !== 'teacher' || !$c->teacherId) {
            throw ApiException::forbidden();
        }
        $d = Validator::make(Http::input(), ['from_date' => 'required|date', 'to_date' => 'required|date', 'reason' => 'max:255'])->validated();
        if ($d['to_date'] < $d['from_date']) {
            throw ApiException::validation(['to_date' => 'End date must be on or after start date.']);
        }
        $id = DB::insert('teacher_leaves', $d + ['academy_id' => $c->academy(), 'teacher_id' => $c->teacherId]);
        $owner = (int) DB::val('SELECT owner_user_id FROM academies WHERE id = ?', [$c->academy()]);
        NotificationService::toUsers($c->academy(), 'manual_message', [$owner], ['title' => 'Leave request', 'message' => "{$c->user['name']} requested leave from {$d['from_date']} to {$d['to_date']}."], ['channels' => ['inapp', 'push'], 'dedupe' => "leave$id"]);
        return Http::ok(['id' => $id], 'Leave request sent.');
    }, ['roles' => ['teacher']]);
    $r->put('/leaves/{id}', static function (Ctx $c, array $p) {
        $l = Crud::find('teacher_leaves', (int) $p['id'], $c->academy(), 'Leave');
        $st = Http::input()['status'] ?? '';
        if (!in_array($st, ['approved', 'rejected'], true)) {
            throw ApiException::validation(['status' => 'Choose approve or reject.']);
        }
        DB::update('teacher_leaves', ['status' => $st, 'reviewed_by' => $c->userId], 'id = ?', [$l['id']]);
        $uid = (int) DB::val('SELECT user_id FROM teachers WHERE id = ?', [$l['teacher_id']]);
        if ($uid) {
            NotificationService::toUsers($c->academy(), 'manual_message', [$uid], ['title' => 'Leave ' . $st, 'message' => "Your leave from {$l['from_date']} to {$l['to_date']} was $st."], ['channels' => ['inapp', 'push'], 'dedupe' => "leave{$l['id']}$st"]);
        }
        return Http::ok(null, 'Leave ' . $st . '.');
    }, $own + ['perm' => 'teachers.manage']);
};
