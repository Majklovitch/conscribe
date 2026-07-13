<?php
$httpsHeader = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
$forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$forwardedSsl = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
$serverPort = (string) ($_SERVER['SERVER_PORT'] ?? '');
$cfVisitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
$frontEndHttps = strtolower((string) ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? ''));
$xArrSsl = (string) ($_SERVER['HTTP_X_ARR_SSL'] ?? '');
$requestScheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));

$isHttpsRequest = $httpsHeader === 'on'
    || $httpsHeader === '1'
    || $serverPort === '443'
    || str_contains($forwardedProto, 'https')
    || $forwardedSsl === 'on'
    || str_contains($cfVisitor, '"scheme":"https"')
    || $frontEndHttps === 'on'
    || $frontEndHttps === '1'
    || (is_string($xArrSsl) && $xArrSsl !== '')
    || $requestScheme === 'https';

ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', $isHttpsRequest ? '1' : '0');

if ($isHttpsRequest) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttpsRequest,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
error_reporting(1);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Helpers/TemplateHelper.php';
require_once __DIR__ . '/../app/Modules/LanguageMutations/Core/Language.php';

use App\Core\Router;
/*
use Modules\LanguageMutations\Core\Language;

Language::getBrowserLang();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$parts = $uri === '/' ? [] : array_values(array_filter(explode('/', trim($uri, '/')), 'strlen'));

$lang = 'cs';
$supportedLanguages = ['cs', 'en', 'pl'];

if (!empty($parts) && in_array($parts[0], $supportedLanguages, true)) {
	$lang = array_shift($parts);
}

$page = $parts[0] ?? 'home';

if (!defined('LANG')) {
	define('LANG', $lang);
}

if (!defined('CURRENT_PAGE')) {
	define('CURRENT_PAGE', $page);
}

Language::setLang($lang);
Language::load($page);
*/
$router = new Router();
require_once __DIR__ . '/../app/Config/routes.php';
$router->run();
