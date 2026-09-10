<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;
use Psr\Container\ContainerInterface;
use RuntimeException;

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
 *     its own dependencies) or, without one, constructed with `new`;
 *   - a callable with the same signature as process().
 *
 * Class names are resolved when the pipeline runs, not when it is declared:
 * a route file naming a middleware must not force its dependencies - a
 * database connection, say - to exist for every request that never reaches
 * that route.
 */
final class Pipeline {
    /** @var array<int, MiddlewareInterface|callable|class-string> */
    private array $middleware;

    private ?ContainerInterface $container;

    /**
     * @param array<int, MiddlewareInterface|callable|class-string> $middleware
     */
    public function __construct(array $middleware = [], ?ContainerInterface $container = null) {
        $this->middleware = array_values($middleware);
        $this->container = $container;
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
     * @param MiddlewareInterface|callable|class-string $entry
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
                throw new RuntimeException(sprintf(
                    'Middleware %s must return a Response, got %s.',
                    $this->describe($entry),
                    get_debug_type($response),
                ));
            }

            return $response;
        };
    }

    /**
     * @param MiddlewareInterface|callable|class-string $entry
     * @return MiddlewareInterface|callable
     */
    private function resolve(mixed $entry): mixed {
        if ($entry instanceof MiddlewareInterface || (is_object($entry) && is_callable($entry))) {
            return $entry;
        }

        if (is_string($entry)) {
            $resolved = $this->container?->has($entry) === true
                ? $this->container->get($entry)
                : (class_exists($entry) ? new $entry() : null);

            if ($resolved instanceof MiddlewareInterface || (is_object($resolved) && is_callable($resolved))) {
                return $resolved;
            }

            throw new RuntimeException(sprintf(
                'Middleware %s must implement %s or be callable.',
                $entry,
                MiddlewareInterface::class,
            ));
        }

        if (is_callable($entry)) {
            return $entry;
        }

        throw new RuntimeException(sprintf('Middleware entry of type %s is not usable.', get_debug_type($entry)));
    }

    private function describe(mixed $entry): string {
        return match (true) {
            is_string($entry) => $entry,
            is_object($entry) => get_class($entry),
            default => get_debug_type($entry),
        };
    }
}
