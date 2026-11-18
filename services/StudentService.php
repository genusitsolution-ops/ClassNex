<?php
declare(strict_types=1);

final class StudentService
{
    /**
     * Quick registration. Only name, WhatsApp number and activity (and batch, when the activity
     * has batches) are required; guardian name is required for minors. Everything else is optional.
     */
    public static function create(Ctx $ctx, array $in): array
    {
        $aid = $ctx->academy();
        $cc = Phone::code($in['country_code'] ?? '+91');
        $v = Validator::make($in, [
            'name'             => 'required|max:120',
            'whatsapp'         => 'required|phone',
            'activity_id'      => 'required|int',
            'batch_id'         => 'int',
            'guardian_name'    => 'max:120',
            'guardian_relation'=> 'max:30',
            'email'            => 'email|max:160',
            'dob'              => 'date',
            'gender'           => 'in:male,female,other',
            'address'          => 'max:500',
            'school_name'      => 'max:160',
            'blood_group'      => 'max:5',
            'medical_notes'    => 'max:255',
            'emergency_contact'=> 'phone',
            'alt_mobile'       => 'phone',
            'notes'            => 'max:1000',
            'parent_whatsapp'  => 'phone',
            'parent_email'     => 'email|max:160',
            'monthly_fee'      => 'money',
            'admission_date'   => 'date',
            'branch_id'        => 'int',
        ], ['whatsapp' => 'WhatsApp number', 'activity_id' => 'Activity', 'batch_id' => 'Batch', 'dob' => 'Date of birth']);
        $d = $v->validated();
        $errors = [];
        $wa = Phone::normalize($d['whatsapp'], $cc);
        if (!$wa) {
            $errors['whatsapp'] = $cc === '+91' ? 'Enter a valid 10-digit Indian mobile number.' : 'Enter a valid mobile number.';
        }
        $isMinor = isset($in['is_minor']) ? filter_var($in['is_minor'], FILTER_VALIDATE_BOOLEAN) : true;
        if (!empty($d['dob'])) {
            if ($d['dob'] > date('Y-m-d')) {
                $errors['dob'] = 'Date of birth cannot be in the future.';
            }
            $isMinor = strtotime($d['dob']) > strtotime('-18 years');
        }
        if ($isMinor && empty($d['guardian_name'])) {
            $errors['guardian_name'] = 'Parent / Guardian name is required for students under 18.';
        }
        $activity = DB::one("SELECT * FROM activities WHERE id = ? AND academy_id = ? AND status = 'active'", [$d['activity_id'] ?? 0, $aid]);
        if (!$activity) {
            $errors['activity_id'] = 'Select an activity.';
        }
        $batch = null;
        if ($activity) {
            // a batch is required only when this activity runs batches at the chosen branch (or anywhere, if no branch was chosen)
            $hasBatches = (int) DB::val("SELECT COUNT(*) FROM batches WHERE activity_id = ? AND status = 'active'" . (!empty($d['branch_id']) ? ' AND (branch_id = ' . (int) $d['branch_id'] . ' OR branch_id IS NULL)' : ''), [$activity['id']]);
            if (!empty($d['batch_id'])) {
                $batch = DB::one("SELECT * FROM batches WHERE id = ? AND activity_id = ? AND academy_id = ? AND status = 'active'", [$d['batch_id'], $activity['id'], $aid]);
                if (!$batch) {
                    $errors['batch_id'] = 'Select a valid batch for this activity.';
                } elseif ($batch['capacity'] && (int) DB::val("SELECT COUNT(*) FROM student_activity_mapping WHERE batch_id = ? AND status = 'active'", [$batch['id']]) >= (int) $batch['capacity'] && empty($in['allow_over_capacity'])) {
                    $errors['batch_id'] = 'This batch is full (' . $batch['capacity'] . ' students). Choose another batch or increase capacity.';
                }
            } elseif ($hasBatches) {
                $errors['batch_id'] = 'Select a batch.';
            }
        }
        $parentWa = null;
        if (!empty($d['parent_whatsapp'])) {
            $parentWa = Phone::normalize($d['parent_whatsapp'], Phone::code($in['parent_country_code'] ?? $cc));
            if (!$parentWa) {
                $errors['parent_whatsapp'] = 'Enter a valid parent WhatsApp number.';
            }
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        // Possible duplicates (name + WhatsApp + guardian + DOB). Brothers and sisters sharing a parent's
        // number are NOT duplicates. A Class Admin may still create a separate profile after checking,
        // with a reason that is written to the audit log.
        $override = !empty($in['confirm_duplicate']);
        $overrideReason = trim((string) ($in['duplicate_reason'] ?? ''));
        $findDuplicate = static fn(): array => self::possibleDuplicates($aid, $d['name'], $wa, $d['guardian_name'] ?? null, $d['dob'] ?? null);
        $duplicateError = static function (array $cands) use ($ctx): ApiException {
            $c = $cands[0];
            $msg = count($cands) === 1
                ? "Possible duplicate: {$c['name']} ({$c['student_code']}, {$c['status']}) – " . implode(', ', $c['reasons']) . '. Open or link the existing profile, or create a separate student after checking.'
                : count($cands) . ' possible duplicates found. Open or link an existing profile, or create a separate student after checking.';
            return new ApiException($msg, 409, ['duplicate_id' => $c['id'], 'duplicate_status' => $c['status'], 'candidates' => $cands, 'can_override' => $ctx->role === 'owner'], 'duplicate_student');
        };
        $dups = $findDuplicate();
        if ($dups && !$override) {
            throw $duplicateError($dups);
        }
        if ($dups && $override) {
            if ($ctx->role !== 'owner') {
                throw new ApiException('Only the Class Admin can create a separate student when a possible duplicate exists.', 403, [], 'forbidden');
            }
            if (mb_strlen($overrideReason) < 5) {
                throw ApiException::validation(['duplicate_reason' => 'Briefly say why this is a different student (e.g. "twin brother", "different child, same parent").']);
            }
        }
        // branch: chosen branch, else the batch's / activity's branch (must belong to this academy)
        $branchId = !empty($d['branch_id']) ? (int) $d['branch_id'] : (int) ($batch['branch_id'] ?? $activity['branch_id'] ?? 0);
        if ($branchId && !DB::val("SELECT 1 FROM academy_branches WHERE id = ? AND academy_id = ? AND status = 'active'", [$branchId, $aid])) {
            throw ApiException::validation(['branch_id' => 'Select a valid branch.']);
        }
        $d['branch_id'] = $branchId ?: null;
        if (!empty($in['consent_given']) === false && $isMinor && !empty($in['require_consent'])) {
            throw ApiException::validation(['consent_given' => 'Parent / guardian consent is required.']);
        }

        $photo = Upload::storePhoto('photo', $aid);
        $academy = DB::one('SELECT code, name FROM academies WHERE id = ?', [$aid]);
        $settings = Settings::academy($aid);
        try {
        $studentId = DB::tx(static function () use ($ctx, $aid, $d, $in, $cc, $wa, $isMinor, $activity, $batch, $photo, $academy, $settings, $parentWa, $findDuplicate, $duplicateError, $override) {
            // academy-wide quota, checked under a row lock so parallel registrations cannot exceed it
            Feature::assertStudentCapacity($aid);
            $now = $findDuplicate(); // re-check inside the lock (a parallel registration of the same person)
            if ($now && !$override) {
                throw $duplicateError($now);
            }
            $prefix = $settings['student_id_prefix'] ?: $academy['code'];
            $code = $prefix . str_pad((string) Sequence::next($aid, 'student'), 4, '0', STR_PAD_LEFT);
            $admission = $d['admission_date'] ?? date('Y-m-d');
            $sid = DB::insert('students', [
                'academy_id'        => $aid,
                'branch_id'         => $d['branch_id'],
                'student_code'      => $code,
                'name'              => trim($d['name']),
                'photo_path'        => $photo,
                'country_code'      => $cc,
                'whatsapp'          => $wa,
                'alt_mobile'        => $d['alt_mobile'] ?? null,
                'email'             => $d['email'] ?? null,
                'dob'               => $d['dob'] ?? null,
                'gender'            => $d['gender'] ?? null,
                'is_minor'          => $isMinor ? 1 : 0,
                'guardian_name'     => $d['guardian_name'] ?? null,
                'guardian_relation' => $d['guardian_relation'] ?? null,
                'address'           => $d['address'] ?? null,
                'school_name'       => $d['school_name'] ?? null,
                'blood_group'       => $d['blood_group'] ?? null,
                'medical_notes'     => $d['medical_notes'] ?? null,
                'emergency_contact' => $d['emergency_contact'] ?? null,
                'admission_date'    => $admission,
                'source'            => $in['source'] ?? 'manual',
                'consent_given'     => !empty($in['consent_given']) ? 1 : 0,
                'consent_by'        => !empty($in['consent_given']) ? ($d['guardian_name'] ?? trim($d['name'])) : null,
                'consent_at'        => !empty($in['consent_given']) ? date('Y-m-d H:i:s') : null,
                'notes'             => $d['notes'] ?? null,
                'created_by'        => $ctx->userId,
            ]);
            $enrol = DB::insert('student_activity_mapping', [
                'academy_id' => $aid, 'student_id' => $sid, 'activity_id' => $activity['id'], 'batch_id' => $batch['id'] ?? null, 'start_date' => $admission,
            ]);

            // parent / guardian record (the WhatsApp number for minors is the parent's number)
            if ($isMinor || $parentWa) {
                $pPhone = $parentWa ?? $wa;
                $pcc = $parentWa ? Phone::code($in['parent_country_code'] ?? $cc) : $cc;
                $pid = DB::val('SELECT id FROM parents WHERE academy_id = ? AND country_code = ? AND whatsapp = ?', [$aid, $pcc, $pPhone]);
                if (!$pid) {
                    $pid = DB::insert('parents', [
                        'academy_id' => $aid, 'name' => $d['guardian_name'] ?: 'Parent of ' . trim($d['name']), 'country_code' => $pcc, 'whatsapp' => $pPhone,
                        'email' => $d['parent_email'] ?? ($isMinor ? ($d['email'] ?? null) : null), 'address' => $d['address'] ?? null,
                    ]);
                }
                DB::exec('INSERT IGNORE INTO parent_student_mapping (parent_id, student_id, relation) VALUES (?,?,?)', [$pid, $sid, $d['guardian_relation'] ?? 'Parent']);
            }

            // fees
            if (!isset($in['assign_fees']) || filter_var($in['assign_fees'], FILTER_VALIDATE_BOOLEAN)) {
                self::assignDefaultFees($ctx, $aid, $sid, $enrol, $activity, $batch, $admission, $d['monthly_fee'] ?? null, $in);
            }
            Audit::log($ctx, 'student.create', 'students', $sid, null, ['code' => $code, 'name' => $d['name'], 'activity' => $activity['name'], 'batch' => $batch['name'] ?? null]);
            if ($override && $now) {
                Audit::log($ctx, 'student.duplicate_override', 'students', $sid, null, ['possible_duplicates' => array_column($now, 'student_code'), 'reason' => trim((string) ($in['duplicate_reason'] ?? ''))]);
            }
            return $sid;
        });
        } catch (Throwable $e) {
            Upload::delete($photo);
            throw $e;
        }

        if (($settings['send_admission_message'] ?? true) && (!isset($in['send_message']) || filter_var($in['send_message'], FILTER_VALIDATE_BOOLEAN))) {
            NotificationService::admissionConfirmed($aid, $studentId);
        }
        $invite = null;
        if (!empty($in['invite_parent']) && Feature::has($aid, 'parent_access')) {
            $invite = self::inviteFamily($ctx, $studentId);
        }
        BillingService::applyAdvance($studentId);
        return ['student' => self::get($ctx, $studentId), 'invite' => $invite];
    }

    private static function assignDefaultFees(Ctx $ctx, int $aid, int $sid, int $enrolId, array $activity, ?array $batch, string $start, ?int $customMonthly, array $in): void
    {
        $structures = DB::all("SELECT * FROM fee_structures WHERE academy_id = ? AND status = 'active' AND auto_assign = 1
                               AND (batch_id = ? OR (batch_id IS NULL AND activity_id = ?) OR (batch_id IS NULL AND activity_id IS NULL))", [$aid, $batch['id'] ?? 0, $activity['id']]);
        // batch-level plans override activity-level ones of the same type
        $byType = [];
        foreach ($structures as $s) {
            $rank = $s['batch_id'] ? 3 : ($s['activity_id'] ? 2 : 1);
            $key = $s['fee_type'] . ':' . ($s['frequency_months'] ?? 0);
            if (!isset($byType[$key]) || $byType[$key]['rank'] < $rank) {
                $byType[$key] = $s + ['rank' => $rank];
            }
        }
        $assigned = [];
        foreach ($byType as $s) {
            $amount = (int) $s['amount_paise'];
            if ($s['fee_type'] === 'recurring' && (int) $s['frequency_months'] === 1 && $customMonthly) {
                $amount = $customMonthly;
            }
            BillingService::assign($aid, $sid, [
                'fee_structure_id' => $s['id'], 'enrollment_id' => $enrolId, 'name' => $s['name'], 'fee_type' => $s['fee_type'],
                'frequency_months' => $s['frequency_months'], 'amount_paise' => $amount, 'due_day' => $s['due_day'],
                'late_fee_paise' => $s['late_fee_paise'], 'late_fee_after_days' => $s['late_fee_after_days'], 'start_date' => $start,
                'prorate_first' => Settings::academy($aid)['prorate_first_month'] ?? false,
                'discount_id' => $in['discount_id'] ?? null,
            ], $ctx);
            $assigned[] = $s['fee_type'];
        }
        // fall back to the simple fees on the activity / batch
        if (!in_array('recurring', $assigned, true)) {
            $monthly = $customMonthly ?? ($batch && $batch['monthly_fee_paise'] !== null ? (int) $batch['monthly_fee_paise'] : (int) $activity['monthly_fee_paise']);
            if ($monthly > 0) {
                BillingService::assign($aid, $sid, ['enrollment_id' => $enrolId, 'name' => $activity['name'] . ' Monthly Fee', 'fee_type' => 'recurring', 'frequency_months' => 1, 'amount_paise' => $monthly, 'due_day' => (int) ($in['due_day'] ?? 5), 'start_date' => $start, 'prorate_first' => Settings::academy($aid)['prorate_first_month'] ?? false, 'discount_id' => $in['discount_id'] ?? null], $ctx);
            }
        }
        if (!in_array('registration', $assigned, true) && (int) $activity['registration_fee_paise'] > 0) {
            BillingService::assign($aid, $sid, ['enrollment_id' => $enrolId, 'name' => $activity['name'] . ' Registration Fee', 'fee_type' => 'registration', 'amount_paise' => (int) $activity['registration_fee_paise'], 'start_date' => $start], $ctx);
        }
        if (!in_array('one_time', $assigned, true) && (int) $activity['one_time_fee_paise'] > 0) {
            BillingService::assign($aid, $sid, ['enrollment_id' => $enrolId, 'name' => $activity['name'] . ' One-time Fee', 'fee_type' => 'one_time', 'amount_paise' => (int) $activity['one_time_fee_paise'], 'start_date' => $start], $ctx);
        }
    }

    public static function get(Ctx $ctx, int $id): array
    {
        $s = $ctx->assertStudent($id);
        $s['photo_url'] = $s['photo_path'] ? cnx_url('api/v1/files/student-photo/' . $s['id']) : null;
        $s['branch_name'] = $s['branch_id'] ? DB::val('SELECT name FROM academy_branches WHERE id = ?', [$s['branch_id']]) : null;
        $s['enrollments'] = DB::all('SELECT m.*, a.name AS activity_name, b.name AS batch_name, b.start_time, b.end_time, br.name AS branch_name FROM student_activity_mapping m
                                     JOIN activities a ON a.id = m.activity_id LEFT JOIN batches b ON b.id = m.batch_id LEFT JOIN academy_branches br ON br.id = b.branch_id WHERE m.student_id = ? ORDER BY m.status = \'active\' DESC, m.id DESC', [$id]);
        $s['parents'] = DB::all('SELECT p.id, p.name, p.country_code, p.whatsapp, p.email, p.user_id, m.relation, u.status AS login_status
                                 FROM parents p JOIN parent_student_mapping m ON m.parent_id = p.id LEFT JOIN users u ON u.id = p.user_id WHERE m.student_id = ?', [$id]);
        if (!$ctx->can('fees.view')) {
            return $s;
        }
        $s['balance'] = BillingService::balance($id);
        $s['fee_assignments'] = DB::all('SELECT * FROM student_fee_assignments WHERE student_id = ? ORDER BY status = \'active\' DESC, id DESC', [$id]);
        return $s;
    }

    /** Creates parent (or adult student) logins and sends activation links. */
    public static function inviteFamily(Ctx $ctx, int $studentId): array
    {
        $s = $ctx->assertStudent($studentId);
        $aid = $ctx->academy();
        Feature::assert($aid, 'parent_access');
        $out = [];
        $parents = DB::all('SELECT p.* FROM parents p JOIN parent_student_mapping m ON m.parent_id = p.id WHERE m.student_id = ?', [$studentId]);
        foreach ($parents as $p) {
            $uid = $p['user_id'] ?: AccountService::ensureUser($aid, 'parent', $p['name'], $p['country_code'], $p['whatsapp'], $p['email']);
            if (!$p['user_id']) {
                DB::update('parents', ['user_id' => $uid], 'id = ?', [$p['id']]);
            }
            $out[] = ['name' => $p['name'], 'role' => 'parent'] + AccountService::invite($uid);
        }
        if (!$s['is_minor']) {
            $uid = $s['user_id'] ?: AccountService::ensureUser($aid, 'student', $s['name'], $s['country_code'], $s['whatsapp'], $s['email']);
            if (!$s['user_id']) {
                DB::update('students', ['user_id' => $uid], 'id = ?', [$studentId]);
            }
            $out[] = ['name' => $s['name'], 'role' => 'student'] + AccountService::invite($uid);
        }
        Audit::log($ctx, 'student.invite', 'students', $studentId);
        return $out;
    }

    /** Moves a student (the same profile) to another branch, optionally into one of its batches. */
    private static function normName(?string $n): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} ]+/u', ' ', mb_strtolower((string) $n))));
    }

    /**
     * Possible duplicate profiles of the same person in this academy (any branch, any status).
     * A match needs a similar NAME plus at least one more signal (same WhatsApp, same guardian, same DOB).
     * Different name + same WhatsApp = family members sharing a number: not a duplicate.
     * Known but different dates of birth rule a match out.
     */
    public static function possibleDuplicates(int $academyId, string $name, ?string $wa, ?string $guardian, ?string $dob, ?int $excludeId = null): array
    {
        $n = self::normName($name);
        if ($n === '') {
            return [];
        }
        $first = explode(' ', $n)[0];
        $g = self::normName($guardian);
        $rows = DB::all("SELECT s.id, s.student_code, s.name, s.status, s.whatsapp, s.guardian_name, s.dob, s.branch_id, br.name AS branch_name
                         FROM students s LEFT JOIN academy_branches br ON br.id = s.branch_id
                         WHERE s.academy_id = ? AND s.id <> ? AND (s.whatsapp = ? OR (s.dob IS NOT NULL AND s.dob = ?) OR s.name LIKE ?)
                         LIMIT 200", [$academyId, $excludeId ?? 0, (string) $wa, $dob ?: '0000-00-00', '%' . str_replace(['%', '_'], ['\%', '\_'], $first) . '%']);
        $out = [];
        foreach ($rows as $r) {
            $rn = self::normName($r['name']);
            $exact = $rn === $n;
            $similar = $exact || (min(mb_strlen($rn), mb_strlen($n)) >= 4 && levenshtein($rn, $n) <= max(1, intdiv(mb_strlen($n), 6)));
            if (!$similar) {
                continue;
            }
            if ($dob && $r['dob'] && $dob !== $r['dob']) {
                continue; // same name, different birthdays: different people
            }
            $reasons = [$exact ? 'same name' : 'similar name'];
            $sameWa = $wa && $r['whatsapp'] === $wa;
            $sameG = $g !== '' && self::normName($r['guardian_name']) === $g;
            $sameDob = $dob && $r['dob'] === $dob;
            if ($sameWa) {
                $reasons[] = 'same WhatsApp number';
            }
            if ($sameG) {
                $reasons[] = 'same parent/guardian';
            }
            if ($sameDob) {
                $reasons[] = 'same date of birth';
            }
            if (count($reasons) < 2) {
                continue; // a common name alone is not a duplicate
            }
            $out[] = ['id' => (int) $r['id'], 'student_code' => $r['student_code'], 'name' => $r['name'], 'status' => $r['status'], 'branch_name' => $r['branch_name'] ?? 'Main',
                      'guardian_name' => $r['guardian_name'], 'dob' => $r['dob'], 'whatsapp_last4' => substr((string) $r['whatsapp'], -4),
                      'reasons' => $reasons, 'level' => ($exact && $sameWa) || $sameDob ? 'high' : 'medium'];
        }
        usort($out, static fn($a, $b) => [$b['level'] === 'high', $b['status'] === 'active'] <=> [$a['level'] === 'high', $a['status'] === 'active']);
        return array_slice($out, 0, 10);
    }

    /**
     * "Link existing student": use the existing profile for a new admission instead of creating another one.
     * Re-activates it if needed (quota checked), enrols it in the activity/batch, and optionally sets the branch.
     */
    public static function linkExisting(Ctx $ctx, int $studentId, array $in): array
    {
        $aid = $ctx->academy();
        $s = $ctx->assertStudent($studentId);
        $act = Crud::find('activities', (int) ($in['activity_id'] ?? 0), $aid, 'Activity');
        $batchId = !empty($in['batch_id']) ? (int) $in['batch_id'] : null;
        if ($batchId && !DB::val('SELECT 1 FROM batches WHERE id = ? AND activity_id = ? AND academy_id = ?', [$batchId, $act['id'], $aid])) {
            throw ApiException::validation(['batch_id' => 'Select a batch of this activity.']);
        }
        DB::tx(static function () use ($ctx, $aid, $s, $act, $batchId, $in) {
            if ($s['status'] !== 'active') {
                Feature::assertStudentCapacity($aid);
                DB::update('students', ['status' => 'active'], 'id = ?', [$s['id']]);
                Audit::log($ctx, 'student.reactivate', 'students', (int) $s['id'], ['status' => $s['status']], ['status' => 'active']);
            }
            $cur = DB::one("SELECT * FROM student_activity_mapping WHERE student_id = ? AND activity_id = ? AND status = 'active' LIMIT 1", [$s['id'], $act['id']]);
            if ($cur && $batchId && (int) $cur['batch_id'] !== $batchId) {
                self::transfer($ctx, (int) $s['id'], $batchId);
            } elseif (!$cur) {
                self::enroll($ctx, (int) $s['id'], ['activity_id' => $act['id'], 'batch_id' => $batchId] + $in);
            }
            if (!empty($in['branch_id'])) {
                $bid = (int) $in['branch_id'];
                if (!DB::val("SELECT 1 FROM academy_branches WHERE id = ? AND academy_id = ? AND status = 'active'", [$bid, $aid])) {
                    throw ApiException::validation(['branch_id' => 'Select a valid branch.']);
                }
                DB::update('students', ['branch_id' => $bid], 'id = ?', [$s['id']]);
            } elseif ($batchId && ($bb = DB::val('SELECT branch_id FROM batches WHERE id = ?', [$batchId]))) {
                DB::update('students', ['branch_id' => (int) $bb], 'id = ?', [$s['id']]);
            }
            Audit::log($ctx, 'student.link_existing', 'students', (int) $s['id'], null, ['activity' => $act['name'], 'batch_id' => $batchId]);
        });
        return self::get($ctx, $studentId);
    }

    /** Enrol an existing student in another activity (with its monthly fee). */
    public static function enroll(Ctx $c, int $sid, array $in): int
    {
        $aid = $c->academy();
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
        return $eid;
    }

    /**
     * WHERE clause for student lists, pickers and bulk actions (server-side search, never loads everyone).
     * q matches name, Student ID, WhatsApp or guardian; plus status, branch_id ("none" = no branch),
     * not_branch_id, activity_id, batch_id and the teacher's batch scope.
     */
    public static function listWhere(Ctx $c, array $q): array
    {
        $w = 's.academy_id = ?';
        $p = [$c->academy()];
        if (($t = trim((string) ($q['q'] ?? ''))) !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $t) . '%';
            $digits = preg_replace('/\D/', '', $t);
            $w .= ' AND (s.name LIKE ? OR s.student_code LIKE ? OR s.guardian_name LIKE ?' . (strlen($digits) >= 4 ? ' OR s.whatsapp LIKE ?' : '') . ')';
            array_push($p, $like, $like, $like);
            if (strlen($digits) >= 4) {
                $p[] = '%' . $digits . '%';
            }
        }
        $status = $q['status'] ?? 'active';
        if ($status !== 'all' && $status !== '') {
            $w .= ' AND s.status = ?';
            $p[] = $status;
        }
        $br = $q['branch_id'] ?? '';
        if ($br === 'none') {
            $w .= ' AND s.branch_id IS NULL';
        } elseif ($br !== '' && $br !== null) {
            $w .= ' AND s.branch_id = ?';
            $p[] = (int) $br;
        }
        if (!empty($q['not_branch_id'])) {
            $w .= ' AND (s.branch_id IS NULL OR s.branch_id <> ?)';
            $p[] = (int) $q['not_branch_id'];
        }
        foreach (['batch_id' => 'm.batch_id', 'activity_id' => 'm.activity_id'] as $k => $col) {
            if ($v = (int) ($q[$k] ?? 0)) {
                $w .= " AND EXISTS (SELECT 1 FROM student_activity_mapping m WHERE m.student_id = s.id AND m.status = 'active' AND $col = ?)";
                $p[] = $v;
            }
        }
        $scope = $c->batchScope();
        if ($scope !== null) {
            $w .= $scope ? ' AND EXISTS (SELECT 1 FROM student_activity_mapping m WHERE m.student_id = s.id AND m.status = \'active\' AND m.batch_id IN (' . DB::inList($scope) . '))' : ' AND 0';
            array_push($p, ...$scope);
        }
        if (!empty($q['dues']) && $c->can('fees.view')) {
            $w .= " AND EXISTS (SELECT 1 FROM fee_invoices i WHERE i.student_id = s.id AND i.status IN ('unpaid','partial'))";
        }
        return [$w, $p];
    }

    /** Moves many students (same profiles, same Student IDs) to a branch, optionally into a batch there. */
    public static function bulkChangeBranch(Ctx $ctx, int $branchId, ?int $batchId, ?array $ids, ?array $filter, bool $preview): array
    {
        $aid = $ctx->academy();
        $branch = DB::one("SELECT id, name FROM academy_branches WHERE id = ? AND academy_id = ? AND status = 'active'", [$branchId, $aid]);
        if (!$branch) {
            throw ApiException::notFound('Branch');
        }
        if ($batchId) {
            $bb = DB::one("SELECT id, branch_id FROM batches WHERE id = ? AND academy_id = ? AND status = 'active'", [$batchId, $aid]);
            if (!$bb) {
                throw ApiException::notFound('Batch');
            }
            if ($bb['branch_id'] !== null && (int) $bb['branch_id'] !== $branchId) {
                throw ApiException::validation(['batch_id' => 'This batch belongs to a different branch.']);
            }
        }
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter($ids)));
            $found = $ids ? array_map('intval', DB::col('SELECT id FROM students WHERE academy_id = ? AND id IN (' . DB::inList($ids) . ')', array_merge([$aid], $ids))) : [];
        } elseif ($filter !== null) {
            [$w, $p] = self::listWhere($ctx, $filter + ['status' => 'active']);
            $found = array_map('intval', DB::col("SELECT s.id FROM students s WHERE $w", $p));
        } else {
            throw ApiException::validation(['student_ids' => 'Select at least one student.']);
        }
        if (!$found) {
            throw ApiException::validation(['student_ids' => 'Select at least one student.']);
        }
        if ($preview) {
            return ['preview' => true, 'count' => count($found), 'branch_name' => $branch['name']];
        }
        $moved = 0;
        $skipped = [];
        DB::tx(static function () use ($ctx, $aid, $branch, $batchId, $found, &$moved, &$skipped) {
            foreach (array_chunk($found, 500) as $chunk) {
                if (!$batchId) { // branch only: one UPDATE per chunk
                    $before = DB::all('SELECT id, branch_id FROM students WHERE id IN (' . DB::inList($chunk) . ') AND (branch_id IS NULL OR branch_id <> ?)', array_merge($chunk, [$branch['id']]));
                    if ($before) {
                        $chg = array_map('intval', array_column($before, 'id'));
                        DB::exec('UPDATE students SET branch_id = ? WHERE academy_id = ? AND id IN (' . DB::inList($chg) . ')', array_merge([$branch['id'], $aid], $chg));
                        $moved += count($chg);
                    }
                    continue;
                }
                foreach ($chunk as $sid) {
                    try {
                        self::changeBranch($ctx, $sid, (int) $branch['id'], $batchId);
                        $moved++;
                    } catch (ApiException $e) {
                        if ($e->getCode() !== 409) { // 409 = already in that batch → still counts as done
                            throw $e;
                        }
                        DB::update('students', ['branch_id' => $branch['id']], 'id = ?', [$sid]);
                        $moved++;
                    }
                }
            }
            Audit::log($ctx, 'student.bulk_branch_change', 'academy_branches', (int) $branch['id'], null, ['students' => count($found), 'moved' => $moved, 'batch_id' => $batchId]);
        });
        return ['preview' => false, 'count' => count($found), 'moved' => $moved, 'branch_name' => $branch['name']];
    }

    public static function changeBranch(Ctx $ctx, int $studentId, int $branchId, ?int $batchId = null, ?string $date = null): array
    {
        $aid = $ctx->academy();
        $s = $ctx->assertStudent($studentId);
        if (!DB::val("SELECT 1 FROM academy_branches WHERE id = ? AND academy_id = ? AND status = 'active'", [$branchId, $aid])) {
            throw ApiException::notFound('Branch');
        }
        if ($batchId) {
            $bb = DB::val('SELECT branch_id FROM batches WHERE id = ? AND academy_id = ?', [$batchId, $aid]);
            if ($bb !== null && (int) $bb !== $branchId) {
                throw ApiException::validation(['batch_id' => 'This batch belongs to a different branch.']);
            }
            self::transfer($ctx, $studentId, $batchId, $date);
        }
        if ((int) $s['branch_id'] !== $branchId) {
            DB::update('students', ['branch_id' => $branchId], 'id = ?', [$studentId]);
            Audit::log($ctx, 'student.branch_change', 'students', $studentId, ['branch_id' => $s['branch_id']], ['branch_id' => $branchId]);
        }
        return self::get($ctx, $studentId);
    }

    /** Moves a student to another batch; history (attendance + fees) stays attached to the student. */
    public static function transfer(Ctx $ctx, int $studentId, int $newBatchId, ?string $date = null): array
    {
        $aid = $ctx->academy();
        $ctx->assertStudent($studentId);
        $date ??= date('Y-m-d');
        $batch = DB::one("SELECT b.*, a.name AS activity_name FROM batches b JOIN activities a ON a.id = b.activity_id WHERE b.id = ? AND b.academy_id = ? AND b.status = 'active'", [$newBatchId, $aid]);
        if (!$batch) {
            throw ApiException::notFound('Batch');
        }
        DB::tx(static function () use ($ctx, $aid, $studentId, $batch, $date) {
            $cur = DB::one("SELECT * FROM student_activity_mapping WHERE student_id = ? AND activity_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE", [$studentId, $batch['activity_id']])
                ?? DB::one("SELECT * FROM student_activity_mapping WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE", [$studentId]);
            if ($cur && (int) $cur['batch_id'] === (int) $batch['id']) {
                throw new ApiException('The student is already in this batch.', 409);
            }
            if ($cur) {
                DB::update('student_activity_mapping', ['status' => 'transferred', 'end_date' => date('Y-m-d', strtotime("$date -1 day"))], 'id = ?', [$cur['id']]);
            }
            DB::insert('student_activity_mapping', ['academy_id' => $aid, 'student_id' => $studentId, 'activity_id' => $batch['activity_id'], 'batch_id' => $batch['id'], 'start_date' => $date, 'transferred_from_id' => $cur['id'] ?? null]);
            // moving to a batch of another branch moves the same student profile (quota unchanged)
            $oldBranch = DB::val('SELECT branch_id FROM students WHERE id = ?', [$studentId]);
            if ($batch['branch_id'] && (int) $batch['branch_id'] !== (int) $oldBranch) {
                DB::update('students', ['branch_id' => $batch['branch_id']], 'id = ?', [$studentId]);
            }
            Audit::log($ctx, 'student.transfer', 'students', $studentId, ['batch_id' => $cur['batch_id'] ?? null, 'branch_id' => $oldBranch], ['batch_id' => $batch['id'], 'branch_id' => $batch['branch_id'] ?: $oldBranch]);
        });
        NotificationService::toStudents($aid, 'batch_change', [$studentId], ['batch_name' => $batch['name'], 'activity_name' => $batch['activity_name']], ['dedupe' => "bt$studentId-{$batch['id']}-$date"]);
        return self::get($ctx, $studentId);
    }
}
