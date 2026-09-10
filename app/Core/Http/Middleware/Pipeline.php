<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;
use Psr\Container\ContainerInterface;

/**
 * Runs a list of middleware around a destination.
 *
 * The list is applied outermost-first: given [A, B] and a handler H, the call
 * order is A -> B -> H, and the response travels back out B -> A. That is what
 * lets an early entry wrap everything after it, which is the whole point of
 * ordering middleware at all.
 *
 * Entries may be:
 *   - a MiddlewareInterface object;
 *   - a class name, resolved through the container (so middleware can declare
 *     its own dependencies) or, without one, constructed with `new`. Typed as
 *     a plain string rather than class-string on purpose: the name comes from a
 *     route file and may well be a typo, and reporting that is this class's job
 *     rather than something to declare away;
 *   - a callable with the same signature as process().
 *
 * Class names are resolved when the pipeline runs, not when it is declared:
 * a route file naming a middleware must not force its dependencies - a
 * database connection, say - to exist for every request that never reaches
 * that route.
 */
final class Pipeline {
    /** @var array<int, MiddlewareInterface|callable|string> */
    private array $middleware;

    private ?ContainerInterface $container;

    /**
     * @param array<int, MiddlewareInterface|callable|string> $middleware
     */
    public function __construct(array $middleware = [], ?ContainerInterface $container = null) {
        $this->middleware = array_values($middleware);
        $this->container = $container;
    }

    /**
     * Normalises whatever a route declared into a list.
     *
     * A single middleware written without the surrounding array is the obvious
     * shorthand, and casting it with (array) would quietly turn the object into
     * a list of its own properties - so `['middleware' => new Cors($origins)]`
     * would try to use a property value as a class name. Wrapping is the only
     * safe reading.
     *
     * @return array<int, MiddlewareInterface|callable|string>
     */
    public static function normalize(mixed $middleware): array {
        if ($middleware === null) {
            return [];
        }

        return array_is_list($middleware = is_array($middleware) ? $middleware : [$middleware])
            ? $middleware
            : array_values($middleware);
    }

    /**
     * Sends the request through the middleware and into $destination.
     *
     * @param callable(Request): Response $destination
     */
    public function handle(Request $request, callable $destination): Response {
        // Folded from the inside out, so the first entry ends up the outermost
        // layer. Each stage closes over the one after it.
        $next = static fn (Request $request): Response => $destination($request);

        foreach (array_reverse($this->middleware) as $entry) {
            $next = $this->wrap($entry, $next);
        }

        return $next($request);
    }

    /**
     * @param MiddlewareInterface|callable|string $entry
     * @param callable(Request): Response               $next
     */
    private function wrap(mixed $entry, callable $next): Closure {
        return function (Request $request) use ($entry, $next): Response {
            $middleware = $this->resolve($entry);

            $response = $middleware instanceof MiddlewareInterface
                ? $middleware->process($request, $next)
                : $middleware($request, $next);

            if (!$response instanceof Response) {
                // A middleware that forgets to return, or returns what $next
                // gave it without returning it, would otherwise produce a blank
                // page that nothing reports.
                throw new MiddlewareException(sprintf(
                    'Middleware %s must return a Response, got %s.',
                    $this->describe($entry),
                    get_debug_type($response),
                ));
            }

            return $response;
        };
    }

    /**
     * @param MiddlewareInterface|callable|string $entry
     * @return MiddlewareInterface|callable
     */
    private function resolve(mixed $entry): mixed {
        if ($entry instanceof MiddlewareInterface || (is_object($entry) && is_callable($entry))) {
            return $entry;
        }

        if (is_string($entry)) {
            // Said separately from the check below, because "there is no such
            // class" and "that class is not a middleware" are different
            // mistakes and the first one is usually a typo.
            if ($this->container?->has($entry) !== true && !class_exists($entry)) {
                throw new MiddlewareException(sprintf(
                    'Middleware %s is neither a registered container entry nor an existing class.',
                    $entry,
                ));
            }

            $resolved = $this->container?->has($entry) === true
                ? $this->container->get($entry)
                : new $entry();

            if ($resolved instanceof MiddlewareInterface || (is_object($resolved) && is_callable($resolved))) {
                return $resolved;
            }

            throw new MiddlewareException(sprintf(
                'Middleware %s must implement %s or be callable.',
                $entry,
                MiddlewareInterface::class,
            ));
        }

        if (is_callable($entry)) {
            return $entry;
        }

        throw new MiddlewareException(sprintf('Middleware entry of type %s is not usable.', get_debug_type($entry)));
    }

    private function describe(mixed $entry): string {
        return match (true) {
            is_string($entry) => $entry,
            is_object($entry) => get_class($entry),
            default => get_debug_type($entry),
        };
    }
}
