<?php

namespace App\Core\Validation;

use Closure;
use InvalidArgumentException;

/**
 * Rule-based validation of an array of input.
 *
 *   $data = Validator::make($request->getBodyParams(), [
 *       'email'    => 'required|email|max:255',
 *       'age'      => 'nullable|int|between:18,120',
 *       'password' => 'required|min:8|confirmed',
 *       'role'     => ['required', 'in:admin,editor'],
 *   ])->validate();
 *
 * validate() returns only the fields that were asked for, so what comes back
 * can be handed to a repository without a second pass to drop whatever else the
 * form posted.
 *
 * Rules are written as `name:argument,argument`. A pipe-separated string is the
 * short form and an array is the long one; the array form is what you want as
 * soon as a rule's argument contains a pipe, as a regex often does.
 */
final class Validator {
    /**
     * Rules whose job is to describe the value rather than test it.
     *
     * They are read before the loop and never produce a message of their own,
     * so a field can be `nullable` without that also being a check that could
     * fail.
     */
    private const MODIFIERS = ['nullable', 'sometimes'];

    private ErrorBag $errors;

    /** @var array<string, mixed> */
    private array $validated = [];

    private bool $ran = false;

    /**
     * @param array<string, mixed>                          $data
     * @param array<string, string|array<int, string|Closure>> $rules
     * @param array<string, string>                         $messages field.rule => message
     */
    public function __construct(
        private array $data,
        private array $rules,
        private array $messages = [],
    ) {
        $this->errors = new ErrorBag();
    }

    /**
     * @param array<string, mixed>                          $data
     * @param array<string, string|array<int, string|Closure>> $rules
     * @param array<string, string>                         $messages
     */
    public static function make(array $data, array $rules, array $messages = []): self {
        return new self($data, $rules, $messages);
    }

    public function passes(): bool {
        $this->run();

        return $this->errors->isEmpty();
    }

    public function fails(): bool {
        return !$this->passes();
    }

    public function errors(): ErrorBag {
        $this->run();

        return $this->errors;
    }

    /**
     * The validated fields only.
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(): array {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    /**
     * @return array<string, mixed> whatever passed, without throwing
     */
    public function validated(): array {
        $this->run();

        return $this->validated;
    }

    private function run(): void {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        foreach ($this->rules as $field => $rules) {
            $this->check($field, $this->parse($rules));
        }
    }

    /**
     * @param array<int, array{name: string, args: array<int, string>}|Closure> $rules
     */
    private function check(string $field, array $rules): void {
        $names = array_map(
            static fn (array|Closure $rule): string => $rule instanceof Closure ? '' : $rule['name'],
            $rules,
        );

        $present = array_key_exists($field, $this->data);
        $value = $this->data[$field] ?? null;

        // A field marked `sometimes` is only validated when it was sent at all,
        // which is what a partial update (PATCH) needs.
        if (!$present && in_array('sometimes', $names, true)) {
            return;
        }

        // `nullable` means an empty value is acceptable and the remaining rules
        // have nothing to say about it - without it, `email` would reject the
        // empty string that an untouched optional input posts.
        if (in_array('nullable', $names, true) && $this->isEmpty($value)) {
            $this->validated[$field] = $present ? $value : null;

            return;
        }

        $failed = false;

        foreach ($rules as $rule) {
            if ($rule instanceof Closure) {
                $result = $rule($value, $this->data);
                if ($result !== true) {
                    $this->errors->add($field, is_string($result) ? $result : $this->message($field, 'closure', []));
                    $failed = true;
                }
                continue;
            }

            if (in_array($rule['name'], self::MODIFIERS, true)) {
                continue;
            }

            if (!$this->satisfies($rule['name'], $value, $rule['args'], $field)) {
                $this->errors->add($field, $this->message($field, $rule['name'], $rule['args'], $value));
                $failed = true;

                // One complaint per field is what a form can show next to an
                // input; reporting that a missing value is also not an email
                // helps nobody.
                break;
            }
        }

        if (!$failed && $present) {
            $this->validated[$field] = $value;
        }
    }

    /**
     * @param array<int, string> $args
     */
    private function satisfies(string $rule, mixed $value, array $args, string $field): bool {
        return match ($rule) {
            'required' => !$this->isEmpty($value),
            'string' => is_string($value),
            'int', 'integer' => $this->isInteger($value),
            'numeric' => is_numeric($value),
            'bool', 'boolean' => is_bool($value)
                || in_array($value, [0, 1, '0', '1', 'true', 'false', 'on', 'off'], true),
            'array' => is_array($value),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'date' => is_string($value) && strtotime($value) !== false,
            'alpha' => is_string($value) && preg_match('/^[\pL]+$/u', $value) === 1,
            'alpha_num' => is_string($value) && preg_match('/^[\pL\pN]+$/u', $value) === 1,
            'alpha_dash' => is_string($value) && preg_match('/^[\pL\pN_-]+$/u', $value) === 1,
            'min' => $this->size($value) >= (float) ($args[0] ?? 0),
            'max' => $this->size($value) <= (float) ($args[0] ?? 0),
            'between' => $this->size($value) >= (float) ($args[0] ?? 0)
                && $this->size($value) <= (float) ($args[1] ?? 0),
            'in' => in_array((string) $this->scalar($value), $args, true),
            'not_in' => !in_array((string) $this->scalar($value), $args, true),
            'regex' => is_string($value) && preg_match($args[0] ?? '//', $value) === 1,
            'same' => $value === ($this->data[$args[0] ?? ''] ?? null),
            'different' => $value !== ($this->data[$args[0] ?? ''] ?? null),
            'confirmed' => $value === ($this->data[$field . '_confirmation'] ?? null),
            'accepted' => in_array($value, [true, 1, '1', 'yes', 'on'], true),
            default => throw new InvalidArgumentException(sprintf('There is no validation rule named "%s".', $rule)),
        };
    }

    /**
     * What min/max/between compare against: a number's value, a string's
     * length, an array's count.
     */
    private function size(mixed $value): float {
        return match (true) {
            is_numeric($value) => (float) $value,
            is_string($value) => (float) mb_strlen($value),
            is_array($value) => (float) count($value),
            is_bool($value) => $value ? 1.0 : 0.0,
            default => 0.0,
        };
    }

    private function scalar(mixed $value): string|int|float|bool|null {
        return is_scalar($value) ? $value : null;
    }

    private function isInteger(mixed $value): bool {
        return is_int($value)
            || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }

    /**
     * Empty for validation purposes.
     *
     * `0` and `'0'` are values, not absences - a required quantity of zero must
     * not read as "you forgot to fill this in".
     */
    private function isEmpty(mixed $value): bool {
        return $value === null
            || $value === ''
            || $value === []
            || (is_string($value) && trim($value) === '');
    }

    /**
     * @param string|array<int, string|Closure> $rules
     * @return array<int, array{name: string, args: array<int, string>}|Closure>
     */
    private function parse(string|array $rules): array {
        $list = is_string($rules) ? explode('|', $rules) : $rules;
        $parsed = [];

        foreach ($list as $rule) {
            if ($rule instanceof Closure) {
                $parsed[] = $rule;
                continue;
            }

            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            // Split on the first colon only: a regex argument is full of them.
            [$name, $arguments] = str_contains($rule, ':')
                ? explode(':', $rule, 2)
                : [$rule, ''];

            $parsed[] = [
                'name' => strtolower(trim($name)),
                'args' => $arguments === '' ? [] : array_map('trim', explode(',', $arguments)),
            ];
        }

        return $parsed;
    }

    /**
     * @param array<int, string> $args
     */
    private function message(string $field, string $rule, array $args, mixed $value = null): string {
        // Most specific first: a message for this field and rule, then one for
        // the rule anywhere, then the built-in wording.
        $custom = $this->messages[$field . '.' . $rule] ?? $this->messages[$rule] ?? null;
        if ($custom !== null) {
            return $this->interpolate($custom, $field, $args);
        }

        return $this->interpolate(Messages::for($rule, $this->typeOf($value)), $field, $args);
    }

    /**
     * How a size-based message should read about this value: characters for a
     * string, items for an array, the bare number otherwise.
     */
    private function typeOf(mixed $value): string {
        return match (true) {
            is_numeric($value) => '',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => '',
        };
    }

    /**
     * @param array<int, string> $args
     */
    private function interpolate(string $template, string $field, array $args): string {
        $replacements = [
            ':field' => str_replace('_', ' ', $field),
            ':value' => (string) ($args[0] ?? ''),
            ':min' => (string) ($args[0] ?? ''),
            ':max' => (string) ($args[1] ?? $args[0] ?? ''),
            ':values' => implode(', ', $args),
        ];

        return strtr($template, $replacements);
    }
}
