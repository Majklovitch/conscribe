<?php
/** @var App\Core\Router $router */
use App\Controllers\WebController;

$router->get('', [WebController::class, 'index']);
$router->get('test', [WebController::class, 'test']);