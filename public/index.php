<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\ErrorController;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Http\Session;
use App\Core\Logging\ErrorHandler;
use App\Core\Module\ModuleManager;

$isSecure = Request::isSecure();
$isDevelopment = Config::get('conscribe.environment', 'production') === 'development';

error_reporting(E_ALL);
ini_set('display_errors', $isDevelopment ? '1' : '0');
ini_set('display_startup_errors', $isDevelopment ? '1' : '0');
ini_set('log_errors', '1');

// Both the error page and the logger are built only once something fails.
// Nothing is logged on a successful request, so there is no reason to pull in
// Monolog - and constructing ErrorController also reaches into the database
// for the menu.
$errorPage = static function (int $code, string $message, Request $request): Response {
    static $controller = null;
    $controller ??= new ErrorController();

    return $controller($code, $message, $request);
};

ErrorHandler::register($isDevelopment, $errorPage);

if (!headers_sent()) {
    if ($isSecure) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
}

$session = Session::instance();

$request = Request::createFromGlobals();
$router = new Router($session);

$router->setErrorHandler($errorPage);

$modules = new ModuleManager();
$request = $modules->boot($request);
$modules->registerRoutes($router);

require __DIR__ . '/../app/Config/routes.php';
$router->run($request);
