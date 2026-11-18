<?php
// Runs only the "queue" task. Normally not needed: one cron line for cron/run.php (all tasks) is enough.
// Use this if your host limits how long a single cron job may run:  php cron/queue.php
$argv = [$argv[0] ?? __FILE__, 'queue'];
require __DIR__ . '/run.php';
