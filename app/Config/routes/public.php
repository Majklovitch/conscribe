<?php

/**
 * Public pages.
 *
 * Everything registered here lands in sitemap.xml automatically - the group in
 * routes.php supplies the defaults, so a new page needs nothing extra. Per-route
 * options override those defaults:
 *
 *   ['sitemap' => false]                                     leave this page out
 *   ['sitemap' => ['changefreq' => 'daily', 'priority' => 1.0]]
 *   ['sitemap' => ['provider' => fn () => $repo->slugs()]]    expand {slug} routes
 *
 * A provider returns rows of parameter values (['slug' => 'hello']) or ready
 * App\Services\Sitemap\SitemapUrl objects when it has a lastmod to offer.
 *
 * @var \App\Core\Http\Router $router
 */

use App\Controllers\WebController;

$router->get('', [WebController::class, 'index'], [
    'sitemap' => ['changefreq' => 'daily', 'priority' => 1.0],
]);

$router->get('test', [WebController::class, 'test']);
