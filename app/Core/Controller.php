<?php
namespace App\Core;

use App\Core\View;
use JetBrains\PhpStorm\NoReturn;

abstract class Controller {
    protected function render(string $view, array $params = [], $layout = 'main'): null
    {
        return View::render($view, $params, $layout);
    }
    #[NoReturn]
    protected function redirect($url): void {
        header("Location: " . $url);
        exit;
    }

}