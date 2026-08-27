<?php

namespace App\Core\Http;

use App\Core\Config;
use InvalidArgumentException;
use JsonException;

class Response
{
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

    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
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
    private static function normalizeHeaderName(string $name): string
    {
        $name = trim(str_replace(["\r", "\n", "\0"], '', $name));

        return implode('-', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            explode('-', $name)
        ));
    }

    /**
     * Strips the characters that would allow HTTP response splitting.
     */
    private static function sanitizeHeaderValue(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Returns the first value of a header (case-insensitive).
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[self::normalizeHeaderName($name)][0] ?? null;
    }

    /**
     * Sets a header, discarding any values it already had.
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[self::normalizeHeaderName($name)] = [self::sanitizeHeaderValue($value)];
        return $this;
    }

    /**
     * Appends another value to an existing header.
     */
    public function addHeader(string $name, string $value): self
    {
        $this->headers[self::normalizeHeaderName($name)][] = self::sanitizeHeaderValue($value);
        return $this;
    }

    public function removeHeader(string $name): self
    {
        unset($this->headers[self::normalizeHeaderName($name)]);
        return $this;
    }

    /**
     * Adds a cookie to be sent along with the response.
     */
    public function withCookie(string $name, string $value, array $options = []): self
    {
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

    public static function html(string $content, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($content, $statusCode, $headers);
    }

    /**
     * A response with no body.
     */
    public static function noContent(int $statusCode = 204, array $headers = []): self
    {
        return new self('', $statusCode, $headers);
    }

    /**
     * @throws JsonException when the data cannot be encoded
     */
    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $content = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return new self($content, $statusCode, $headers);
    }

    /**
     * The target must be a local path or an address on the same domain as
     * conscribe.base_url, otherwise this would be an open redirect.
     *
     * @throws InvalidArgumentException
     */
    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self
    {
        $headers['Location'] = self::sanitizeRedirectUrl($url);
        return new self('', $statusCode, $headers);
    }

    /**
     * Validates and normalizes the redirect target.
     *
     * @throws InvalidArgumentException
     */
    public static function sanitizeRedirectUrl(string $url): string
    {
        $url = trim(str_replace(["\r", "\n", "\0"], '', $url));

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

        return '/' . ltrim($url, '/');
    }

    /**
     * Status codes that must not have a body under RFC 9110.
     */
    private function isBodyless(): bool
    {
        return $this->statusCode === 204
            || $this->statusCode === 304
            || ($this->statusCode >= 100 && $this->statusCode < 200);
    }

    /**
     * @param bool $includeBody false for HEAD requests
     */
    public function send(bool $includeBody = true): void
    {
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
