<?php

namespace App\Core;

/**
 * Class Response
 * Represents an HTTP Response.
 */
class Response
{
    private string $content;
    private int $statusCode;
    private array $headers;

    /**
     * Response constructor.
     * Normalizes input headers to array format (Header-Name => [value1, value2, ...])
     */
    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = [];
        
        foreach ($headers as $name => $values) {
            if (is_array($values)) {
                $this->headers[$name] = array_values($values);
            } else {
                $this->headers[$name] = [(string) $values];
            }
        }
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
     * Sets an HTTP header, completely replacing existing values for this header.
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = [$value];
        return $this;
    }

    /**
     * Appends a value to an HTTP header.
     */
    public function addHeader(string $name, string $value): self
    {
        if (!isset($this->headers[$name])) {
            $this->headers[$name] = [];
        }
        $this->headers[$name][] = $value;
        return $this;
    }

    /**
     * Removes an HTTP header.
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }

    /**
     * Factory method for generating a JSON Response.
     */
    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self($content, $statusCode, $headers);
    }

    /**
     * Factory method for generating a Redirect Response.
     */
    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self
    {
        $headers['Location'] = $url;
        return new self('', $statusCode, $headers);
    }

    /**
     * Sends the HTTP response headers and body content to the browser.
     */
    public function send(): void
    {
        if (headers_sent()) {
            echo $this->content;
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

        echo $this->content;
    }
}
