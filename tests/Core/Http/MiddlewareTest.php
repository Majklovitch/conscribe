<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Container\Container;
use App\Core\Http\Middleware\MiddlewareException;
use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Middleware\Pipeline;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeSession;
use Tests\Support\RequestFactory;

/** Appends its label to a shared log, so ordering can be asserted. */
final class Tagger implements MiddlewareInterface {
    /** @var string[] */
    public static array $log = [];

    public function __construct(private string $label) {
    }

    public function process(Request $request, callable $next): Response {
        self::$log[] = 'in:' . $this->label;
        $response = $next($request);
        self::$log[] = 'out:' . $this->label;

        return $response->setHeader('X-Tag-' . $this->label, 'yes');
    }
}

/** Answers by itself and never calls $next. */
final class ShortCircuit implements MiddlewareInterface {
    public function process(Request $request, callable $next): Response {
        return Response::json(['blocked' => true], 403);
    }
}

/** Rewrites the request before the router sees it. */
final class Rewrite implements MiddlewareInterface {
    public function process(Request $request, callable $next): Response {
        return $next($request->withPath('rewritten'));
    }
}

/** Declares a dependency, so it can only come from the container. */
final class NeedsService implements MiddlewareInterface {
    public function __construct(private Clock $clock) {
    }

    public function process(Request $request, callable $next): Response {
        return $next($request)->setHeader('X-Now', $this->clock->now);
    }
}

final class NotMiddleware {
}

/** Throws the way application code would, rather than misusing the pipeline. */
final class Explodes implements MiddlewareInterface {
    public function process(Request $request, callable $next): Response {
        throw new \DomainException('application failure');
    }
}

#[CoversClass(Pipeline::class)]
#[CoversClass(Router::class)]
final class MiddlewareTest extends TestCase {
    protected function setUp(): void {
        Tagger::$log = [];
    }

    private function router(?Container $container = null): Router {
        $router = new Router(new FakeSession(), $container);
        $router->get('page', static fn (): Response => Response::json(['page' => true]));

        return $router;
    }

    private function decode(Response $response): array {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    // -----------------------------------------------------------------
    // Pipeline
    // -----------------------------------------------------------------

    #[TestDox('the first entry is the outermost layer')]
    public function testOrderIsOutermostFirst(): void {
        $pipeline = new Pipeline([new Tagger('a'), new Tagger('b')]);

        $pipeline->handle(
            RequestFactory::make('GET', 'x'),
            static function (): Response {
                Tagger::$log[] = 'handler';

                return Response::json([]);
            },
        );

        self::assertSame(['in:a', 'in:b', 'handler', 'out:b', 'out:a'], Tagger::$log);
    }

    #[TestDox('an empty pipeline just calls the destination')]
    public function testEmptyPipeline(): void {
        $response = (new Pipeline())->handle(
            RequestFactory::make('GET', 'x'),
            static fn (): Response => Response::json(['reached' => true]),
        );

        self::assertSame(['reached' => true], $this->decode($response));
    }

    #[TestDox('a plain callable works as middleware')]
    public function testCallableMiddleware(): void {
        $pipeline = new Pipeline([
            static fn (Request $request, callable $next): Response => $next($request)->setHeader('X-Plain', 'yes'),
        ]);

        $response = $pipeline->handle(RequestFactory::make('GET', 'x'), static fn (): Response => Response::json([]));

        self::assertSame('yes', $response->getHeader('X-Plain'));
    }

    #[TestDox('a class name is resolved through the container')]
    public function testClassNameIsResolvedFromTheContainer(): void {
        $container = new Container();
        $container->instance(Clock::class, new Clock('2026-01-01'));

        $pipeline = new Pipeline([NeedsService::class], $container);
        $response = $pipeline->handle(RequestFactory::make('GET', 'x'), static fn (): Response => Response::json([]));

        self::assertSame('2026-01-01', $response->getHeader('X-Now'));
    }

    #[TestDox('a class that is not middleware is refused by name')]
    public function testNonMiddlewareClassIsRefused(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(NotMiddleware::class);

        (new Pipeline([NotMiddleware::class]))
            ->handle(RequestFactory::make('GET', 'x'), static fn (): Response => Response::json([]));
    }

    #[TestDox('callable middleware that returns nothing is reported, not a blank page')]
    public function testMiddlewareMustReturnAResponse(): void {
        // Only reachable for a callable: an implementation of the interface
        // declares `: Response`, so PHP rejects it before the pipeline can.
        // An untyped closure has no such guard, which is what this covers.
        $forgetsToReturn = static function (Request $request, callable $next) {
            $next($request);
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a Response');

        (new Pipeline([$forgetsToReturn]))
            ->handle(RequestFactory::make('GET', 'x'), static fn (): Response => Response::json([]));
    }

    // -----------------------------------------------------------------
    // Router integration
    // -----------------------------------------------------------------

    public function testGlobalMiddlewareWrapsTheRoute(): void {
        $router = $this->router();
        $router->middleware(new Tagger('global'));

        $response = $router->handle(RequestFactory::make('GET', 'page'));

        self::assertSame('yes', $response->getHeader('X-Tag-global'));
        self::assertSame(['in:global', 'out:global'], Tagger::$log);
    }

    #[TestDox('global middleware also sees requests that match no route')]
    public function testGlobalMiddlewareCoversNotFound(): void {
        $router = $this->router();
        $router->middleware(new Tagger('global'));

        $response = $router->handle(RequestFactory::make('GET', 'no/such/page'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['in:global', 'out:global'], Tagger::$log, 'a rate limiter that only saw hits would be worth little');
    }

    #[TestDox('middleware may answer without reaching the route')]
    public function testMiddlewareCanShortCircuit(): void {
        $router = $this->router();
        $router->middleware(new ShortCircuit());

        $response = $router->handle(RequestFactory::make('GET', 'page'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['blocked' => true], $this->decode($response));
    }

    #[TestDox('a rewritten request is what the router matches against')]
    public function testMiddlewareCanRewriteTheRequest(): void {
        $router = new Router(new FakeSession());
        $router->get('rewritten', static fn (): Response => Response::json(['matched' => true]));
        $router->middleware(new Rewrite());

        $response = $router->handle(RequestFactory::make('GET', 'original'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['matched' => true], $this->decode($response));
    }

    public function testPerRouteMiddlewareRunsOnlyForThatRoute(): void {
        $router = $this->router();
        $router->get('guarded', static fn (): Response => Response::json([]), ['middleware' => [new Tagger('route')]]);

        $router->handle(RequestFactory::make('GET', 'page'));
        self::assertSame([], Tagger::$log, 'the plain route is untouched');

        $router->handle(RequestFactory::make('GET', 'guarded'));
        self::assertSame(['in:route', 'out:route'], Tagger::$log);
    }

    #[TestDox('group middleware applies to the routes inside it')]
    public function testGroupMiddleware(): void {
        $router = new Router(new FakeSession());
        $router->group(['middleware' => [new Tagger('group')]], static function (Router $router): void {
            $router->get('inside', static fn (): Response => Response::json([]));
        });
        $router->get('outside', static fn (): Response => Response::json([]));

        $router->handle(RequestFactory::make('GET', 'outside'));
        self::assertSame([], Tagger::$log);

        $router->handle(RequestFactory::make('GET', 'inside'));
        self::assertSame(['in:group', 'out:group'], Tagger::$log);
    }

    #[TestDox('a route appends to its group rather than replacing it')]
    public function testRouteMiddlewareAppendsToTheGroup(): void {
        $router = new Router(new FakeSession());
        $router->group(['middleware' => [new Tagger('g1'), new Tagger('g2')]], static function (Router $router): void {
            $router->get('inside', static fn (): Response => Response::json([]), [
                'middleware' => [new Tagger('r1')],
            ]);
        });

        $router->handle(RequestFactory::make('GET', 'inside'));

        // Merging this the way the other options merge would union by position
        // and silently drop g2.
        self::assertSame(
            ['in:g1', 'in:g2', 'in:r1', 'out:r1', 'out:g2', 'out:g1'],
            Tagger::$log,
        );
    }

    #[TestDox('a nested group appends to its parent rather than replacing it')]
    public function testNestedGroupMiddlewareAppendsToTheParent(): void {
        $router = new Router(new FakeSession());
        $router->group(['middleware' => [new Tagger('outer')]], static function (Router $router): void {
            $router->group(['middleware' => [new Tagger('inner')]], static function (Router $router): void {
                $router->get('admin/users', static fn (): Response => Response::json([]));
            });

            $router->get('account', static fn (): Response => Response::json([]));
        });

        $router->handle(RequestFactory::make('GET', 'admin/users'));

        // array_merge() on the group defaults would overwrite 'middleware'
        // wholesale, so the outer group's authentication would never run and
        // the page behind it would be reachable by anyone.
        self::assertSame(
            ['in:outer', 'in:inner', 'out:inner', 'out:outer'],
            Tagger::$log,
        );

        Tagger::$log = [];
        $router->handle(RequestFactory::make('GET', 'account'));

        // The nested group's entry is confined to it; a sibling route
        // registered after it still sees only the parent's.
        self::assertSame(['in:outer', 'out:outer'], Tagger::$log);
    }

    #[TestDox('global middleware runs outside the route middleware')]
    public function testGlobalRunsOutsideRoute(): void {
        $router = new Router(new FakeSession());
        $router->middleware(new Tagger('global'));
        $router->get('page', static fn (): Response => Response::json([]), ['middleware' => [new Tagger('route')]]);

        $router->handle(RequestFactory::make('GET', 'page'));

        self::assertSame(['in:global', 'in:route', 'out:route', 'out:global'], Tagger::$log);
    }

    // -----------------------------------------------------------------
    // Misuse
    // -----------------------------------------------------------------

    #[TestDox('a single middleware without the array is wrapped, not cast')]
    public function testSingleMiddlewareNeedNotBeWrappedInAnArray(): void {
        // (array) on an object yields its properties, so the shorthand would
        // otherwise try to use a private property value as a class name - and
        // say so in the error message.
        $router = new Router(new FakeSession());
        $router->get('page', static fn (): Response => Response::json(['ok' => true]), [
            'middleware' => new Tagger('solo'),
        ]);

        $response = $router->handle(RequestFactory::make('GET', 'page'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeader('X-Tag-solo'));
    }

    #[TestDox('a misspelled middleware class says so, rather than blaming the interface')]
    public function testMissingClassIsReportedAsMissing(): void {
        $this->expectException(MiddlewareException::class);
        $this->expectExceptionMessage('neither a registered container entry nor an existing class');

        (new Pipeline(['No\Such\Middleware']))
            ->handle(RequestFactory::make('GET', 'x'), static fn (): Response => Response::json([]));
    }

    #[TestDox('a broken middleware is a logged 500, not an escaped exception')]
    public function testPipelineMisuseBecomesA500(): void {
        $router = new Router(new FakeSession());
        $router->middleware(static fn (Request $request, callable $next) => null);
        $router->get('page', static fn (): Response => Response::json([]));

        $response = $router->handle(RequestFactory::make('GET', 'page'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('must return a Response', $response->getContent());
    }

    #[TestDox('an application exception inside middleware still reaches the error handler')]
    public function testApplicationExceptionsAreNotSwallowed(): void {
        // The 500 above must not become a blanket catch: an exception from
        // application code has to keep travelling, or the debug bar never sees
        // it and the log gets a useless message instead of a stack trace.
        $router = new Router(new FakeSession());
        $router->middleware(new Explodes());
        $router->get('page', static fn (): Response => Response::json([]));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('application failure');

        $router->handle(RequestFactory::make('GET', 'page'));
    }

    #[TestDox('route middleware runs before the CSRF check can reject')]
    public function testRouteMiddlewareRunsBeforeCsrf(): void {
        $router = new Router(new FakeSession());
        $router->post('form', static fn (): Response => Response::json([]), ['middleware' => [new Tagger('route')]]);

        $response = $router->handle(RequestFactory::make('POST', 'form'));

        self::assertSame(419, $response->getStatusCode());
        self::assertSame(['in:route', 'out:route'], Tagger::$log, 'the middleware still ran');
    }
}
