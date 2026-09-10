<?php

namespace App\Core\Console;

/**
 * The command line, parsed.
 *
 * Deliberately small. It understands the shapes a command actually receives -
 * `--env=production`, `--watch`, `-v`, and bare arguments - and nothing else.
 * getopt() was the alternative and was rejected: it needs the option list
 * declared up front, cannot see arguments that follow options, and returns
 * false in ways that are hard to tell from a legitimately empty value.
 *
 *   tty build --env=production -v assets
 *   => arguments: ['assets'], options: ['env' => 'production', 'v' => true]
 *
 * Everything after a bare `--` is an argument, whatever it looks like, so a
 * value that begins with a dash can still be passed.
 */
final class Input {
    /** @var array<int, string> */
    private array $arguments;

    /** @var array<string, string|bool> */
    private array $options;

    /**
     * @param array<int, string>          $arguments
     * @param array<string, string|bool>  $options
     */
    public function __construct(array $arguments = [], array $options = []) {
        $this->arguments = array_values($arguments);
        $this->options = $options;
    }

    /**
     * Parses a raw argv, without the script name and without the command name.
     *
     * @param array<int, string> $argv
     */
    public static function fromArgv(array $argv): self {
        $arguments = [];
        $options = [];
        $literal = false;

        foreach ($argv as $token) {
            if ($literal) {
                $arguments[] = $token;
                continue;
            }

            if ($token === '--') {
                $literal = true;
                continue;
            }

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
                [$name, $value] = str_contains($token, '=')
                    ? explode('=', $token, 2)
                    : [$token, true];

                $options[$name] = $value;
                continue;
            }

            // -v, or -abc as three flags; a short option never takes a value,
            // which is what keeps `-v foo` unambiguous.
            if (str_starts_with($token, '-') && $token !== '-') {
                foreach (str_split(substr($token, 1)) as $letter) {
                    $options[$letter] = true;
                }
                continue;
            }

            $arguments[] = $token;
        }

        return new self($arguments, $options);
    }

    /** Positional argument by index. */
    public function argument(int $position, ?string $default = null): ?string {
        return $this->arguments[$position] ?? $default;
    }

    /** @return array<int, string> */
    public function arguments(): array {
        return $this->arguments;
    }

    /**
     * An option's value. A flag given without a value reads as true, so
     * option('watch') distinguishes "absent" (null) from "given" (true).
     */
    public function option(string $name, string|bool|null $default = null): string|bool|null {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool {
        return array_key_exists($name, $this->options);
    }

    /** @return array<string, string|bool> */
    public function options(): array {
        return $this->options;
    }
}
