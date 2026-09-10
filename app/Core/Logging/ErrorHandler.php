<?php

namespace App\Core\Logging;

use App\Core\Http\Request;
use App\Core\Http\Response;
use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Global handler for PHP errors, uncaught exceptions and fatal errors.
 *
 * The router only deals with the error states it produces itself (404, 405, and
 * a 500 from routing). Anything that fails inside a controller or outside the
 * router would otherwise end as a blank page - here it is logged and the same
 * error page is rendered.
 *
 * In development this whole class steps aside: Debug::enable() hands the
 * handlers to Tracy, which needs them for its blue screen and debug bar, and
 * logs through TracyMonologAdapter into the very same logger.
 */
class ErrorHandler {
    /**
     * Errors after which PHP does not continue - catchable only at shutdown.
     * Public because Debug watches for the same set in development.
     */
    public const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;

    private static bool $handled = false;

    private static ?LoggerInterface $logger = null;

    /**
     * Exceptions already written to the log. Tracy can reach the same throwable
     * from two directions (the handler below and its own Debugger::log()), and
     * one failure deserves one log line.
     *
     * @var \WeakMap<\Throwable, true>|null
     */
    private static ?\WeakMap $logged = null;

    /**
     * The logger is held lazily: nothing is logged on a successful request, so
     * there is no reason to pull in Monolog on every page call. It is built
     * only once there is genuinely something to write.
     */
    private static function logger(): LoggerInterface {
        return self::$logger ??= Log::get();
    }

    /**
     * @param callable|null        $renderer fn(int $code, string $message, Request $request): Response
     * @param LoggerInterface|null $logger   null = default logger from the configuration (lazily)
     */
    public static function register(
        bool $isDevelopment = false,
        ?callable $renderer = null,
        ?LoggerInterface $logger = null,
    ): void {
        self::$logger = $logger;

        // Tracy owns the handlers in development - registering ours on top
        // would only log everything a second time.
        if (Debug::enable($isDevelopment, $logger)) {
            return;
        }

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Respect both error_reporting and the '@' operator.
            if (!(error_reporting() & $severity)) {
                return false;
            }

            self::logPhpError($severity, $message, $file, $line);

            // false = PHP also handles the error its own way (display_errors in development).
            return false;
        });

        set_exception_handler(static function (Throwable $e) use ($renderer): void {
            self::$handled = true;

            self::logException($e);

            self::renderFailure($renderer);
        });

        register_shutdown_function(static function () use ($renderer): void {
            $error = error_get_last();

            // An uncaught exception was already dealt with by the handler above;
            // it would land here a second time.
            if (self::$handled || $error === null || !($error['type'] & self::FATAL)) {
                return;
            }

            self::logFatal($error);

            self::renderFailure($renderer);
        });
    }

    /**
     * Writes an uncaught exception to the log.
     *
     * Public because in development the handlers belong to Tracy - Debug wraps
     * them and calls in here, so the log line looks the same in both
     * environments. Logging the same throwable twice is a no-op.
     *
     * The Tracy blue screen for the failure is saved next to the log and its
     * file name goes into the record as 'dump', so the text line points at the
     * page with the source, the trace and the variables.
     */
    public static function logException(Throwable $e, string $level = 'critical'): void {
        self::$logged ??= new \WeakMap();

        if (isset(self::$logged[$e])) {
            return;
        }

        self::$logged[$e] = true;

        $context = [
            'class'     => $e::class,
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'exception' => $e,
        ];

        if (($dump = Dump::write($e)) !== null) {
            $context['dump'] = $dump;
        }

        self::logger()->log($level, 'Uncaught {class}: {message}', $context);
    }

    /**
     * Writes a PHP error (warning, notice, deprecation) to the log.
     */
    public static function logPhpError(int $severity, string $message, string $file, int $line): void {
        self::logger()->log(self::severityToLevel($severity), $message, [
            'file'     => $file,
            'line'     => $line,
            'severity' => self::severityName($severity),
        ]);
    }

    /**
     * Writes a fatal error - the one PHP does not survive - to the log.
     *
     * There is no throwable to render at this point, so one is rebuilt from
     * what PHP reported - purely to get a blue screen out of it. The record
     * itself keeps the raw message: 'Uncaught ErrorException' would be a lie
     * about a fatal such as an exceeded execution time.
     *
     * @param array{type: int, message: string, file: string, line: int} $error from error_get_last()
     */
    public static function logFatal(array $error): void {
        $context = [
            'file'     => $error['file'],
            'line'     => $error['line'],
            'severity' => self::severityName($error['type']),
        ];

        $fatal = new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line'],
        );

        if (($dump = Dump::write($fatal)) !== null) {
            $context['dump'] = $dump;
        }

        self::logger()->alert($error['message'], $context);
    }

    /**
     * Restores the PHP handlers to their default state (tests).
     */
    public static function unregister(): void {
        restore_error_handler();
        restore_exception_handler();
        self::$handled = false;
        self::$logger = null;
        self::$logged = null;
    }

    /**
     * Renders a 500. Any output in progress is discarded so the error page does
     * not get glued onto half of an unfinished template.
     *
     * Only the production path lives here - in development the request never
     * reaches this method, Tracy renders the blue screen instead.
     */
    private static function renderFailure(?callable $renderer): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Rendering the error page can itself fail (unreachable DB, missing
        // template). A bare response must then remain, not an empty page.
        if ($renderer !== null) {
            try {
                $response = $renderer(500, 'Internal server error.', Request::createFromGlobals());
                if ($response instanceof Response) {
                    $response->send();

                    return;
                }
            } catch (Throwable) {
                // fallthrough
            }
        }

        (new Response('Error 500: Internal server error.', 500, ['Content-Type' => 'text/plain; charset=utf-8']))->send();
    }

    /**
     * Maps PHP error levels onto PSR-3.
     */
    private static function severityToLevel(int $severity): string {
        return match ($severity) {
            E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'error',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR => 'warning',
            default => 'notice',
        };
    }

    private static function severityName(int $severity): string {
        return match ($severity) {
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE',
            E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
            default             => 'E_UNKNOWN',
        };
    }
}
