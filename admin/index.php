<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require CNX_ROOT . '/includes/panel.php';

$user = Auth::webUser(['super_admin']);
render_panel($user, 'admin', 'Dashboard', [
    ['#/', 'bi-speedometer2', 'Dashboard'],
    ['#/owners', 'bi-building', 'Owners & Academies'],
    ['#/packages', 'bi-box-seam', 'Packages'],
    ['#/sub-payments', 'bi-currency-rupee', 'Subscription Payments'],
    ['---', 'Messaging'],
    ['#/integrations', 'bi-plug', 'Integrations'],
    ['#/templates', 'bi-chat-square-text', 'Message Templates'],
    ['#/reminders', 'bi-alarm', 'Reminder Rules'],
    ['#/queue', 'bi-send-check', 'Notification Queue'],
    ['---', 'Website'],
    ['#/cms', 'bi-window', 'Website CMS'],
    ['#/enquiries', 'bi-envelope-paper', 'Enquiries'],
    ['#/support', 'bi-life-preserver', 'Support Tickets'],
    ['---', 'System'],
    ['#/reports', 'bi-bar-chart', 'Reports'],
    ['#/audit', 'bi-journal-text', 'Audit Log'],
    ['#/settings', 'bi-gear', 'Global Settings'],
    ['#/health', 'bi-heart-pulse', 'System Health'],
], [cnx_url('admin/admin.js')]);
