<?php
declare(strict_types=1);

final class AnnouncementService
{
    /** Sends notifications for an announcement exactly once (notified_at guard). */
    public static function publish(int $id): int
    {
        $claimed = DB::exec('UPDATE announcements SET notified_at = NOW() WHERE id = ? AND notified_at IS NULL AND notify = 1', [$id]);
        if (!$claimed) {
            return 0;
        }
        $a = DB::one('SELECT * FROM announcements WHERE id = ?', [$id]);
        $aid = (int) $a['academy_id'];
        $vars = ['title' => $a['title'], 'message' => $a['body']];
        $n = 0;
        if (in_array($a['audience'], ['all', 'students', 'batch'], true)) {
            $ids = $a['audience'] === 'batch'
                ? DB::col("SELECT m.student_id FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.batch_id = ? AND m.status = 'active' AND s.status = 'active'", [$a['batch_id']])
                : DB::col("SELECT id FROM students WHERE academy_id = ? AND status = 'active'", [$aid]);
            $n += array_sum(NotificationService::toStudents($aid, 'announcement', $ids, $vars, ['dedupe' => "ann$id", 'created_by' => $a['created_by']]));
        }
        if (in_array($a['audience'], ['all', 'teachers'], true)) {
            $users = DB::col("SELECT user_id FROM teachers WHERE academy_id = ? AND status = 'active' AND user_id IS NOT NULL", [$aid]);
            $n += array_sum(NotificationService::toUsers($aid, 'announcement', $users, $vars, ['dedupe' => "ann$id", 'channels' => ['inapp', 'push']]));
        }
        return $n;
    }

    /** Cron: publishes scheduled announcements whose time has come. */
    public static function publishDue(): int
    {
        $n = 0;
        foreach (DB::col('SELECT id FROM announcements WHERE notify = 1 AND notified_at IS NULL AND publish_at <= NOW() LIMIT 100') as $id) {
            self::publish((int) $id);
            $n++;
        }
        return $n;
    }
}
