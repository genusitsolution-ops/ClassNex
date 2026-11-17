<?php
declare(strict_types=1);

/**
 * Single entry point for every PHP script (website, panels, API, cron).
 */
define('CNX_ROOT', dirname(__DIR__));
define('CNX_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    foreach (['classes', 'services', 'services/channels'] as $dir) {
        $file = CNX_ROOT . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

$configFile = getenv('CNX_CONFIG') ?: CNX_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI !== 'cli' && !defined('CNX_INSTALLER')) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/install.php');
        exit;
    }
    Config::load([]);
} else {
    Config::load(require $configFile);
}

date_default_timezone_set(Config::get('timezone', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

$debug = (bool) Config::get('debug', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', Config::storage('logs/php-error.log'));
error_reporting(E_ALL);

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cnx_url(string $path = ''): string
{
    return rtrim((string) Config::get('app_url', ''), '/') . '/' . ltrim($path, '/');
}
