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
            $hasBatches = (int) DB::val("SELECT COUNT(*) FROM batches WHERE activity_id = ? AND status = 'active'", [$activity['id']]);
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
        if (empty($in['confirm_duplicate'])) {
            $dup = DB::one("SELECT id, student_code, name FROM students WHERE academy_id = ? AND whatsapp = ? AND LOWER(name) = LOWER(?) AND status = 'active'", [$aid, $wa, trim($d['name'])]);
            if ($dup) {
                throw new ApiException("{$dup['name']} ({$dup['student_code']}) is already registered with this WhatsApp number.", 409, ['duplicate_id' => $dup['id']], 'duplicate_student');
            }
        }
        Feature::assertLimit($aid, 'students');
        if (!empty($in['consent_given']) === false && $isMinor && !empty($in['require_consent'])) {
            throw ApiException::validation(['consent_given' => 'Parent / guardian consent is required.']);
        }

        $photo = Upload::storePhoto('photo', $aid);
        $academy = DB::one('SELECT code, name FROM academies WHERE id = ?', [$aid]);
        $settings = Settings::academy($aid);
        try {
        $studentId = DB::tx(static function () use ($ctx, $aid, $d, $in, $cc, $wa, $isMinor, $activity, $batch, $photo, $academy, $settings, $parentWa) {
            $prefix = $settings['student_id_prefix'] ?: $academy['code'];
            $code = $prefix . str_pad((string) Sequence::next($aid, 'student'), 4, '0', STR_PAD_LEFT);
            $admission = $d['admission_date'] ?? date('Y-m-d');
            $sid = DB::insert('students', [
                'academy_id'        => $aid,
                'branch_id'         => $d['branch_id'] ?? $activity['branch_id'],
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
        $s['enrollments'] = DB::all('SELECT m.*, a.name AS activity_name, b.name AS batch_name, b.start_time, b.end_time FROM student_activity_mapping m
                                     JOIN activities a ON a.id = m.activity_id LEFT JOIN batches b ON b.id = m.batch_id WHERE m.student_id = ? ORDER BY m.status = \'active\' DESC, m.id DESC', [$id]);
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
            Audit::log($ctx, 'student.transfer', 'students', $studentId, ['batch_id' => $cur['batch_id'] ?? null], ['batch_id' => $batch['id']]);
        });
        NotificationService::toStudents($aid, 'batch_change', [$studentId], ['batch_name' => $batch['name'], 'activity_name' => $batch['activity_name']], ['dedupe' => "bt$studentId-{$batch['id']}-$date"]);
        return self::get($ctx, $studentId);
    }
}
