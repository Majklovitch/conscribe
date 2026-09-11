<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Http\Middleware\Cors;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSession;
use Tests\Support\RequestFactory;

/** Rejects anything without an Authorization header, as a login check would. */
final class RequiresAuth implements \App\Core\Http\Middleware\MiddlewareInterface {
    public function process(Request $request, callable $next): Response {
        return $request->getHeader('authorization') !== null
            ? $next($request)
            : Response::json(['error' => 'unauthenticated'], 401);
    }
}

#[CoversClass(Cors::class)]
final class CorsTest extends TestCase {
    private const APP = 'https://app.example.cz';

    private function router(Cors $cors): Router {
        $router = new Router(new FakeSession());
        $router->group(['middleware' => [$cors], 'csrf' => false], static function (Router $router): void {
            $router->get('api/orders', static fn (): Response => Response::json(['orders' => []]));
            $router->delete('api/orders/{id}', static fn (int $id): Response => Response::json(['deleted' => $id]));
        });

        return $router;
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, array $headers = []): Request {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return RequestFactory::make($method, $path, [], $server);
    }

    private function preflight(string $origin, string $method = 'DELETE', string $headers = 'Content-Type'): Request {
        return $this->request('OPTIONS', 'api/orders/7', [
            'Origin' => $origin,
            'Access-Control-Request-Method' => $method,
            'Access-Control-Request-Headers' => $headers,
        ]);
    }

    /** @return array<int, string> */
    private function varyOf(Response $response): array {
        return $response->getHeaders()['Vary'] ?? [];
    }

    // -----------------------------------------------------------------
    // Preflight
    // -----------------------------------------------------------------

    #[TestDox('an allowed preflight is answered directly with the CORS headers')]
    public function testAllowedPreflight(): void {
        $response = $this->router(new Cors([self::APP]))->handle($this->preflight(self::APP));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::APP, $response->getHeader('Access-Control-Allow-Origin'));
        self::assertStringContainsString('DELETE', (string) $response->getHeader('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Content-Type', (string) $response->getHeader('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeader('Access-Control-Max-Age'));
    }

    #[TestDox('a disallowed preflight gets no CORS headers, which is how the browser learns no')]
    public function testDisallowedPreflight(): void {
        $response = $this->router(new Cors([self::APP]))->handle($this->preflight('https://evil.example'));

        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
        self::assertNull($response->getHeader('Access-Control-Allow-Methods'));
    }

    #[TestDox('a plain OPTIONS without Access-Control-Request-Method is left to the router')]
    public function testPlainOptionsIsNotAPreflight(): void {
        $response = $this->router(new Cors([self::APP]))->handle(
            $this->request('OPTIONS', 'api/orders/7', ['Origin' => self::APP]),
        );

        // The router's own answer, listing what the path supports.
        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('DELETE', (string) $response->getHeader('Allow'));
    }

    // -----------------------------------------------------------------
    // Actual requests
    // -----------------------------------------------------------------

    #[TestDox('an allowed origin gets Allow-Origin on the real response')]
    public function testAllowedRequestIsDecorated(): void {
        $response = $this->router(new Cors([self::APP]))->handle(
            $this->request('GET', 'api/orders', ['Origin' => self::APP]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::APP, $response->getHeader('Access-Control-Allow-Origin'));
    }

    #[TestDox('a disallowed origin is served, but the browser is not told it may read it')]
    public function testDisallowedRequestIsNotDecorated(): void {
        // CORS is enforced by the browser, not the server: the request still
        // runs, the page script just never sees the answer.
        $response = $this->router(new Cors([self::APP]))->handle(
            $this->request('GET', 'api/orders', ['Origin' => 'https://evil.example']),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    #[TestDox('a request with no Origin is left alone')]
    public function testNoOriginIsUntouched(): void {
        $response = $this->router(new Cors([self::APP]))->handle($this->request('GET', 'api/orders'));

        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame([], $this->varyOf($response));
    }

    #[TestDox('an arbitrary Origin is never reflected back')]
    public function testOriginIsNotReflected(): void {
        // Echoing whatever arrives is `*` in disguise.
        $response = $this->router(new Cors([self::APP]))->handle(
            $this->request('GET', 'api/orders', ['Origin' => 'https://app.example.cz.evil.example']),
        );

        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    #[TestDox('origins compare case-insensitively and without a trailing slash')]
    public function testOriginNormalization(): void {
        $response = $this->router(new Cors(['https://APP.example.cz/']))->handle(
            $this->request('GET', 'api/orders', ['Origin' => self::APP]),
        );

        self::assertSame(self::APP, $response->getHeader('Access-Control-Allow-Origin'));
    }

    // -----------------------------------------------------------------
    // Vary, wildcard, credentials
    // -----------------------------------------------------------------

    #[TestDox('responses from a whitelist vary by Origin, allowed or not')]
    public function testVaryOrigin(): void {
        // Without it a shared cache can hand one origin's Allow-Origin to
        // another site's page.
        $router = $this->router(new Cors([self::APP]));

        self::assertContains('Origin', $this->varyOf($router->handle($this->request('GET', 'api/orders', ['Origin' => self::APP]))));
        self::assertContains('Origin', $this->varyOf($router->handle($this->request('GET', 'api/orders', ['Origin' => 'https://x.example']))));
    }

    #[TestDox('an existing Vary is kept, and Origin is not added twice')]
    public function testVaryIsAppended(): void {
        $cors = new Cors([self::APP]);
        $request = $this->request('GET', 'x', ['Origin' => self::APP]);

        $once = $cors->process($request, static fn (): Response => (new Response('ok'))->setHeader('Vary', 'Accept-Encoding'));
        self::assertSame(['Accept-Encoding', 'Origin'], $this->varyOf($once));

        $already = $cors->process($request, static fn (): Response => (new Response('ok'))->setHeader('Vary', 'Origin'));
        self::assertSame(['Origin'], $this->varyOf($already));
    }

    #[TestDox('a wildcard answers * and needs no Vary')]
    public function testWildcard(): void {
        $response = $this->router(new Cors(['*']))->handle(
            $this->request('GET', 'api/orders', ['Origin' => 'https://anyone.example']),
        );

        self::assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame([], $this->varyOf($response));
    }

    #[TestDox('with credentials the concrete origin is sent, never *')]
    public function testCredentials(): void {
        $response = $this->router(new Cors([self::APP], credentials: true))->handle(
            $this->request('GET', 'api/orders', ['Origin' => self::APP]),
        );

        self::assertSame(self::APP, $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeader('Access-Control-Allow-Credentials'));
    }

    #[TestDox('* with credentials is refused at construction')]
    public function testWildcardWithCredentialsIsRefused(): void {
        // Browsers reject the combination, and the failure would only ever
        // show up as a console error on someone else's page.
        $this->expectException(InvalidArgumentException::class);

        new Cors(['*'], credentials: true);
    }

    #[TestDox('wildcard headers with credentials are refused too')]
    public function testWildcardHeadersWithCredentialsAreRefused(): void {
        $this->expectException(InvalidArgumentException::class);

        new Cors([self::APP], headers: ['*'], credentials: true);
    }

    public function testAnEmptyOriginListIsRefused(): void {
        $this->expectException(InvalidArgumentException::class);

        new Cors([]);
    }

    #[TestDox('wildcard headers echo what the preflight asked for')]
    public function testWildcardHeadersEchoTheRequest(): void {
        $response = $this->router(new Cors([self::APP], headers: ['*']))->handle(
            $this->preflight(self::APP, 'DELETE', 'X-Custom, Content-Type'),
        );

        self::assertSame('X-Custom, Content-Type', $response->getHeader('Access-Control-Allow-Headers'));
    }

    // -----------------------------------------------------------------
    // Interaction with the router
    // -----------------------------------------------------------------

    #[TestDox('Cors before authentication lets the preflight through')]
    public function testCorsBeforeAuthLetsThePreflightThrough(): void {
        $router = new Router(new FakeSession());
        $router->group(['middleware' => [new Cors([self::APP]), RequiresAuth::class]], static function (Router $router): void {
            $router->get('api/me', static fn (): Response => Response::json([]));
        });

        $response = $router->handle($this->request('OPTIONS', 'api/me', [
            'Origin' => self::APP,
            'Access-Control-Request-Method' => 'GET',
        ]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::APP, $response->getHeader('Access-Control-Allow-Origin'));
    }

    #[TestDox('anything before Cors in a route is refused when the route is declared')]
    public function testMiddlewareBeforeCorsIsRefused(): void {
        // A preflight carries no credentials, so authentication ahead of Cors
        // answers 401 and every cross-origin call fails with an error that
        // points nowhere near the cause.
        $router = new Router(new FakeSession());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Put Cors first');

        $router->group(['middleware' => [RequiresAuth::class, new Cors([self::APP])]], static function (Router $router): void {
            $router->get('api/me', static fn (): Response => Response::json([]));
        });
    }

    #[TestDox('the check also sees Cors given by class name')]
    public function testCorsByClassNameIsChecked(): void {
        $this->expectException(InvalidArgumentException::class);

        (new Router(new FakeSession()))->get('x', static fn (): string => '', [
            'middleware' => [RequiresAuth::class, Cors::class],
        ]);
    }

    #[TestDox('a 405 carries the CORS headers, so the client sees the real status')]
    public function testMethodNotAllowedIsDecorated(): void {
        $response = $this->router(new Cors([self::APP]))->handle(
            $this->request('PATCH', 'api/orders', ['Origin' => self::APP]),
        );

        self::assertSame(405, $response->getStatusCode());
        self::assertSame(self::APP, $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function testExposedHeaders(): void {
        $response = $this->router(new Cors([self::APP], exposed: ['X-Total-Count']))->handle(
            $this->request('GET', 'api/orders', ['Origin' => self::APP]),
        );

        self::assertSame('X-Total-Count', $response->getHeader('Access-Control-Expose-Headers'));
    }
}
