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
        // not installed yet: go to install.php in the installation root (works from sub-folders like /ClassNex/admin/)
        $depth = substr_count(trim(str_replace('\\', '/', substr((string) realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')), strlen(CNX_ROOT))), '/'), '/') + (realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')) === CNX_ROOT ? 0 : 1);
        header('Location: ' . str_repeat('../', $depth) . 'install.php');
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

require __DIR__ . '/functions.php';
