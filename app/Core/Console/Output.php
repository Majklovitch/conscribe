<?php

namespace App\Core\Console;

/**
 * Where a command writes.
 *
 * Streams are injected so a test can hand it two php://memory handles and read
 * back what a command printed; nothing here writes to STDOUT directly.
 *
 * Colour is decided once, at construction: on when the output is a terminal
 * that is not being redirected and NO_COLOR is unset. A command therefore never
 * has to ask - `$output->error(...)` is plain text in a pipe and red on screen,
 * with no branch at the call site.
 */
final class Output {
    private const COLOURS = [
        'red' => '0;31',
        'green' => '0;32',
        'yellow' => '0;33',
        'blue' => '0;34',
        'grey' => '0;90',
        'bold' => '1',
    ];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private bool $decorated;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null, ?bool $decorated = null) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->decorated = $decorated ?? $this->detectColourSupport();
    }

    public function write(string $text): void {
        fwrite($this->stdout, $text);
    }

    public function line(string $text = ''): void {
        $this->write($text . PHP_EOL);
    }

    /** A heading, so a wall of output has somewhere for the eye to land. */
    public function title(string $text): void {
        $this->line($this->colour($text, 'bold'));
    }

    public function success(string $text): void {
        $this->line($this->colour($text, 'green'));
    }

    public function warning(string $text): void {
        $this->line($this->colour($text, 'yellow'));
    }

    public function comment(string $text): void {
        $this->line($this->colour($text, 'grey'));
    }

    /** Errors go to stderr, so `tty something > file` still shows them. */
    public function error(string $text): void {
        fwrite($this->stderr, $this->colour($text, 'red') . PHP_EOL);
    }

    /**
     * Two aligned columns - the shape every `list`-style output wants.
     *
     * @param array<string, string> $rows
     */
    public function columns(array $rows, int $indent = 2): void {
        if ($rows === []) {
            return;
        }

        $width = max(array_map('strlen', array_keys($rows)));

        foreach ($rows as $left => $right) {
            $this->line(
                str_repeat(' ', $indent)
                . $this->colour(str_pad($left, $width), 'green')
                . '  ' . $right,
            );
        }
    }

    public function isDecorated(): bool {
        return $this->decorated;
    }

    private function colour(string $text, string $colour): string {
        if (!$this->decorated || !isset(self::COLOURS[$colour])) {
            return $text;
        }

        return "\033[" . self::COLOURS[$colour] . 'm' . $text . "\033[0m";
    }

    private function detectColourSupport(): bool {
        // https://no-color.org - honoured whatever its value, including empty.
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (!function_exists('stream_isatty')) {
            return false;
        }

        return @stream_isatty($this->stdout);
    }
}
