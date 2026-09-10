<?php

namespace App\Core\Validation;

use Countable;
use JsonSerializable;

/**
 * The messages a validation run produced, by field.
 *
 * A field can fail more than once ("required" and "email" are different
 * complaints), so each one holds a list. Templates almost always want just the
 * first, which is what first() is for.
 */
final class ErrorBag implements Countable, JsonSerializable {
    /** @var array<string, array<int, string>> */
    private array $messages;

    /** @param array<string, array<int, string>> $messages */
    public function __construct(array $messages = []) {
        $this->messages = $messages;
    }

    public function add(string $field, string $message): void {
        $this->messages[$field][] = $message;
    }

    public function has(string $field): bool {
        return ($this->messages[$field] ?? []) !== [];
    }

    public function isEmpty(): bool {
        return $this->messages === [];
    }

    /** The first message for a field, or '' - the shape a template wants. */
    public function first(string $field): string {
        return $this->messages[$field][0] ?? '';
    }

    /** @return array<int, string> */
    public function get(string $field): array {
        return $this->messages[$field] ?? [];
    }

    /** @return array<string, array<int, string>> */
    public function messages(): array {
        return $this->messages;
    }

    /**
     * Every message, flattened - for "please fix the following" summaries.
     *
     * @return array<int, string>
     */
    public function all(): array {
        return array_merge(...array_values($this->messages)) ?: [];
    }

    /** @return array<string, string> field => first message */
    public function firsts(): array {
        return array_map(static fn (array $messages): string => $messages[0] ?? '', $this->messages);
    }

    public function count(): int {
        return count($this->messages);
    }

    /** @return array<string, array<int, string>> */
    public function jsonSerialize(): array {
        return $this->messages;
    }
}
