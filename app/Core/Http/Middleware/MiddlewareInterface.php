<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * One layer around the dispatch.
 *
 * Shaped like PSR-15 but over this project's own Request and Response rather
 * than PSR-7. Adopting PSR-15 properly would mean adopting PSR-7 with it and
 * giving up the lean Request/Response the rest of the core is built on; that is
 * a decision worth making deliberately, not by drifting into a half-compatible
 * shape. The contract is the same idea either way, so the port stays possible.
 *
 * A middleware may:
 *   - answer by itself, and never call $next (a rate limiter, a redirect);
 *   - change the request before passing it on;
 *   - change the response on the way back out.
 *
 *   final class Timer implements MiddlewareInterface {
 *       public function process(Request $request, callable $next): Response {
 *           $started = microtime(true);
 *
 *           return $next($request)->setHeader(
 *               'X-Duration',
 *               (string) (int) ((microtime(true) - $started) * 1000)
 *           );
 *       }
 *   }
 */
interface MiddlewareInterface {
    /**
     * @param callable(Request): Response $next the rest of the pipeline
     */
    public function process(Request $request, callable $next): Response;
}
