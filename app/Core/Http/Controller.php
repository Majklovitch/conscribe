<?php

namespace App\Core\Http;

use App\Core\Template\View;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use RuntimeException;

abstract class Controller {
    protected ?Request $request = null;
    protected ?Session $session = null;

    public function setRequest(Request $request): void {
        $this->request = $request;
    }

    protected function request(): Request {
        if ($this->request === null) {
            throw new RuntimeException(static::class . ' has no Request; was it created outside the Router?');
        }

        return $this->request;
    }

    public function setSession(Session $session): void {
        $this->session = $session;
    }

    protected function session(): Session {
        if ($this->session === null) {
            throw new RuntimeException(static::class . ' has no Session; was it created outside the Router?');
        }

        return $this->session;
    }

    /**
     * Validates the request body and returns only the fields asked for.
     *
     * Throws on failure, so an action can validate on its first line and then
     * write the happy path straight through. The router turns the exception
     * into a redirect back with the errors and the old input, or a 422 for a
     * client that wants JSON - see Router::handle().
     *
     *   $data = $this->validate([
     *       'email'   => 'required|email|max:255',
     *       'message' => 'required|min:10',
     *   ]);
     *
     * @param array<string, string|array<int, string|\Closure>> $rules
     * @param array<string, string>                            $messages
     * @return array<string, mixed>
     * @throws ValidationException
     */
    protected function validate(array $rules, array $messages = []): array {
        $request = $this->request();

        // Query and body together, so the same action works for a form post and
        // for a filtered GET without the caller having to say which.
        $data = $request->getBodyParams() + $request->getQueryParams();

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * @throws \Throwable
     */
    protected function render(string $view, array $params = [], string $layout = 'main', int $status = 200): Response {
        return Response::html(View::render($view, $params, $layout), $status);
    }

    /**
     * @throws \JsonException
     */
    protected function json(mixed $data, int $statusCode = 200, array $headers = []): Response {
        return Response::json($data, $statusCode, $headers);
    }

    protected function redirect(string $url, int $statusCode = 302, array $headers = []): Response {
        return Response::redirect($url, $statusCode, $headers);
    }
}
