<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Http\Response;
use App\Core\Http\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSession;
use Tests\Support\RequestFactory;

/**
 * Router: HTTP methods, CSRF and the responses derived from the route table.
 *
 * Everything here goes through Router::handle(), which returns the Response
 * instead of sending it - no output buffering, no headers, no session.
 */
#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    /**
     * A router with one resource registered under every verb, one route that
     * opted out of CSRF, and one POST-only endpoint. Enough for the method,
     * CSRF and Allow-header cases alike.
     */
    private function router(): Router
    {
        $router = new Router(new FakeSession());

        $router->get('api/order/{id}', static fn (int $id): Response => Response::json(['method' => 'GET', 'id' => $id]));
        $router->put('api/order/{id}', static fn (int $id): Response => Response::json(['method' => 'PUT', 'id' => $id]));
        $router->patch('api/order/{id}', static fn (int $id): Response => Response::json(['method' => 'PATCH', 'id' => $id]));
        $router->delete('api/order/{id}', static fn (int $id): Response => Response::json(['method' => 'DELETE', 'id' => $id]));

        $router->map(['PUT', 'PATCH'], 'api/thing', static fn (): Response => Response::json(['mapped' => true]), ['csrf' => false]);
        $router->post('api/webhook', static fn (): Response => Response::json(['hook' => true]), ['csrf' => false]);

        return $router;
    }

    private function decode(Response $response): array
    {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    // -----------------------------------------------------------------
    // Methods
    // -----------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function stateChangingMethods(): array
    {
        return ['PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    #[DataProvider('stateChangingMethods')]
    #[TestDox('$method reaches its own handler')]
    public function testStateChangingMethodsAreRouted(string $method): void
    {
        $response = $this->router()->handle(RequestFactory::withToken($method, 'api/order/7'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['method' => $method, 'id' => 7], $this->decode($response));
    }

    public function testGetIsUnaffected(): void
    {
        $response = $this->router()->handle(RequestFactory::make('GET', 'api/order/7'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['method' => 'GET', 'id' => 7], $this->decode($response));
    }

    #[TestDox('HEAD is answered from the GET table')]
    public function testHeadUsesTheGetTable(): void
    {
        $response = $this->router()->handle(RequestFactory::make('HEAD', 'api/order/7'));

        self::assertSame(200, $response->getStatusCode());
        // handle() builds the full response; suppressing the body is send()'s
        // job, so the content is still the GET content here.
        self::assertSame(['method' => 'GET', 'id' => 7], $this->decode($response));
    }

    public function testMapRegistersEveryMethodGiven(): void
    {
        foreach (['PUT', 'PATCH'] as $method) {
            $response = $this->router()->handle(RequestFactory::make($method, 'api/thing'));

            self::assertSame(200, $response->getStatusCode(), $method . ' should be mapped');
            self::assertSame(['mapped' => true], $this->decode($response));
        }
    }

    public function testUnsupportedMethodIsRejectedAtRegistration(): void
    {
        $router = new Router(new FakeSession());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("uses unsupported method 'TRACE'");

        $router->map('TRACE', 'anything', static fn (): string => '');
    }

    public function testAnUnroutableParameterTypeIsNotAMatch(): void
    {
        // {id} is typed int in the handler; 'abc' cannot fill it, so the route
        // does not actually match and the answer is 404, not a TypeError.
        $response = $this->router()->handle(RequestFactory::withToken('PUT', 'api/order/abc'));

        self::assertSame(404, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------

    #[DataProvider('stateChangingMethods')]
    #[TestDox('$method without a CSRF token is refused with 419')]
    public function testStateChangingMethodsRequireACsrfToken(string $method): void
    {
        $response = $this->router()->handle(RequestFactory::make($method, 'api/order/7'));

        self::assertSame(419, $response->getStatusCode());
    }

    #[TestDox('the token is also accepted from the X-CSRF-Token header')]
    public function testCsrfTokenMayComeFromTheHeader(): void
    {
        $request = RequestFactory::make('DELETE', 'api/order/7', [], [
            'HTTP_X_CSRF_TOKEN' => FakeSession::VALID_TOKEN,
        ]);

        self::assertSame(200, $this->router()->handle($request)->getStatusCode());
    }

    #[TestDox('a route that opted out of CSRF is not checked')]
    public function testCsrfCanBeOptedOutOf(): void
    {
        self::assertSame(200, $this->router()->handle(RequestFactory::make('PUT', 'api/thing'))->getStatusCode());
        self::assertSame(200, $this->router()->handle(RequestFactory::make('POST', 'api/webhook'))->getStatusCode());
    }

    #[TestDox('a group opt-out reaches the routes inside it')]
    public function testCsrfOptOutAppliesToAWholeGroup(): void
    {
        $router = new Router(new FakeSession());
        $router->group(['csrf' => false], static function (Router $router): void {
            $router->put('api/grouped', static fn (): Response => Response::json(['ok' => true]));
        });

        self::assertSame(200, $router->handle(RequestFactory::make('PUT', 'api/grouped'))->getStatusCode());
    }

    #[TestDox('GET is never CSRF-checked')]
    public function testSafeMethodsSkipCsrf(): void
    {
        self::assertSame(200, $this->router()->handle(RequestFactory::make('GET', 'api/order/7'))->getStatusCode());
    }

    // -----------------------------------------------------------------
    // OPTIONS and Allow
    // -----------------------------------------------------------------

    #[TestDox('OPTIONS answers 204 and lists every method the path supports')]
    public function testOptionsListsTheRegisteredMethods(): void
    {
        $response = $this->router()->handle(RequestFactory::make('OPTIONS', 'api/order/7'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(
            ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            explode(', ', (string) $response->getHeader('Allow'))
        );
    }

    #[TestDox('OPTIONS on a POST-only path does not invent methods')]
    public function testOptionsReportsOnlyWhatIsRegistered(): void
    {
        $response = $this->router()->handle(RequestFactory::make('OPTIONS', 'api/webhook'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('POST, OPTIONS', $response->getHeader('Allow'));
    }

    #[TestDox('OPTIONS on an unknown path is 404, not an empty 204')]
    public function testOptionsOnAnUnknownPathIs404(): void
    {
        $response = $this->router()->handle(RequestFactory::make('OPTIONS', 'api/nope'));

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($response->getHeader('Allow'));
    }

    #[TestDox('a known path under the wrong method is 405 with an Allow header')]
    public function testWrongMethodIs405(): void
    {
        $response = $this->router()->handle(RequestFactory::make('DELETE', 'api/thing'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('PUT, PATCH, OPTIONS', $response->getHeader('Allow'));
    }

    #[TestDox('the 405 Allow header excludes the method that was tried')]
    public function testAllowExcludesTheAttemptedMethod(): void
    {
        $response = $this->router()->handle(RequestFactory::make('GET', 'api/webhook'));

        self::assertSame(405, $response->getStatusCode());
        self::assertStringNotContainsString('GET', (string) $response->getHeader('Allow'));
    }

    #[TestDox('an unknown path is 404 with no Allow header')]
    public function testUnknownPathIs404(): void
    {
        $response = $this->router()->handle(RequestFactory::make('GET', 'no/such/page'));

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($response->getHeader('Allow'));
    }
}
