<?php
/** @var \App\Core\Http\Router $router */

use App\Controllers\WebController;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Template\View;
use App\Models\Menu\MenuRepository;

$router->get('', [WebController::class, 'index']);
$router->get('test', [WebController::class, 'test']);


/**
 * Chybové stránky. Router sám o menu ani šablonách aplikace nic neví,
 * takže si jejich vykreslení registruje aplikace tady.
 */
$router->setErrorHandler(static function (int $code, string $message, Request $request): Response {
    $content = View::render('404', [
        'pageTitle' => "Error {$code}",
        'code'      => $code,
        'message'   => $message,
        'menuItems' => (new MenuRepository())->all(),
    ]);

    return Response::html($content, $code);
});
