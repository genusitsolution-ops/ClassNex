<?php
declare(strict_types=1);

/** Authenticated request context: who is calling, for which academy, with which rights. */
final class Ctx
{
    public int $userId;
    public string $role;
    public ?int $academyId;
    public ?int $teacherId = null;
    public ?int $parentId = null;
    public ?int $studentId = null;
    public array $perms = [];
    private ?array $batchCache = null;
    private ?array $studentCache = null;

    public function __construct(public array $user, public string $via = 'token')
    {
        $this->userId = (int) $user['id'];
        $this->role = $user['role'];
        $this->academyId = $user['academy_id'] !== null ? (int) $user['academy_id'] : null;

        if ($this->role === 'teacher') {
            $this->teacherId = (int) DB::val('SELECT id FROM teachers WHERE user_id = ? AND academy_id = ?', [$this->userId, $this->academyId]) ?: null;
            $perms = DB::col('SELECT perm_key FROM role_permissions WHERE role_key = ?', ['teacher']);
            foreach (DB::all('SELECT perm_key, granted FROM user_permissions WHERE user_id = ?', [$this->userId]) as $p) {
                if ($p['granted']) {
                    $perms[] = $p['perm_key'];
                } else {
                    $perms = array_diff($perms, [$p['perm_key']]);
                }
            }
            $this->perms = array_values(array_unique($perms));
        } elseif ($this->role === 'parent') {
            $this->parentId = (int) DB::val('SELECT id FROM parents WHERE user_id = ? AND academy_id = ?', [$this->userId, $this->academyId]) ?: null;
        } elseif ($this->role === 'student') {
            $this->studentId = (int) DB::val('SELECT id FROM students WHERE user_id = ? AND academy_id = ?', [$this->userId, $this->academyId]) ?: null;
        } elseif ($this->role === 'owner') {
            $this->perms = DB::col('SELECT perm_key FROM permissions');
        }
    }

    public function can(string $perm): bool
    {
        return match ($this->role) {
            'super_admin', 'owner' => true,
            'teacher' => in_array($perm, $this->perms, true),
            default => false,
        };
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['owner', 'teacher'], true);
    }

    public function academy(): int
    {
        if (!$this->academyId) {
            throw ApiException::forbidden('An academy account is required for this action.');
        }
        return $this->academyId;
    }

    /** Batch IDs the caller may work with. NULL = every batch in the academy. */
    public function batchScope(): ?array
    {
        if ($this->role === 'owner') {
            return null;
        }
        if ($this->role !== 'teacher') {
            return [];
        }
        if ($this->batchCache === null) {
            $this->batchCache = array_map('intval', DB::col(
                'SELECT id FROM batches WHERE academy_id = ? AND teacher_id = ?
                 UNION SELECT tb.batch_id FROM teacher_batches tb JOIN batches b ON b.id = tb.batch_id WHERE tb.teacher_id = ? AND b.academy_id = ?',
                [$this->academyId, $this->teacherId ?? 0, $this->teacherId ?? 0, $this->academyId]
            ));
        }
        return $this->batchCache;
    }

    public function assertBatch(int $batchId): void
    {
        $ok = DB::val('SELECT 1 FROM batches WHERE id = ? AND academy_id = ?', [$batchId, $this->academy()]);
        $scope = $this->batchScope();
        if (!$ok || ($scope !== null && !in_array($batchId, $scope, true))) {
            throw ApiException::notFound('Batch');
        }
    }

    /** Students visible to a parent / student login. */
    public function familyStudentIds(): array
    {
        if ($this->studentCache !== null) {
            return $this->studentCache;
        }
        $ids = [];
        if ($this->role === 'parent' && $this->parentId) {
            $ids = DB::col('SELECT m.student_id FROM parent_student_mapping m JOIN students s ON s.id = m.student_id
                            WHERE m.parent_id = ? AND s.academy_id = ? ORDER BY s.name', [$this->parentId, $this->academyId]);
        } elseif ($this->role === 'student' && $this->studentId) {
            $ids = [$this->studentId];
        }
        return $this->studentCache = array_map('intval', $ids);
    }

    /** Throws unless the caller may see this student. Returns the student row. */
    public function assertStudent(int $studentId): array
    {
        $s = DB::one('SELECT * FROM students WHERE id = ? AND academy_id = ?', [$studentId, $this->academy()]);
        if (!$s) {
            throw ApiException::notFound('Student');
        }
        if (in_array($this->role, ['parent', 'student'], true)) {
            if (!in_array($studentId, $this->familyStudentIds(), true)) {
                throw ApiException::notFound('Student');
            }
        } elseif ($this->role === 'teacher') {
            $scope = $this->batchScope();
            $in = $scope ? DB::val('SELECT 1 FROM student_activity_mapping WHERE student_id = ? AND batch_id IN (' . DB::inList($scope) . ') LIMIT 1', array_merge([$studentId], $scope)) : false;
            if (!$in) {
                throw ApiException::notFound('Student');
            }
        }
        return $s;
    }
}
