<?php

namespace App\Core\Http\Middleware;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use InvalidArgumentException;

/**
 * Cross-origin resource sharing: which other sites a browser may let call this
 * one.
 *
 * The router already answers OPTIONS with the methods a path supports, because
 * that is a fact about the route table. *Who* may call it is a policy of the
 * application - different for a shop, a public API and a plain website - so it
 * lives here, and only on the routes that want it:
 *
 *   $router->group(['middleware' => [new Cors(['https://app.example.cz'])]], ...);
 *
 * Two things this deliberately refuses to do, because they are the usual CORS
 * mistakes:
 *
 *   - It never reflects an arbitrary Origin back. Echoing whatever arrives is
 *     `*` in disguise, and with credentials it is worse than `*`, which the
 *     browser at least refuses.
 *   - It will not be built with `*` and credentials together. The browser
 *     rejects that combination outright, and the failure only shows up as a
 *     console error on someone else's page.
 *
 * CORS tells a browser what it may hand to page scripts. It protects nothing
 * from curl or a server-side caller; authentication still has to.
 */
final class Cors implements MiddlewareInterface {
    /** @var array<int, string> */
    private array $origins;

    /** @var array<int, string> */
    private array $methods;

    /** @var array<int, string> */
    private array $headers;

    /** @var array<int, string> */
    private array $exposed;

    /**
     * @param array<int, string> $origins  exact origins (scheme://host[:port]), or ['*']
     * @param array<int, string> $methods  methods a preflight may ask for
     * @param array<int, string> $headers  request headers a preflight may ask for, or ['*']
     * @param array<int, string> $exposed  response headers page scripts may read
     * @param bool               $credentials allow cookies and Authorization
     * @param int                $maxAge   seconds a browser may cache the preflight
     */
    public function __construct(
        array $origins,
        array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        array $headers = ['Content-Type', 'Authorization', 'X-CSRF-Token', 'X-Requested-With'],
        array $exposed = [],
        private bool $credentials = false,
        private int $maxAge = 600,
    ) {
        if ($origins === []) {
            throw new InvalidArgumentException('Cors needs at least one allowed origin.');
        }

        $this->origins = array_map(self::normalizeOrigin(...), $origins);
        $this->methods = array_map('strtoupper', $methods);
        $this->headers = $headers;
        $this->exposed = $exposed;

        if ($credentials && ($this->allowsAnyOrigin() || $this->headers === ['*'])) {
            throw new InvalidArgumentException(
                'Cors cannot allow credentials with a wildcard: browsers reject `*` on a credentialed request. List the origins (and headers) explicitly.',
            );
        }
    }

    /**
     * Built from the 'cors' section of app/Config/main.php.
     *
     *   'cors' => [
     *       'origins'     => ['https://app.example.cz'],
     *       'credentials' => true,
     *   ],
     */
    public static function fromConfig(): self {
        $config = Config::get('cors', []);
        $config = is_array($config) ? $config : [];

        return new self(
            origins: (array) ($config['origins'] ?? []),
            methods: (array) ($config['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            headers: (array) ($config['headers'] ?? ['Content-Type', 'Authorization', 'X-CSRF-Token', 'X-Requested-With']),
            exposed: (array) ($config['exposed'] ?? []),
            credentials: (bool) ($config['credentials'] ?? false),
            maxAge: (int) ($config['max_age'] ?? 600),
        );
    }

    public function process(Request $request, callable $next): Response {
        $origin = $request->getHeader('origin');

        // No Origin: a same-origin navigation, curl, a server-side caller. CORS
        // has nothing to say to any of them.
        if ($origin === null || $origin === '') {
            return $next($request);
        }

        $allowed = $this->isAllowed($origin);

        if ($this->isPreflight($request)) {
            // A disallowed origin gets the ordinary OPTIONS answer and no CORS
            // headers, which is how the browser learns the answer is no. A
            // made-up 403 would tell a probing script more than it needs.
            if (!$allowed) {
                return $this->vary($next($request));
            }

            return $this->vary($this->preflight($request, $origin));
        }

        $response = $next($request);

        if ($allowed) {
            $this->decorate($response, $origin);
        }

        return $this->vary($response);
    }

    private function preflight(Request $request, string $origin): Response {
        $response = new Response('', 204);
        $this->decorate($response, $origin);

        $response->setHeader('Access-Control-Allow-Methods', implode(', ', $this->methods));

        $requested = trim((string) $request->getHeader('access-control-request-headers'));
        $allowHeaders = $this->headers === ['*']
            // `*` for headers is only honoured without credentials, which the
            // constructor already guarantees; echoing the request is the
            // equivalent that older browsers also understand.
            ? $requested
            : implode(', ', $this->headers);

        if ($allowHeaders !== '') {
            $response->setHeader('Access-Control-Allow-Headers', $allowHeaders);
        }

        $response->setHeader('Access-Control-Max-Age', (string) $this->maxAge);

        return $response;
    }

    /** The headers every allowed response carries, preflight or not. */
    private function decorate(Response $response, string $origin): void {
        // The concrete origin rather than `*` whenever credentials are on, and
        // whenever there is a list: a list means the answer depends on who is
        // asking, and the header must say which one it was for.
        $response->setHeader(
            'Access-Control-Allow-Origin',
            $this->allowsAnyOrigin() && !$this->credentials ? '*' : $origin,
        );

        if ($this->credentials) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposed !== []) {
            $response->setHeader('Access-Control-Expose-Headers', implode(', ', $this->exposed));
        }
    }

    /**
     * Marks the response as varying by Origin.
     *
     * Without it a shared cache can hand one origin's Allow-Origin to another,
     * so a response for app.example.cz is served to a page on some other site.
     * Added rather than set, so a Vary the application already sent survives.
     */
    private function vary(Response $response): Response {
        if ($this->allowsAnyOrigin()) {
            return $response;
        }

        $existing = strtolower(implode(',', $response->getHeaders()['Vary'] ?? []));
        if (!preg_match('/(^|[\s,])origin([\s,]|$)/', $existing)) {
            $response->addHeader('Vary', 'Origin');
        }

        return $response;
    }

    private function isPreflight(Request $request): bool {
        return $request->getMethod() === 'OPTIONS'
            && $request->getHeader('access-control-request-method') !== null;
    }

    private function isAllowed(string $origin): bool {
        return $this->allowsAnyOrigin()
            || in_array(self::normalizeOrigin($origin), $this->origins, true);
    }

    private function allowsAnyOrigin(): bool {
        return in_array('*', $this->origins, true);
    }

    /**
     * Origins compare as scheme://host[:port], case-insensitively and without a
     * trailing slash - which is how browsers send them and how people tend to
     * mistype them in config.
     */
    private static function normalizeOrigin(string $origin): string {
        return $origin === '*' ? '*' : strtolower(rtrim(trim($origin), '/'));
    }
}
