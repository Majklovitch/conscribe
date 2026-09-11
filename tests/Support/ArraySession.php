<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Http\Session;

/**
 * A Session kept in an array.
 *
 * The real one needs PHP's session machinery, which under the CLI cannot send
 * a cookie and never starts - so anything that stores state in the session,
 * like a login, could not be tested against it. Only the methods that touch
 * storage are replaced; the counters let a test see that the id and the CSRF
 * token were rotated, which is the part of a login most worth asserting.
 */
final class ArraySession extends Session {
    /** @var array<string, mixed> */
    private array $data = [];

    public int $regenerated = 0;
    public int $tokensRotated = 0;

    public function start(): bool {
        return true;
    }

    public function isStarted(): bool {
        return true;
    }

    /** Set to false to act as a visitor whose browser sent no session cookie. */
    public bool $exists = true;

    public function exists(): bool {
        return $this->exists;
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): self {
        $this->data[$key] = $value;

        return $this;
    }

    public function has(string $key): bool {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): self {
        unset($this->data[$key]);

        return $this;
    }

    public function pull(string $key, mixed $default = null): mixed {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    public function regenerate(bool $deleteOld = true): bool {
        $this->regenerated++;

        return true;
    }

    public function rotateToken(): self {
        $this->tokensRotated++;

        return $this;
    }

    public function validateToken(?string $token): bool {
        return true;
    }
}
