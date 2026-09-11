<?php

namespace Modules\Auth\Middleware;

use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Modules\Auth\ApiTokens;
use Modules\Auth\Auth;

/**
 * Admits a request carrying a valid `Authorization: Bearer <token>`.
 *
 * The user it resolves is handed to Auth, so a controller asks `$auth->user()`
 * the same way whichever guard let the request in - and never touches the
 * session, so an API response sets no cookie.
 *
 * Routes behind this should opt out of CSRF: a bearer token is not sent by the
 * browser on its own, which is the whole attack CSRF defends against, and an
 * API client has no CSRF token to send.
 *
 *   $router->group(['middleware' => [RequireToken::class], 'csrf' => false], ...);
 */
final class RequireToken implements MiddlewareInterface {
    public function __construct(private ApiTokens $tokens, private Auth $auth) {
    }

    public function process(Request $request, callable $next): Response {
        $header = (string) $request->getHeader('authorization');

        $user = preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1
            ? $this->tokens->userFor($match[1])
            : null;

        if ($user === null) {
            // One answer for "no token" and "wrong token", so a caller cannot
            // tell which tokens exist. WWW-Authenticate is what RFC 6750 asks
            // a 401 for a bearer-protected resource to carry.
            return Response::problem(401, 'A valid API token is required.', instance: '/' . $request->getPath())
                ->setHeader('WWW-Authenticate', 'Bearer');
        }

        $this->auth->setUser($user);

        return $next($request);
    }
}
