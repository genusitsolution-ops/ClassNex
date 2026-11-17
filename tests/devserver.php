<?php
// Router for PHP's built-in server: php -S 127.0.0.1:8080 tests/devserver.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/api/v1/#', $path)) {
    $_GET['_route'] = substr($path, 7);
    require __DIR__ . '/../api/v1/index.php';
    return true;
}
if (preg_match('#^/(config|classes|services|includes|database|storage|cli|cron|tests|docs|android)/#', $path)) {
    http_response_code(403);
    return true;
}
$file = __DIR__ . '/..' . $path;
if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.php';
}
if (is_file($file) && str_ends_with($file, '.php')) {
    chdir(dirname($file));
    $_SERVER['SCRIPT_NAME'] = $path;
    require $file;
    return true;
}
return false;
