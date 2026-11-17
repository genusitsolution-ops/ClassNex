<?php
/**
 * ClassNex configuration.
 * Copy to config/config.php (or run /install.php) and fill in real values.
 * config/config.php is git-ignored — never commit it.
 */
return [
    'env'        => 'production',            // production | development
    'debug'      => false,                   // never true on a live server
    'app_url'    => 'https://www.example.com', // no trailing slash
    'timezone'   => 'Asia/Kolkata',

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'classnex',
        'user'    => 'classnex',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // 32-byte key, base64 encoded. Generate with:
    //   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    // Used to encrypt API credentials and student documents. Back it up —
    // losing it makes encrypted documents unrecoverable.
    'app_key' => '',

    'session_name'      => 'CNXSESS',
    'token_ttl_days'    => 30,       // Android / API bearer tokens
    'session_ttl_hours' => 12,       // web sessions

    // Absolute path for private files. Default keeps it inside the project
    // (protected by .htaccess). Moving it outside public_html is even better.
    'storage_path' => __DIR__ . '/../storage',

    // Secret used by cron.php when triggered over HTTP (wget/curl cron).
    // Leave empty to allow CLI cron only.
    'cron_http_key' => '',
];
