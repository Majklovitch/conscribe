<?php

namespace Modules\Auth\Middleware;

use App\Core\Config;
use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Modules\Auth\Auth;

/**
 * Keeps signed-in users away from pages meant for guests - the login and
 * registration forms - by sending them to 'auth.home' ('/' by default).
 */
final class Guest implements MiddlewareInterface {
    public function __construct(private Auth $auth) {
    }

    public function process(Request $request, callable $next): Response {
        if ($this->auth->check()) {
            return Response::redirect((string) Config::get('auth.home', '/'));
        }

        return $next($request);
    }
}
