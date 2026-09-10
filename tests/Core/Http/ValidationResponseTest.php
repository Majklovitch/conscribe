<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Validation\ErrorBag;
use App\Core\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSession;
use Tests\Support\RequestFactory;

/**
 * What the router does with a ValidationException an action threw.
 */
#[CoversClass(Router::class)]
final class ValidationResponseTest extends TestCase {
    private function router(): Router {
        $router = new Router(new FakeSession());
        $router->post('contact', static function (): Response {
            $errors = new ErrorBag();
            $errors->add('email', 'The email must be a valid email address.');

            throw new ValidationException($errors);
        }, ['csrf' => false]);

        return $router;
    }

    #[TestDox('a browser form is redirected back to where it came from')]
    public function testBrowserGetsARedirect(): void {
        $request = RequestFactory::make('POST', 'contact', ['email' => 'nope'], [
            'HTTP_REFERER' => '/kontakt',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
        ]);

        $response = $this->router()->handle($request);

        // 303, not 302: the browser must follow it with GET whatever the
        // original method was.
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/kontakt', $response->getHeader('Location'));
    }

    #[TestDox('a forged Referer degrades to the home page instead of crashing')]
    public function testAnExternalRefererIsNotFollowed(): void {
        // sanitizeRedirectUrl() refuses off-site URLs by throwing, so an
        // attacker-supplied header would otherwise turn every invalid form into
        // a 500 - or, without the check, into an open redirect.
        $request = RequestFactory::make('POST', 'contact', [], [
            'HTTP_REFERER' => 'https://evil.example/phish',
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $response = $this->router()->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeader('Location'));
    }

    #[TestDox('with no Referer at all it goes to the home page')]
    public function testNoRefererGoesHome(): void {
        $response = $this->router()->handle(RequestFactory::make('POST', 'contact'));

        self::assertSame('/', $response->getHeader('Location'));
    }

    #[TestDox('a client that wants JSON gets 422 with the messages')]
    public function testJsonClientGets422(): void {
        $request = RequestFactory::make('POST', 'contact', [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $this->router()->handle($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['email' => ['The email must be a valid email address.']],
            $body['errors'],
        );
    }

    #[TestDox('an XHR gets JSON even without an Accept header')]
    public function testAjaxGetsJson(): void {
        $request = RequestFactory::make('POST', 'contact', [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertSame(422, $this->router()->handle($request)->getStatusCode());
    }

    #[TestDox('a JSON body is answered with JSON')]
    public function testJsonContentTypeGetsJson(): void {
        $request = RequestFactory::make('POST', 'contact', [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        self::assertSame(422, $this->router()->handle($request)->getStatusCode());
    }

    #[TestDox('a browser asking for both html and json still gets the redirect')]
    public function testBrowserAcceptHeaderIsNotMistakenForAnApiClient(): void {
        // Browsers send long Accept headers that often mention json; html
        // being in there at all is what settles it.
        $request = RequestFactory::make('POST', 'contact', [], [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
        ]);

        self::assertSame(303, $this->router()->handle($request)->getStatusCode());
    }
}
