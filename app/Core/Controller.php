<?php
namespace App\Core;

use App\Core\View;
use JetBrains\PhpStorm\NoReturn;

abstract class Controller {
    protected ?Request $request = null;

    /**
     * Sets the request instance on the controller.
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    /**
     * Renders a view template and returns it as a Response.
     */
    protected function render(string $view, array $params = [], $layout = 'main'): Response
    {
        $content = View::render($view, $params, $layout);
        return new Response($content);
    }

    /**
     * Helper to return a JSON Response.
     */
    protected function json(mixed $data, int $statusCode = 200, array $headers = []): Response
    {
        return Response::json($data, $statusCode, $headers);
    }

    /**
     * Helper to return a Redirect Response.
     */
    protected function redirect(string $url, int $statusCode = 302, array $headers = []): Response {
        return Response::redirect($url, $statusCode, $headers);
    }
}