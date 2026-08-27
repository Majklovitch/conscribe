<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Module\ModuleManager;

$isSecure = Request::isSecure();
$isDevelopment = Config::get('conscribe.environment', 'production') === 'development';

error_reporting(E_ALL);
ini_set('display_errors', $isDevelopment ? '1' : '0');
ini_set('display_startup_errors', $isDevelopment ? '1' : '0');
ini_set('log_errors', '1');

// --- Session ---------------------------------------------------------------
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_trans_sid', '0');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    if ($isSecure) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
}

$request = Request::createFromGlobals();
$router = new Router();

$modules = new ModuleManager();
$request = $modules->boot($request);
$modules->registerRoutes($router);

require __DIR__ . '/../app/Config/routes.php';
$router->run($request);
