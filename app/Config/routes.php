<?php

/**
 * Route table.
 *
 * The split is what decides which pages end up in sitemap.xml: the file a route
 * lives in carries the policy, so it is stated once here instead of on every
 * line. A single route can still override its group.
 *
 * @var \App\Core\Http\Router $router
 */

use App\Core\Http\Router;

$router->group(['sitemap' => ['changefreq' => 'weekly', 'priority' => 0.7]], function (Router $router): void {
    require __DIR__ . '/routes/public.php';
});

$router->group(['sitemap' => false], function (Router $router): void {
    require __DIR__ . '/routes/admin.php';
});
