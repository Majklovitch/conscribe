<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
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
$router->run();