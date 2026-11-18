<?php
// Runs only the "subscriptions" task. Normally not needed: one cron line for cron/run.php (all tasks) is enough.
// Use this if your host limits how long a single cron job may run:  php cron/subscriptions.php
$argv = [$argv[0] ?? __FILE__, 'subscriptions'];
require __DIR__ . '/run.php';
