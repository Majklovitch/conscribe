<?php

namespace App\Core;

/**
 * Class Request
 * Represents an HTTP Request.
 */
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

    /**
     * Request constructor.
     */
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

    /**
     * Creates a Request instance based on PHP superglobals.
     */
    public static function createFromGlobals(): self
    {
        $rawBody = file_get_contents('php://input');
        $bodyParams = $_POST;
        $hasInvalidJson = false;

        // Automatically decode JSON body if Content-Type is application/json
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains(strtolower($contentType), 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $hasInvalidJson = true;
            } elseif (is_array($decoded)) {
                $bodyParams = array_merge($bodyParams, $decoded);
            }
        }

        return new self($_GET, $bodyParams, $_COOKIE, $_FILES, $_SERVER, $rawBody, $hasInvalidJson);
    }

    /**
     * Checks if the request contains invalid/malformed JSON.
     */
    public function hasInvalidJson(): bool
    {
        return $this->hasInvalidJson;
    }

    /**
     * Extracts HTTP headers from $_SERVER.
     */
    private function extractHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace('_', '-', strtolower($key));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    /**
     * Gets the request path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Gets the HTTP method.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Checks if the request method is GET.
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Checks if the request method is POST.
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Checks if the request is an AJAX request.
     */
    public function isAjax(): bool
    {
        return strtolower($this->getHeader('x-requested-with') ?? '') === 'xmlhttprequest';
    }

    /**
     * Gets a parameter from query params or POST body.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $this->bodyParams[$key] ?? $default;
    }

    /**
     * Gets a query parameter (from $_GET).
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Gets a body parameter (from $_POST or JSON).
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->bodyParams[$key] ?? $default;
    }

    /**
     * Gets all query parameters.
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Gets all body parameters.
     */
    public function getBodyParams(): array
    {
        return $this->bodyParams;
    }

    /**
     * Gets a cookie value.
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Gets an uploaded file.
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Gets all headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Gets a specific header.
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? $default;
    }

    /**
     * Gets the raw request body.
     */
    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * Gets raw $_SERVER array.
     */
    public function getServerParams(): array
    {
        return $this->server;
    }
}
