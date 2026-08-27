<?php
namespace App\Core\Http;

use App\Core\Template\View;
use RuntimeException;

abstract class Controller {
    protected ?Request $request = null;

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    protected function request(): Request
    {
        if ($this->request === null) {
            throw new RuntimeException(static::class . ' has no Request; was it created outside the Router?');
        }

        return $this->request;
    }

    /**
     * Renders a view template and returns it as a Response.
     * @throws \Throwable
     */
    protected function render(string $view, array $params = [], string $layout = 'main', int $status = 200): Response
    {
        return Response::html(View::render($view, $params, $layout), $status);
    }

    /**
     * Helper to return a JSON Response.
     * @throws \JsonException
     */
    protected function json(mixed $data, int $statusCode = 200, array $headers = []): Response
    {
        return Response::json($data, $statusCode, $headers);
    }

    protected function redirect(string $url, int $statusCode = 302, array $headers = []): Response {
        return Response::redirect($url, $statusCode, $headers);
    }
}
