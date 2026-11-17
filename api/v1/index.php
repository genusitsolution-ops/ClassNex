<?php
declare(strict_types=1);

/**
 * ClassNex REST API v1 — the single backend for the website, web panels and the Android app.
 * All responses: {"success":bool, "message":string, "data":..., "meta"?:..., "errors"?:{field:msg}}
 */
require __DIR__ . '/../../includes/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$appHost = parse_url((string) Config::get('app_url', ''), PHP_URL_HOST);
if ($origin && parse_url($origin, PHP_URL_HOST) === $appHost) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, Idempotency-Key');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}
if (Http::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = $_GET['_route'] ?? (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . ltrim(preg_replace('#^.*?/api/v1#', '', $path), '/');
$method = Http::method();
if ($method === 'POST' && isset($_POST['_method']) && in_array($_POST['_method'], ['PUT', 'DELETE'], true)) {
    $method = $_POST['_method'];
}

$router = new Router();
foreach (glob(__DIR__ . '/routes/*.php') as $file) {
    (require $file)($router);
}

try {
    if (!Config::isLoaded()) {
        throw new ApiException('ClassNex is not installed yet.', 503);
    }
    $result = $router->dispatch($method, $path);
    Http::json($result);
} catch (ApiException $e) {
    $body = ['success' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode];
    if ($e->errors) {
        $body['errors'] = $e->errors;
    }
    Http::json($body, $e->status);
} catch (PDOException $e) {
    error_log('[api] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $dup = str_contains($e->getMessage(), 'Duplicate entry');
    Http::json(['success' => false, 'message' => $dup ? 'This record already exists.' : 'A database error occurred. Please try again.', 'code' => $dup ? 'duplicate' : 'db_error'] + (Config::get('debug') ? ['debug' => $e->getMessage()] : []), $dup ? 409 : 500);
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Http::json(['success' => false, 'message' => 'Something went wrong. Please try again.', 'code' => 'server_error'] + (Config::get('debug') ? ['debug' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()] : []), 500);
}
