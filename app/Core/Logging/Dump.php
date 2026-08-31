<?php

namespace App\Core\Logging;

use App\Core\Config;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;
use Tracy\Logger as TracyLogger;

/**
 * Tracy blue screens written to disk, next to the Monolog log.
 *
 * A log line says what broke; a dump says why. It is the same interactive page
 * the browser shows in development - source excerpt, the whole trace with the
 * local variables, the request state - only saved as a file, so a failure in
 * production can be examined afterwards instead of guessed at from a message.
 *
 * The filename lands in the log record as the 'dump' key, so the text line
 * points at the page belonging to it.
 */
class Dump {
    /**
     * Keys whose values are never printed. Tracy matches them whole and
     * case-insensitively; its own defaults already cover 'password' and 'pass'.
     */
    private const array SECRETS = ['DB_PASSWORD', 'DB_PASS', 'APP_SECRET', 'secret', 'token', 'api_key'];

    private static bool $configured = false;

    /**
     * How often the directory is swept for expired dumps.
     */
    private const int PRUNE_INTERVAL = 86400;

    /**
     * Writes the blue screen for $e.
     *
     * @return string|null the file name (without the directory), or null when
     *                     dumps are off, Tracy is missing or the write failed
     */
    public static function write(Throwable $e): ?string {
        if (Config::get('log.dumps', true) !== true || !class_exists(Debugger::class)) {
            return null;
        }

        try {
            $directory = self::directory();

            // Created on the first failure, the way the log directory is
            // expected to exist: an installation that never breaks never grows
            // an empty folder. A failure here is not reported - a read-only
            // deployment simply gets no dumps.
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                return null;
            }

            if (!is_writable($directory)) {
                return null;
            }

            self::configureBlueScreen();

            // Tracy's own naming: exception--Y-m-d--H-i--<hash>.html, where the
            // hash comes from the trace - an error repeating a thousand times
            // returns the file that is already there instead of a thousand
            // copies of it. Only the name is taken from Tracy's logger; its
            // log() would write a second text log and send email, which is
            // Monolog's job.
            $file = (new TracyLogger($directory))->getExceptionFile($e, ILogger::EXCEPTION);

            // renderToFile() opens the file exclusively, so it also returns
            // false when the dump is already there - from an earlier request or
            // from a parallel one that got in first. That is a success as far
            // as the log line is concerned, hence the second check.
            if (!Debugger::getBlueScreen()->renderToFile($e, $file) && !is_file($file)) {
                return null;
            }

            self::prune($directory);

            return basename($file);
        } catch (Throwable) {
            // The dump is a convenience. Failing to write it must never turn a
            // handled error into a second, unhandled one.
            return null;
        }
    }

    /**
     * Hides configuration secrets from the dumps.
     *
     * It lives here and not in Debug because the blue screen is also rendered
     * in production, where Debug never runs - the masking must not depend on
     * the debug bar being on.
     */
    public static function configureBlueScreen(): void {
        if (self::$configured || !class_exists(Debugger::class)) {
            return;
        }

        self::$configured = true;

        Debugger::$keysToHide = array_merge(Debugger::$keysToHide, self::SECRETS);
        $blueScreen = Debugger::getBlueScreen();
        $blueScreen->keysToHide = array_merge($blueScreen->keysToHide, self::SECRETS);
    }

    /**
     * Where the dumps go: 'log.dumps_path' when set, otherwise an 'exceptions'
     * directory beside the log one - a dump is a page, not a log line, and a
     * directory holding one file per failure would otherwise bury the logs.
     */
    private static function directory(): string {
        $path = Config::get('log.dumps_path');

        return is_string($path) && trim($path) !== ''
            ? Log::resolve($path)
            : dirname(Log::directory()) . '/exceptions';
    }

    /**
     * Deletes dumps older than 'log.max_files' days - the same retention the
     * rotating log file uses; 0 keeps everything.
     *
     * Swept at most once a day: the request that is already failing must not
     * also pay for a directory scan.
     */
    private static function prune(string $directory): void {
        $days = (int) Config::get('log.max_files', 14);

        if ($days <= 0) {
            return;
        }

        $stamp = $directory . '/dumps-pruned';

        if (is_file($stamp) && (time() - (int) @filemtime($stamp)) < self::PRUNE_INTERVAL) {
            return;
        }

        if (@file_put_contents($stamp, '') === false) {
            // Cannot remember the sweep, so do not start one - it would run on
            // every single error.
            return;
        }

        $expired = time() - ($days * 86400);

        // Tracy writes the page and a Markdown transcript of it next to each
        // other; both belong to the same failure and expire with it. Two
        // patterns rather than GLOB_BRACE, which musl-based systems (Alpine)
        // do not have.
        $dumps = array_merge(glob($directory . '/*.html') ?: [], glob($directory . '/*.md') ?: []);

        foreach ($dumps as $dump) {
            if (@filemtime($dump) < $expired) {
                @unlink($dump);
            }
        }
    }
}
