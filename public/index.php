<?php
session_start();
require_once '../app/Controllers/WebController.php';
require_once '../app/Modules/SitemapAutocreation/Services/SitemapService.php';

use App\Controllers\WebController;
use Modules\SitemapAutocreation\Services\SitemapService;

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts = explode('/', $path);
$pageName = $parts[0] ?: 'home';
$subPage = $parts[1] ?? null;

if($pageName == 'tasks' && $subPage == 'sitemapgen') {
    echo "test";
    $sitemapService = new SitemapService();
    $sitemapService->generate();
    echo "Sitemap generated successfully.";
    exit;
}

define('CURRENT_PAGE', $path);

$app = new WebController();
$app->render($pageName);