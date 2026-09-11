<?php

namespace Modules\Auth\Middleware;

use App\Core\Config;
use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Modules\Auth\Auth;

/**
 * Admits a request from a signed-in session.
 *
 * A browser is sent to the login page and brought back afterwards to where it
 * was going; a client that wants JSON gets a 401 problem document instead of a
 * redirect it could not follow anyway.
 *
 * The login page is 'auth.login_url' in the configuration, '/login' by default.
 */
final class RequireLogin implements MiddlewareInterface {
    public function __construct(private Auth $auth) {
    }

    public function process(Request $request, callable $next): Response {
        if ($this->auth->check()) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::problem(401, 'You need to sign in.', instance: '/' . $request->getPath());
        }

        // Only a GET is remembered: returning someone to a POST target after
        // login would resubmit a form they filled in before they were asked
        // to sign in, and the data is gone by now anyway.
        if ($request->getMethod() === 'GET') {
            // The query string as the browser sent it, not rebuilt from the
            // parsed parameters: rebuilding re-encodes and reorders them, and
            // drops anything PHP could not parse.
            $query = parse_url((string) ($request->getServerParams()['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            $this->auth->rememberIntended('/' . $request->getPath() . (is_string($query) && $query !== '' ? '?' . $query : ''));
        }

        return Response::redirect((string) Config::get('auth.login_url', '/login'));
    }
}
