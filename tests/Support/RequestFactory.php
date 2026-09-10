<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Http\Request;

/**
 * Builds a Request without touching the superglobals.
 *
 * Request::createFromGlobals() reads $_SERVER and php://input, neither of which
 * a test can set meaningfully; the constructor takes everything explicitly,
 * which is what makes the router testable at all.
 */
final class RequestFactory
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $server extra $_SERVER entries, e.g. headers
     */
    public static function make(string $method, string $path, array $body = [], array $server = []): Request
    {
        return new Request(
            queryParams: [],
            bodyParams: $body,
            cookies: [],
            files: [],
            server: [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI'    => '/' . ltrim($path, '/'),
            ] + $server,
        );
    }

    /** A request carrying a valid CSRF token in the body, as a form post would. */
    public static function withToken(string $method, string $path, array $body = []): Request
    {
        return self::make($method, $path, $body + ['csrf_token' => FakeSession::VALID_TOKEN]);
    }
}
