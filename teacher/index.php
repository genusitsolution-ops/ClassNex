<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require CNX_ROOT . '/includes/panel.php';

$user = Auth::webUser(['teacher']);
$ctx = new Ctx($user, 'session');
$menu = [
    ['#/', 'bi-speedometer2', 'Dashboard'],
    ['#/attendance', 'bi-calendar-check', 'Mark Attendance', 'attendance'],
    ['#/attendance-history', 'bi-clock-history', 'Attendance History', 'attendance'],
    ['#/batches', 'bi-diagram-3', 'My Batches'],
    ['#/students', 'bi-people', 'My Students'],
    ['#/timetable', 'bi-clock', 'Timetable'],
];
if ($ctx->can('students.manage')) { $menu[] = ['#/students/new', 'bi-person-plus', 'Add Student']; }
if ($ctx->can('fees.collect')) { $menu[] = ['#/collect', 'bi-cash-coin', 'Collect Fee', 'fees']; }
if ($ctx->can('fees.view')) { $menu[] = ['#/fees', 'bi-hourglass-split', 'Pending Fees', 'fees']; $menu[] = ['#/payments', 'bi-receipt', 'Payments', 'fees']; }
if ($ctx->can('notifications.send')) { $menu[] = ['#/messages', 'bi-chat-dots', 'Send Message', 'manual_reminders']; }
if ($ctx->can('reports.view')) { $menu[] = ['#/reports', 'bi-bar-chart', 'Reports']; }
$menu = array_merge($menu, [
    ['---', 'More'],
    ['#/announcements', 'bi-megaphone', 'Announcements'],
    ['#/holidays', 'bi-calendar-heart', 'Holidays'],
    ['#/leaves', 'bi-calendar-x', 'My Leave'],
    ['#/notifications', 'bi-bell', 'Notifications'],
    ['#/support', 'bi-life-preserver', 'Help'],
]);
render_panel($user, 'teacher', 'Dashboard', $menu, [cnx_url('assets/js/academy.js')]);
