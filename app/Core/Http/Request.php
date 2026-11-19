<?php

namespace App\Core\Http;

use App\Core\Config;

class Request {
    private string $path;
    private string $method;
    private array $queryParams;
    private array $bodyParams;
    private array $cookies;
    private array $headers;
    private array $files;
    private array $server;
    private ?string $rawBody;
    private bool $hasInvalidJson;

    /**
     * Values a middleware hands down to the controller, see withAttribute().
     *
     * @var array<string, mixed>
     */
    private array $attributes = [];

    public function __construct(
        array $queryParams = [],
        array $bodyParams = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $rawBody = null,
        bool $hasInvalidJson = false,
    ) {
        $this->queryParams = $queryParams;
        $this->bodyParams = $bodyParams;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->rawBody = $rawBody;
        $this->hasInvalidJson = $hasInvalidJson;

        $this->method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        $uri = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $this->path = trim($uri, '/');

        $this->headers = $this->extractHeaders($server);
    }

    public static function createFromGlobals(): self {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        // With multipart/form-data php://input is always empty, nothing to read.
        $rawBody = str_contains($contentType, 'multipart/form-data')
            ? null
            : (file_get_contents('php://input') ?: '');

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $bodyParams = $_POST;
        $files = $_FILES;
        $hasInvalidJson = false;

        // PHP fills $_POST and $_FILES for POST only. PUT, PATCH and DELETE
        // arrive with the same encodings - from fetch(), from API clients - and
        // would otherwise reach the application, and the CSRF check, with an
        // empty body.
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            if (str_contains($contentType, 'multipart/form-data') && function_exists('request_parse_body')) {
                try {
                    [$bodyParams, $files] = request_parse_body();
                } catch (\Throwable) {
                    // A malformed body is treated as an empty one, as PHP
                    // does for a malformed POST.
                }
            } elseif ($rawBody !== null) {
                $bodyParams = self::parseUrlencoded($contentType, $rawBody) ?? $bodyParams;
            }
        }

        if ($rawBody !== null && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $hasInvalidJson = true;
            } elseif (is_array($decoded)) {
                $bodyParams = array_merge($bodyParams, $decoded);
            }
        }

        return new self($_GET, $bodyParams, $_COOKIE, $files, $_SERVER, $rawBody, $hasInvalidJson);
    }

    public function hasInvalidJson(): bool {
        return $this->hasInvalidJson;
    }

    private function extractHeaders(array $server): array {
        $headers = [];
        foreach ($server as $key => $value) {
            // On a rewrite, Apache moves Authorization into REDIRECT_HTTP_AUTHORIZATION.
            if (str_starts_with($key, 'REDIRECT_HTTP_')) {
                $key = substr($key, 9);
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] ??= $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace('_', '-', strtolower($key));
                $headers[$name] ??= $value;
            }
        }
        return $headers;
    }

    /**
     * The path without leading or trailing slashes.
     * The site root is an empty string, not a slash.
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * Returns a copy of the request with a different path.
     * Used by modules that strip their own prefix (a language, say) from it.
     */
    public function withPath(string $path): self {
        $clone = clone $this;
        $clone->path = trim($path, '/');

        return $clone;
    }

    /**
     * A copy of the request carrying a named value - e.g. the API client a
     * middleware authenticated, read back by the controller. The request
     * itself stays immutable, so a middleware passes the copy to $next().
     */
    public function withAttribute(string $key, mixed $value): static {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    /** A value set by withAttribute(), or $default when there is none. */
    public function getAttribute(string $key, mixed $default = null): mixed {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    /**
     * The first path segment, or 'home' for the site root.
     */
    public function getFirstSegment(string $default = 'home'): string {
        $segment = explode('/', $this->path)[0];

        return $segment === '' ? $default : $segment;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function isGet(): bool {
        return $this->method === 'GET';
    }

    public function isPost(): bool {
        return $this->method === 'POST';
    }

    public function isHead(): bool {
        return $this->method === 'HEAD';
    }

    public function isAjax(): bool {
        return strtolower($this->getHeader('x-requested-with') ?? '') === 'xmlhttprequest';
    }

    /**
     * Whether the client would rather have JSON than a page.
     *
     * Decides the shape of every answer the framework builds on its own - a
     * failed validation, an error page - so it lives here rather than in each
     * of them, and they cannot drift into disagreeing about who is an API
     * client.
     *
     * An XHR, a JSON body, or an Accept header asking for JSON (including
     * `+json` types such as `application/problem+json`) without also asking
     * for HTML. The last condition matters: browsers send long Accept headers
     * that often mention JSON, and HTML being in there at all means a person
     * is looking at the result.
     */
    public function wantsJson(): bool {
        if ($this->isAjax()) {
            return true;
        }

        $accept = strtolower($this->getHeader('accept') ?? '');
        $asksForJson = str_contains($accept, 'application/json') || str_contains($accept, '+json');

        if ($asksForJson && !str_contains($accept, 'text/html')) {
            return true;
        }

        return str_contains(strtolower($this->getHeader('content-type') ?? ''), 'json');
    }

    /**
     * Determines whether the request arrived over HTTPS.
     *
     * Only two signals are believed unconditionally, because only they come
     * from the web server: $_SERVER['HTTPS'] and REQUEST_SCHEME/SERVER_PORT.
     * Everything else on this list is a request header, which any client can
     * send - so a plain HTTP request would otherwise be able to talk the
     * application into calling itself secure, and then into emitting HSTS and
     * Secure cookies that never come back.
     *
     * The proxy headers are therefore consulted only when REMOTE_ADDR is one of
     * the 'conscribe.trusted_proxies' entries. The default is an empty list, so
     * a site that is not behind a proxy trusts nothing.
     *
     * @param array|null $server Defaults to $_SERVER, so it can be called statically before a request exists.
     */
    public static function isSecure(?array $server = null): bool {
        $server ??= $_SERVER;

        // Set by the web server itself - not forgeable by the client.
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }

        if (strtolower((string) ($server['REQUEST_SCHEME'] ?? '')) === 'https') {
            return true;
        }

        if ((string) ($server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        if (!self::isFromTrustedProxy($server)) {
            return false;
        }

        $forwardedProto = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $forwardedSsl = strtolower((string) ($server['HTTP_X_FORWARDED_SSL'] ?? ''));
        $frontEndHttps = strtolower((string) ($server['HTTP_FRONT_END_HTTPS'] ?? ''));
        $cfVisitor = (string) ($server['HTTP_CF_VISITOR'] ?? '');
        $xArrSsl = (string) ($server['HTTP_X_ARR_SSL'] ?? '');

        return str_contains($forwardedProto, 'https')
            || $forwardedSsl === 'on'
            || str_contains($cfVisitor, '"scheme":"https"')
            || $frontEndHttps === 'on'
            || $frontEndHttps === '1'
            || $xArrSsl !== '';
    }

    /**
     * Whether the immediate peer is a proxy the configuration vouches for.
     *
     * @param array<string, mixed> $server
     */
    public static function isFromTrustedProxy(array $server): bool {
        $proxies = Config::get('conscribe.trusted_proxies', []);
        if (!is_array($proxies) || $proxies === []) {
            return false;
        }

        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return false;
        }

        foreach ($proxies as $proxy) {
            if (self::ipMatches($remote, (string) $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matches an address against a single IP or a CIDR block (IPv4 and IPv6).
     */
    private static function ipMatches(string $ip, string $range): bool {
        $range = trim($range);
        if ($range === '') {
            return false;
        }

        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);

        // A malformed suffix must not become /0 through a silent (int) cast -
        // that would turn one typo in the configuration into "trust everyone".
        if ($bits === '' || !ctype_digit($bits)) {
            return false;
        }

        $address = @inet_pton($ip);
        $network = @inet_pton($subnet);

        // Different families (an IPv4 address against an IPv6 block) never match.
        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits > strlen($network) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }

    /**
     * The client's address.
     *
     * REMOTE_ADDR, unless the request came through a proxy listed in
     * 'conscribe.trusted_proxies'. Then X-Forwarded-For is walked from the
     * right - the end the proxies wrote - past every address that is itself a
     * trusted proxy, and the first one that is not is the client.
     *
     * The left-most entry is never taken on its own: the client writes that
     * one, so taking it would let anyone claim any address - and a login
     * throttle keyed on the address would then allow unlimited guesses.
     *
     * Trusting a configured proxy is the default because configuring it is the
     * decision; before, every caller also had to pass true, and one that did
     * not got the proxy's address for every visitor. A site listing no proxies
     * gets REMOTE_ADDR as it always did. Pass false to insist on the direct
     * peer.
     */
    public function getClientIp(bool $trustProxy = true): ?string {
        $remote = $this->server['REMOTE_ADDR'] ?? null;

        if (!$trustProxy || !self::isFromTrustedProxy($this->server)) {
            return $remote;
        }

        $proxies = Config::get('conscribe.trusted_proxies', []);
        $proxies = is_array($proxies) ? $proxies : [];
        $hops = array_reverse(array_map('trim', explode(',', (string) $this->getHeader('x-forwarded-for', ''))));

        foreach ($hops as $hop) {
            // A hop that is not an address cannot be vouched for. Stop there
            // rather than skip past it to something further left, which the
            // client may have written.
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }

            $trusted = false;
            foreach ($proxies as $proxy) {
                if (self::ipMatches($hop, (string) $proxy)) {
                    $trusted = true;
                    break;
                }
            }

            if (!$trusted) {
                return $hop;
            }
        }

        return $remote;
    }

    /**
     * An application/x-www-form-urlencoded body as an array, or null when the
     * body has another type.
     *
     * @internal public so the parsing is testable without php://input
     * @return array<array-key, mixed>|null
     */
    public static function parseUrlencoded(string $contentType, string $rawBody): ?array {
        if (!str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            return null;
        }

        parse_str($rawBody, $parsed);

        return $parsed;
    }

    /**
     * A value from the query string, falling back to the request body.
     */
    public function get(string $key, mixed $default = null): mixed {
        return $this->queryParams[$key] ?? $this->bodyParams[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed {
        return $this->queryParams[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed {
        return $this->bodyParams[$key] ?? $default;
    }

    public function getQueryParams(): array {
        return $this->queryParams;
    }

    public function getBodyParams(): array {
        return $this->bodyParams;
    }

    public function cookie(string $key, mixed $default = null): mixed {
        return $this->cookies[$key] ?? $default;
    }

    public function file(string $key): ?array {
        return $this->files[$key] ?? null;
    }

    public function getHeaders(): array {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string {
        $name = strtolower($name);
        return $this->headers[$name] ?? $default;
    }

    public function getRawBody(): ?string {
        return $this->rawBody;
    }

    public function getServerParams(): array {
        return $this->server;
    }
}
