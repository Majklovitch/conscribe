<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Config;
use App\Core\Http\ErrorPage;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FakeSession;
use Tests\Support\RequestFactory;

/**
 * An ErrorPage with no templates, so the HTML path ends in the plain-text
 * fallback instead of rendering the application's views - which need their own
 * data and are not what these tests are about.
 */
final class TemplatelessErrorPage extends ErrorPage {
    protected function resolveView(int $code): ?string {
        return null;
    }
}

#[CoversClass(ErrorPage::class)]
#[CoversClass(Request::class)]
final class ErrorPageTest extends TestCase {
    private ErrorPage $page;

    protected function setUp(): void {
        $this->page = new TemplatelessErrorPage(logger: new NullLogger());
    }

    protected function tearDown(): void {
        Config::set([]);
    }

    /** @param array<string, string> $server */
    private function request(array $server = [], string $path = 'api/orders/7'): Request {
        return RequestFactory::make('GET', $path, [], $server);
    }

    /** @return array<string, mixed> */
    private function problemOf(Response $response): array {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[TestDox('a JSON client gets an RFC 9457 problem document')]
    public function testJsonClientGetsAProblemDocument(): void {
        $response = ($this->page)(404, 'Page not found.', $this->request(['HTTP_ACCEPT' => 'application/json']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeader('Content-Type'));
        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'Page not found.',
            'instance' => '/api/orders/7',
        ], $this->problemOf($response));
    }

    #[TestDox('asking for problem+json itself counts as asking for JSON')]
    public function testProblemJsonAcceptIsRecognised(): void {
        $response = ($this->page)(404, 'x', $this->request(['HTTP_ACCEPT' => 'application/problem+json']));

        self::assertSame('application/problem+json', $response->getHeader('Content-Type'));
    }

    #[TestDox('an XHR and a JSON body are answered with a problem document too')]
    public function testAjaxAndJsonBodyGetJson(): void {
        $ajax = ($this->page)(404, 'x', $this->request(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        $body = ($this->page)(404, 'x', $this->request(['CONTENT_TYPE' => 'application/json']));

        self::assertSame('application/problem+json', $ajax->getHeader('Content-Type'));
        self::assertSame('application/problem+json', $body->getHeader('Content-Type'));
    }

    #[TestDox('a browser still gets the page, even when its Accept mentions JSON')]
    public function testBrowserGetsHtml(): void {
        $response = ($this->page)(404, 'Page not found.', $this->request([
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
        ]));

        self::assertNotSame('application/problem+json', $response->getHeader('Content-Type'));
        self::assertStringContainsString('Page not found.', $response->getContent());
    }

    #[TestDox('5xx detail is masked in production, in JSON exactly as on the page')]
    public function testServerErrorDetailIsMasked(): void {
        Config::set(['conscribe' => ['environment' => 'production']]);

        $response = ($this->page)(500, 'Controller App\\Secret not found.', $this->request(['HTTP_ACCEPT' => 'application/json']));
        $problem = $this->problemOf($response);

        // The message names internals; a JSON body is no less public than a page.
        self::assertSame('Internal Server Error', $problem['title']);
        self::assertStringNotContainsString('Secret', $problem['detail']);
    }

    #[TestDox('in development the real 5xx detail is shown')]
    public function testServerErrorDetailIsShownInDevelopment(): void {
        Config::set(['conscribe' => ['environment' => 'development']]);

        $problem = $this->problemOf(($this->page)(500, 'Controller App\\Secret not found.', $this->request(['HTTP_ACCEPT' => 'application/json'])));

        self::assertStringContainsString('Secret', $problem['detail']);
    }

    #[TestDox('an unlisted code still gets a sensible title')]
    public function testUnknownCodeTitle(): void {
        $problem = $this->problemOf(($this->page)(418, 'x', $this->request(['HTTP_ACCEPT' => 'application/json'])));

        self::assertSame('Client Error', $problem['title']);
        self::assertSame(418, $problem['status']);
    }

    #[TestDox('through the router, a 405 keeps its Allow header in the JSON answer')]
    public function testRouterErrorsUseIt(): void {
        $router = new Router(new FakeSession());
        $router->setErrorHandler($this->page);
        $router->get('api/orders', static fn (): Response => Response::json([]));

        $response = $router->handle(RequestFactory::make('DELETE', 'api/orders', [], ['HTTP_ACCEPT' => 'application/json']));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeader('Content-Type'));
        self::assertSame('Method Not Allowed', $this->problemOf($response)['title']);
        self::assertStringContainsString('GET', (string) $response->getHeader('Allow'));
    }
}
