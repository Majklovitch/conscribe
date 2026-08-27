<?php
namespace App\Core\Http;

use App\Core\Template\View;
use RuntimeException;

abstract class Controller {
    protected ?Request $request = null;
    protected ?Session $session = null;

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

    public function setSession(Session $session): void
    {
        $this->session = $session;
    }

    protected function session(): Session
    {
        if ($this->session === null) {
            throw new RuntimeException(static::class . ' has no Session; was it created outside the Router?');
        }

        return $this->session;
    }

    /**
     * @throws \Throwable
     */
    protected function render(string $view, array $params = [], string $layout = 'main', int $status = 200): Response
    {
        return Response::html(View::render($view, $params, $layout), $status);
    }

    /**
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
