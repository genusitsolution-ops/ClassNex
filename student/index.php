<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require CNX_ROOT . '/includes/panel.php';

$user = Auth::webUser(['parent', 'student']);
if ($user['academy_id'] && !Feature::has((int) $user['academy_id'], 'parent_access')) {
    http_response_code(403);
    exit('Parent / student access is not enabled for this academy. Please contact the academy.');
}
render_panel($user, 'student', 'Home', [
    ['#/', 'bi-house', 'Home'],
    ['#/attendance', 'bi-calendar-check', 'Attendance', 'attendance'],
    ['#/fees', 'bi-wallet2', 'Fees & Receipts', 'fees'],
    ['#/timetable', 'bi-clock', 'Timetable'],
    ['#/announcements', 'bi-megaphone', 'Announcements'],
    ['#/holidays', 'bi-calendar-heart', 'Holidays'],
    ['#/notifications', 'bi-bell', 'Notifications'],
    ['#/support', 'bi-life-preserver', 'Help'],
], [cnx_url('student/portal.js')]);
