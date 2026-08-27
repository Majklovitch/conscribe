<?php

namespace App\Core\Http;

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
     * Also accounts for the common reverse proxies (Cloudflare, Azure, load balancers).
     *
     * @param array|null $server Defaults to $_SERVER, so it can be called statically before a request exists.
     */
    public static function isSecure(?array $server = null): bool
    {
        $server ??= $_SERVER;

        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $forwardedProto = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $forwardedSsl = strtolower((string) ($server['HTTP_X_FORWARDED_SSL'] ?? ''));
        $frontEndHttps = strtolower((string) ($server['HTTP_FRONT_END_HTTPS'] ?? ''));
        $cfVisitor = (string) ($server['HTTP_CF_VISITOR'] ?? '');
        $xArrSsl = (string) ($server['HTTP_X_ARR_SSL'] ?? '');
        $requestScheme = strtolower((string) ($server['REQUEST_SCHEME'] ?? ''));
        $serverPort = (string) ($server['SERVER_PORT'] ?? '');

        return $https === 'on'
            || $https === '1'
            || $serverPort === '443'
            || str_contains($forwardedProto, 'https')
            || $forwardedSsl === 'on'
            || str_contains($cfVisitor, '"scheme":"https"')
            || $frontEndHttps === 'on'
            || $frontEndHttps === '1'
            || $xArrSsl !== ''
            || $requestScheme === 'https';
    }

    /**
     * Client IP address. Proxy headers are trusted only when the proxy itself is,
     * so the default source is always REMOTE_ADDR.
     */
    public function getClientIp(bool $trustProxy = false): ?string
    {
        if ($trustProxy) {
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
