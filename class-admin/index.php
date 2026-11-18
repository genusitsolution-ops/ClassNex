<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require CNX_ROOT . '/includes/panel.php';

$user = Auth::webUser(['owner']);
render_panel($user, 'owner', 'Dashboard', [
    ['#/', 'bi-speedometer2', 'Dashboard'],
    ['#/students', 'bi-people', 'Students'],
    ['#/attendance', 'bi-calendar-check', 'Attendance', 'attendance'],
    ['#/collect', 'bi-cash-coin', 'Collect Fee', 'fees'],
    ['#/fees', 'bi-hourglass-split', 'Pending Fees', 'fees'],
    ['#/payments', 'bi-receipt', 'Payments & Receipts', 'fees'],
    ['#/messages', 'bi-whatsapp', 'Reminders & Messages', 'manual_reminders'],
    ['---', 'Academy'],
    ['#/branches', 'bi-geo-alt', 'Branches'],
    ['#/activities', 'bi-collection', 'Activities'],
    ['#/batches', 'bi-diagram-3', 'Batches'],
    ['#/timetable', 'bi-clock', 'Timetable'],
    ['#/teachers', 'bi-person-badge', 'Teachers'],
    ['#/parents', 'bi-people-fill', 'Parents'],
    ['#/admissions', 'bi-inbox', 'Online Admissions', 'online_admission'],
    ['#/fee-plans', 'bi-tags', 'Fee Plans', 'fees'],
    ['#/announcements', 'bi-megaphone', 'Announcements'],
    ['#/holidays', 'bi-calendar-heart', 'Holidays'],
    ['#/leaves', 'bi-calendar-x', 'Teacher Leaves'],
    ['---', 'Insights'],
    ['#/reports', 'bi-bar-chart', 'Reports'],
    ['#/message-log', 'bi-list-check', 'Message Log'],
    ['---', 'Account'],
    ['#/subscription', 'bi-gem', 'Subscription'],
    ['#/settings', 'bi-gear', 'Settings'],
    ['#/support', 'bi-life-preserver', 'Help & Support'],
], [cnx_url('assets/js/academy.js')]);
