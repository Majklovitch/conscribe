<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Container\Container;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSession;
use Tests\Support\RequestFactory;

/** A service the container has to supply; nothing else can produce it. */
final class Clock {
    public function __construct(public string $now = '2026-09-10') {
    }
}

final class ClockController extends Controller {
    // The point of the container: a controller that cannot be built with
    // `new $class()` because it declares what it needs.
    public function __construct(private Clock $clock) {
    }

    public function show(): Response {
        return Response::json(['now' => $this->clock->now]);
    }

    public function withParam(int $id, Clock $clock): Response {
        return Response::json(['id' => $id, 'now' => $clock->now]);
    }

    public function shadowed(Request $request, Clock $clock): Response {
        return Response::json(['path' => $request->getPath(), 'now' => $clock->now]);
    }
}

/** Takes the request in its constructor, as a service would. */
final class PathController extends Controller {
    public function __construct(private Request $seen) {
    }

    public function show(): Response {
        return Response::json(['path' => $this->seen->getPath()]);
    }
}

final class PlainController extends Controller {
    public function show(): Response {
        return Response::json(['plain' => true]);
    }
}

/**
 * The router and the container together: constructing controllers and filling
 * action parameters the URL cannot.
 */
#[CoversClass(Router::class)]
final class RouterContainerTest extends TestCase {
    private function router(?Container $container): Router {
        return new Router(new FakeSession(), $container);
    }

    private function decode(Response $response): array {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[TestDox('a controller with constructor dependencies is built by the container')]
    public function testControllerDependenciesAreAutowired(): void {
        $container = new Container();
        $container->instance(Clock::class, new Clock('2020-01-01'));

        $router = $this->router($container);
        $router->get('now', [ClockController::class, 'show']);

        $response = $router->handle(RequestFactory::make('GET', 'now'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['now' => '2020-01-01'], $this->decode($response));
    }

    #[TestDox('an action parameter the URL cannot fill comes from the container')]
    public function testActionParametersAreResolvedFromTheContainer(): void {
        $container = new Container();
        $container->instance(Clock::class, new Clock('2021-02-02'));

        $router = $this->router($container);
        $router->get('order/{id}', [ClockController::class, 'withParam']);

        $response = $router->handle(RequestFactory::make('GET', 'order/42'));

        self::assertSame(['id' => 42, 'now' => '2021-02-02'], $this->decode($response));
    }

    #[TestDox('Request and Session still win over the container')]
    public function testRequestInjectionIsUnaffected(): void {
        $container = new Container();
        $container->instance(Clock::class, new Clock('2022-03-03'));
        // A Request in the container must not displace the one being handled.
        $container->instance(Request::class, RequestFactory::make('GET', 'some/other/path'));

        $router = $this->router($container);
        $router->get('here', [ClockController::class, 'shadowed']);

        $response = $router->handle(RequestFactory::make('GET', 'here'));

        self::assertSame('here', $this->decode($response)['path'], 'the handled request, not the bound one');
    }

    #[TestDox('a Request injected into a constructor is the one being dispatched, after rewriting')]
    public function testConstructorRequestIsTheRewrittenOne(): void {
        $container = new Container();
        // The request as Bootstrap registers it, before anything rewrote it.
        $container->instance(Request::class, RequestFactory::make('GET', 'en/page'));

        $router = $this->router($container);
        $router->middleware(static fn (Request $request, callable $next): Response => $next($request->withPath('page')));
        $router->get('page', [PathController::class, 'show']);

        $response = $router->handle(RequestFactory::make('GET', 'en/page'));

        self::assertSame(['path' => 'page'], $this->decode($response));
    }

    #[TestDox('the router still works without a container')]
    public function testAControllerWithNoDependenciesNeedsNoContainer(): void {
        $router = $this->router(null);
        $router->get('plain', [PlainController::class, 'show']);

        $response = $router->handle(RequestFactory::make('GET', 'plain'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['plain' => true], $this->decode($response));
    }

    #[TestDox('a controller the container cannot build is a 500, not a TypeError')]
    public function testUnbuildableControllerBecomesAnError(): void {
        $router = $this->router(new Container());
        // Clock has a default, so it builds; make it genuinely unbuildable by
        // asking for the controller without a container at all.
        $router->get('now', [ClockController::class, 'show']);

        $plain = $this->router(null);
        $plain->get('now', [ClockController::class, 'show']);

        // With a container the dependency is autowired from its own default.
        self::assertSame(200, $router->handle(RequestFactory::make('GET', 'now'))->getStatusCode());

        // Without one, `new ClockController()` would be an ArgumentCountError.
        // The router must turn that into a logged 500 rather than a fatal.
        $response = @$plain->handle(RequestFactory::make('GET', 'now'));
        self::assertSame(500, $response->getStatusCode());
    }
}
