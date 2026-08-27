<?php

namespace App\Core\Logging;

use App\Core\Config;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Access to the shared logger.
 *
 * The same shape as Config and Connection: a lazy static instance plus set()
 * for tests. The logger is built from the 'log' configuration; when that is
 * missing or cannot be written to, a NullLogger is returned - logging must
 * never bring the site down.
 */
class Log {
    private static ?LoggerInterface $logger = null;

    public static function get(): LoggerInterface {
        return self::$logger ??= self::create();
    }

    /**
     * Injects a custom logger (tests, or an application with its own setup).
     * null resets the instance, so it is built again next time.
     */
    public static function set(?LoggerInterface $logger): void {
        self::$logger = $logger;
    }

    private static function create(): LoggerInterface {
        if (Config::get('log.enabled', true) === false) {
            return new NullLogger();
        }

        $isDevelopment = Config::get('conscribe.environment', 'production') === 'development';
        $level = self::resolveLevel(Config::get('log.level'), $isDevelopment);

        // Format without an empty context and with newlines allowed, so the
        // stack trace stays readable in the output.
        $formatter = new LineFormatter(null, 'Y-m-d H:i:s', true, true);
        // Without a stack trace an exception record is useless.
        $formatter->includeStacktraces();

        try {
            $logger = new Logger((string) Config::get('log.name', 'conscribe'));

            $maxFiles = (int) Config::get('log.max_files', 14);
            $handler = new RotatingFileHandler(self::resolvePath(), $maxFiles, $level);
            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);

            // In a container, stderr is the only thing that `docker logs` picks up.
            if (Config::get('log.stderr', false) === true) {
                $stderr = new StreamHandler('php://stderr', $level);
                $stderr->setFormatter($formatter);
                $logger->pushHandler($stderr);
            }

            $logger->pushProcessor(new PsrLogMessageProcessor());
            $logger->pushProcessor(new WebProcessor());

            return $logger;
        } catch (Throwable $e) {
            // Typically a non-writable directory. The server log is what is left.
            error_log('Logger could not be initialized: ' . $e->getMessage());

            return new NullLogger();
        }
    }

    /**
     * Target file. A relative path from the configuration is resolved against
     * the project root.
     */
    private static function resolvePath(): string {
        $root = dirname(__DIR__, 3);
        $path = Config::get('log.path');

        if (!is_string($path) || trim($path) === '') {
            return $root . '/storage/logs/app.log';
        }

        $path = str_replace('\\', '/', trim($path));

        // An absolute path (Unix style as well as 'C:/...') is taken as it is.
        if (str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:/#', $path) === 1) {
            return $path;
        }

        return $root . '/' . ltrim($path, '/');
    }

    /**
     * In development we want to see everything; in production only what is
     * genuinely an error.
     */
    private static function resolveLevel(mixed $level, bool $isDevelopment): Level {
        if (is_string($level) && $level !== '') {
            try {
                return Logger::toMonologLevel($level);
            } catch (Throwable) {
                // A typo in the configuration must not disable logging; fall back
                // to the default.
            }
        }

        return $isDevelopment ? Level::Debug : Level::Warning;
    }
}
