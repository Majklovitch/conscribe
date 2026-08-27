<?php

namespace App\Core\Logging;

use App\Core\Http\Request;
use App\Core\Http\Response;
use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;
use Whoops\Handler\JsonResponseHandler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

/**
 * Global handler for PHP errors, uncaught exceptions and fatal errors.
 *
 * The router only deals with the error states it produces itself (404, 405, and
 * a 500 from routing). Anything that fails inside a controller or outside the
 * router would otherwise end as a blank page - here it is logged and the same
 * error page is rendered.
 */
class ErrorHandler {
    /**
     * Errors after which PHP does not continue - catchable only at shutdown.
     */
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;

    private static bool $handled = false;

    private static ?LoggerInterface $logger = null;

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
        ?LoggerInterface $logger = null
    ): void {
        self::$logger = $logger;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Respect both error_reporting and the '@' operator.
            if (!(error_reporting() & $severity)) {
                return false;
            }

            self::logger()->log(self::severityToLevel($severity), $message, [
                'file'     => $file,
                'line'     => $line,
                'severity' => self::severityName($severity),
            ]);

            // false = PHP also handles the error its own way (display_errors in development).
            return false;
        });

        set_exception_handler(static function (Throwable $e) use ($isDevelopment, $renderer): void {
            self::$handled = true;

            self::logger()->critical('Uncaught {class}: {message}', [
                'class'     => $e::class,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'exception' => $e,
            ]);

            self::renderFailure($e, $isDevelopment, $renderer);
        });

        register_shutdown_function(static function () use ($isDevelopment, $renderer): void {
            $error = error_get_last();

            // An uncaught exception was already dealt with by the handler above;
            // it would land here a second time.
            if (self::$handled || $error === null || !($error['type'] & self::FATAL)) {
                return;
            }

            self::logger()->alert($error['message'], [
                'file'     => $error['file'],
                'line'     => $error['line'],
                'severity' => self::severityName($error['type']),
            ]);

            $fatal = new ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            );

            self::renderFailure($fatal, $isDevelopment, $renderer);
        });
    }

    /**
     * Restores the PHP handlers to their default state (tests).
     */
    public static function unregister(): void {
        restore_error_handler();
        restore_exception_handler();
        self::$handled = false;
        self::$logger = null;
    }

    /**
     * Renders a 500. Any output in progress is discarded so the error page does
     * not get glued onto half of an unfinished template.
     */
    private static function renderFailure(Throwable $e, bool $isDevelopment, ?callable $renderer): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($isDevelopment) {
            self::debugPage($e)->send();

            return;
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
     * Developer error page.
     *
     * Whoops is a dev dependency; production installs with --no-dev and does not
     * have it, so it is used only when genuinely available. It runs purely as a
     * renderer (allowQuit + writeToOutput off) - it is not allowed to register
     * its own handlers, which would override the logging into Monolog.
     */
    private static function debugPage(Throwable $e): Response {
        if (class_exists(Run::class)) {
            $whoops = new Run();
            $whoops->allowQuit(false);
            $whoops->writeToOutput(false);
            $whoops->sendHttpCode(false);

            [$handler, $contentType] = self::debugHandler();
            $whoops->pushHandler($handler);

            return new Response($whoops->handleException($e), 500, ['Content-Type' => $contentType]);
        }

        return new Response(
            sprintf("%s: %s\nin %s:%d\n\n%s", $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()),
            500,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }

    /**
     * Picks the Whoops handler based on who is asking. An HTML page is useless
     * both in a fetch() call and in the console.
     *
     * @return array{0: \Whoops\Handler\HandlerInterface, 1: string}
     */
    private static function debugHandler(): array {
        if (PHP_SAPI === 'cli') {
            $handler = new PlainTextHandler();
            $handler->addTraceToOutput(true);

            return [$handler, 'text/plain; charset=utf-8'];
        }

        $request = Request::createFromGlobals();
        $wantsJson = $request->isAjax()
            || str_contains(strtolower($request->getHeader('accept', '') ?? ''), 'application/json');

        if ($wantsJson) {
            $handler = new JsonResponseHandler();
            $handler->addTraceToOutput(true);

            return [$handler, 'application/json; charset=utf-8'];
        }

        $handler = new PrettyPageHandler();
        $handler->setPageTitle('Conscribe - application error');
        $handler->addDataTable('Conscribe request', [
            'method' => $request->getMethod(),
            'path'   => '/' . $request->getPath(),
            'ip'     => $request->getClientIp(),
        ]);
        // Keep configuration passwords out of the trace.
        $handler->blacklist('_SERVER', 'DB_PASSWORD');
        $handler->blacklist('_ENV', 'DB_PASSWORD');

        return [$handler, 'text/html; charset=utf-8'];
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
