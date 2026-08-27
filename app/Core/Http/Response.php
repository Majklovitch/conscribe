<?php

namespace App\Core\Http;

use App\Core\Config;
use InvalidArgumentException;
use JsonException;

/**
 * Class Response
 * Represents an HTTP Response.
 */
class Response
{
    private string $content;
    private int $statusCode;

    /**
     * Hlavičky v kanonickém tvaru názvu => pole hodnot.
     */
    private array $headers = [];

    /**
     * Cookies k odeslání: název => ['value' => string, 'options' => array].
     */
    private array $cookies = [];

    /**
     * Response constructor.
     * Normalizes input headers to array format (Header-Name => [value1, value2, ...])
     */
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
     * Převede název hlavičky na kanonický tvar (content-type => Content-Type),
     * aby stejná hlavička nešla odeslat dvakrát jen kvůli jinému zápisu.
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
     * Odstraní znaky umožňující HTTP response splitting.
     */
    private static function sanitizeHeaderValue(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    /**
     * Gets the response body content.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Sets the response body content.
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Gets the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Sets the HTTP status code.
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Gets all headers as an associative array where values are string arrays.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Vrátí první hodnotu hlavičky (case-insensitive).
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[self::normalizeHeaderName($name)][0] ?? null;
    }

    /**
     * Sets an HTTP header, completely replacing existing values for this header.
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[self::normalizeHeaderName($name)] = [self::sanitizeHeaderValue($value)];
        return $this;
    }

    /**
     * Appends a value to an HTTP header.
     */
    public function addHeader(string $name, string $value): self
    {
        $this->headers[self::normalizeHeaderName($name)][] = self::sanitizeHeaderValue($value);
        return $this;
    }

    /**
     * Removes an HTTP header.
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[self::normalizeHeaderName($name)]);
        return $this;
    }

    /**
     * Přidá cookie, která se odešle spolu s odpovědí.
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

    /**
     * Factory method for generating an HTML Response.
     */
    public static function html(string $content, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($content, $statusCode, $headers);
    }

    /**
     * Odpověď bez těla.
     */
    public static function noContent(int $statusCode = 204, array $headers = []): self
    {
        return new self('', $statusCode, $headers);
    }

    /**
     * Factory method for generating a JSON Response.
     *
     * @throws JsonException pokud data nelze zakódovat
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
     * Factory method for generating a Redirect Response.
     * Cíl musí být lokální cesta nebo adresa na stejné doméně jako conscribe.base_url,
     * jinak by šlo o open redirect.
     *
     * @throws InvalidArgumentException
     */
    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self
    {
        $headers['Location'] = self::sanitizeRedirectUrl($url);
        return new self('', $statusCode, $headers);
    }

    /**
     * Ověří a znormalizuje cíl přesměrování.
     *
     * @throws InvalidArgumentException
     */
    public static function sanitizeRedirectUrl(string $url): string
    {
        $url = trim(str_replace(["\r", "\n", "\0"], '', $url));

        if ($url === '') {
            throw new InvalidArgumentException('Redirect URL must not be empty.');
        }

        // Absolutní URL: povolena jen stejná doména jako base_url.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            $host = parse_url($url, PHP_URL_HOST);
            $baseUrl = (string) Config::get('conscribe.base_url', '');
            $baseHost = $baseUrl !== '' ? parse_url($baseUrl, PHP_URL_HOST) : null;

            if ($host === null || $baseHost === null || strcasecmp($host, $baseHost) !== 0) {
                throw new InvalidArgumentException("Refusing to redirect to external URL '{$url}'.");
            }

            return $url;
        }

        // Protokolově relativní ('//evil.com') i '/\evil.com' vedou mimo web.
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            throw new InvalidArgumentException("Refusing to redirect to external URL '{$url}'.");
        }

        return '/' . ltrim($url, '/');
    }

    /**
     * Stavové kódy, které podle RFC 9110 nesmí mít tělo.
     */
    private function isBodyless(): bool
    {
        return $this->statusCode === 204
            || $this->statusCode === 304
            || ($this->statusCode >= 100 && $this->statusCode < 200);
    }

    /**
     * Sends the HTTP response headers and body content to the browser.
     *
     * @param bool $includeBody false pro HEAD requesty
     */
    public function send(bool $includeBody = true): void
    {
        if (headers_sent()) {
            if ($includeBody && !$this->isBodyless()) {
                echo $this->content;
            }
            return;
        }

        // Send HTTP Status Code
        http_response_code($this->statusCode);

        // Send HTTP Headers
        foreach ($this->headers as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header("$name: $value", $first);
                $first = false;
            }
        }

        // Send cookies
        foreach ($this->cookies as $name => $cookie) {
            setcookie($name, $cookie['value'], $cookie['options']);
        }

        if ($includeBody && !$this->isBodyless()) {
            echo $this->content;
        }
    }
}
