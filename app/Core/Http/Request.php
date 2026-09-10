<?php

namespace App\Core\Http;

use App\Core\Config;

class Request
{
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

    public function __construct(
        array $queryParams = [],
        array $bodyParams = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $rawBody = null,
        bool $hasInvalidJson = false
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

    public static function createFromGlobals(): self
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        // With multipart/form-data php://input is always empty, nothing to read.
        $rawBody = str_contains($contentType, 'multipart/form-data')
            ? null
            : (file_get_contents('php://input') ?: '');

        $bodyParams = $_POST;
        $hasInvalidJson = false;

        if ($rawBody !== null && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $hasInvalidJson = true;
            } elseif (is_array($decoded)) {
                $bodyParams = array_merge($bodyParams, $decoded);
            }
        }

        return new self($_GET, $bodyParams, $_COOKIE, $_FILES, $_SERVER, $rawBody, $hasInvalidJson);
    }

    public function hasInvalidJson(): bool
    {
        return $this->hasInvalidJson;
    }

    private function extractHeaders(array $server): array
    {
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
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Returns a copy of the request with a different path.
     * Used by modules that strip their own prefix (a language, say) from it.
     */
    public function withPath(string $path): self
    {
        $clone = clone $this;
        $clone->path = trim($path, '/');

        return $clone;
    }

    /**
     * The first path segment, or 'home' for the site root.
     */
    public function getFirstSegment(string $default = 'home'): string
    {
        $segment = explode('/', $this->path)[0];

        return $segment === '' ? $default : $segment;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    public function isAjax(): bool
    {
        return strtolower($this->getHeader('x-requested-with') ?? '') === 'xmlhttprequest';
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
    public static function isSecure(?array $server = null): bool
    {
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
    public static function isFromTrustedProxy(array $server): bool
    {
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
    private static function ipMatches(string $ip, string $range): bool
    {
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
     * Client IP address. Proxy headers are trusted only when the proxy itself is,
     * so the default source is always REMOTE_ADDR.
     */
    public function getClientIp(bool $trustProxy = false): ?string
    {
        if ($trustProxy && self::isFromTrustedProxy($this->server)) {
            $forwarded = $this->getHeader('x-forwarded-for');
            if ($forwarded !== null && $forwarded !== '') {
                return trim(explode(',', $forwarded)[0]);
            }
        }

        return $this->server['REMOTE_ADDR'] ?? null;
    }

    /**
     * A value from the query string, falling back to the request body.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $this->bodyParams[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->bodyParams[$key] ?? $default;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getBodyParams(): array
    {
        return $this->bodyParams;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? $default;
    }

    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    public function getServerParams(): array
    {
        return $this->server;
    }
}
