<?php

namespace App\Core\Http;

use App\Core\Config;
use InvalidArgumentException;
use JsonException;

class Response {
    private string $content;
    private int $statusCode;

    /**
     * Headers as canonical name => array of values.
     */
    private array $headers = [];

    /**
     * Cookies to send: name => ['value' => string, 'options' => array].
     */
    private array $cookies = [];

    public function __construct(string $content = '', int $statusCode = 200, array $headers = []) {
        $this->content = $content;
        $this->statusCode = $statusCode;

        foreach ($headers as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $this->addHeader((string) $name, (string) $value);
            }
        }
    }

    /**
     * Converts a header name to its canonical form (content-type => Content-Type),
     * so the same header cannot be sent twice merely because it was spelled
     * differently.
     */
    private static function normalizeHeaderName(string $name): string {
        $name = trim(str_replace(["\r", "\n", "\0"], '', $name));

        return implode('-', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            explode('-', $name),
        ));
    }

    /**
     * Strips the characters that would allow HTTP response splitting.
     */
    private static function sanitizeHeaderValue(string $value): string {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    public function getContent(): string {
        return $this->content;
    }

    public function setContent(string $content): self {
        $this->content = $content;
        return $this;
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): self {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array {
        return $this->headers;
    }

    /**
     * Returns the first value of a header (case-insensitive).
     */
    public function getHeader(string $name): ?string {
        return $this->headers[self::normalizeHeaderName($name)][0] ?? null;
    }

    /**
     * Sets a header, discarding any values it already had.
     */
    public function setHeader(string $name, string $value): self {
        $this->headers[self::normalizeHeaderName($name)] = [self::sanitizeHeaderValue($value)];
        return $this;
    }

    /**
     * Appends another value to an existing header.
     */
    public function addHeader(string $name, string $value): self {
        $this->headers[self::normalizeHeaderName($name)][] = self::sanitizeHeaderValue($value);
        return $this;
    }

    public function removeHeader(string $name): self {
        unset($this->headers[self::normalizeHeaderName($name)]);
        return $this;
    }

    /**
     * Adds a cookie to be sent along with the response.
     */
    public function withCookie(string $name, string $value, array $options = []): self {
        $this->cookies[$name] = [
            'value'   => $value,
            'options' => $options + [
                'expires'  => 0,
                'path'     => '/',
                'secure'   => Request::isSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
        return $this;
    }

    public static function html(string $content, int $statusCode = 200, array $headers = []): self {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($content, $statusCode, $headers);
    }

    /**
     * A response with no body.
     */
    public static function noContent(int $statusCode = 204, array $headers = []): self {
        return new self('', $statusCode, $headers);
    }

    /**
     * @throws JsonException when the data cannot be encoded
     */
    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $content = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new self($content, $statusCode, $headers);
    }

    /**
     * The target must be a local path or an address on the same domain as
     * conscribe.base_url, otherwise this would be an open redirect.
     *
     * @throws InvalidArgumentException
     */
    /**
     * An RFC 9457 problem document (the successor of RFC 7807).
     *
     * The one shape every error sent to an API client takes - error pages,
     * failed validation, the router's own fallback - so a client parses errors
     * one way whatever raised them. Extension members ride alongside the
     * standard ones, which is how a 422 carries its per-field messages:
     *
     *   Response::problem(422, 'The given data was invalid.', ['errors' => [...]]);
     *
     * `type` is about:blank, as the RFC prescribes when the status code says it
     * all; `title` is the reason phrase, never the message, because the RFC
     * wants it identical for every occurrence of the problem.
     *
     * @param array<string, mixed> $extensions extra members; cannot replace the standard ones
     */
    public static function problem(int $status, string $detail, array $extensions = [], ?string $instance = null): self {
        $document = [
            'type'   => 'about:blank',
            'title'  => self::PROBLEM_TITLES[$status] ?? (intdiv($status, 100) === 5 ? 'Server Error' : 'Client Error'),
            'status' => $status,
            'detail' => $detail,
        ];

        if ($instance !== null) {
            $document['instance'] = $instance;
        }

        $body = json_encode(
            $document + $extensions,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return new self((string) $body, $status, ['Content-Type' => 'application/problem+json']);
    }

    /** Reason phrases for the codes the framework answers with itself. */
    private const PROBLEM_TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        419 => 'Page Expired',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self {
        $headers['Location'] = self::sanitizeRedirectUrl($url);
        return new self('', $statusCode, $headers);
    }

    /**
     * Validates and normalizes the redirect target.
     *
     * @throws InvalidArgumentException
     */
    public static function sanitizeRedirectUrl(string $url): string {
        // Tabs too: browsers remove them from a URL before reading it, so
        // "/\t/evil.com" arrives as "//evil.com".
        $url = trim(str_replace(["\r", "\n", "\0", "\t"], '', $url));

        if ($url === '') {
            throw new InvalidArgumentException('Redirect URL must not be empty.');
        }

        // Absolute URL: only the same domain as base_url is allowed.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            $host = parse_url($url, PHP_URL_HOST);
            $baseUrl = (string) Config::get('conscribe.base_url', '');
            $baseHost = $baseUrl !== '' ? parse_url($baseUrl, PHP_URL_HOST) : null;

            if ($host === null || $baseHost === null || strcasecmp($host, $baseHost) !== 0) {
                throw new InvalidArgumentException("Refusing to redirect to external URL '{$url}'.");
            }

            return $url;
        }

        // Protocol-relative ('//evil.com') and '/\evil.com' both lead off-site.
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            throw new InvalidArgumentException("Refusing to redirect to external URL '{$url}'.");
        }

        $local = '/' . ltrim($url, '/');

        // Checked again on the result. Browsers read a backslash as a slash, so
        // an input that starts with one - '\evil.com', '\/evil.com' - passes the
        // check above and only becomes '/\evil.com', meaning '//evil.com', once
        // the leading slash is prepended.
        if (str_starts_with(str_replace('\\', '/', $local), '//')) {
            throw new InvalidArgumentException("Refusing to redirect to external URL '{$url}'.");
        }

        return $local;
    }

    /**
     * Status codes that must not have a body under RFC 9110.
     */
    private function isBodyless(): bool {
        return $this->statusCode === 204
            || $this->statusCode === 304
            || ($this->statusCode >= 100 && $this->statusCode < 200);
    }

    /**
     * @param bool $includeBody false for HEAD requests
     */
    public function send(bool $includeBody = true): void {
        if (headers_sent()) {
            if ($includeBody && !$this->isBodyless()) {
                echo $this->content;
            }
            return;
        }

        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header("$name: $value", $first);
                $first = false;
            }
        }

        foreach ($this->cookies as $name => $cookie) {
            setcookie($name, $cookie['value'], $cookie['options']);
        }

        if ($includeBody && !$this->isBodyless()) {
            echo $this->content;
        }
    }
}
