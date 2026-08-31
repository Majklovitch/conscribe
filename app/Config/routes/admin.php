<?php
/**
 * Pages that must not be indexed - the administration, internal tools, form
 * targets, anything behind a login.
 *
 * The group in routes.php registers this file with ['sitemap' => false], so
 * nothing here reaches sitemap.xml. That is the point of the split: a route is
 * kept out of the sitemap by living in this file, not by remembering a flag.
 *
 * @var \App\Core\Http\Router $router
 */
