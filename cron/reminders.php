<?php
// Runs only the "reminders" task. Normally not needed: one cron line for cron/run.php (all tasks) is enough.
// Use this if your host limits how long a single cron job may run:  php cron/reminders.php
$argv = [$argv[0] ?? __FILE__, 'reminders'];
require __DIR__ . '/run.php';
