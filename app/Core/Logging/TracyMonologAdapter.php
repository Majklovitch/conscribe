<?php

namespace App\Core\Logging;

use Psr\Log\LoggerInterface;
use Throwable;
use Tracy\ILogger;

/**
 * Bridges Tracy's logger onto the application logger.
 *
 * In development Tracy owns the PHP handlers (that is the only way the debug
 * bar can work), so without this adapter everything it catches would land in
 * its own file format - or nowhere. Everything it reports is passed on to
 * Monolog instead, so the log looks the same in both environments.
 */
class TracyMonologAdapter implements ILogger {
    private ?LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger null = default logger from the configuration (lazily)
     */
    public function __construct(?LoggerInterface $logger = null) {
        $this->logger = $logger;
    }

    /**
     * @param mixed  $value a message, or the exception itself
     * @param string $level one of the ILogger constants
     */
    public function log(mixed $value, string $level = self::INFO): void {
        $psrLevel = $this->toPsrLevel((string) $level);

        // Through ErrorHandler, so an exception Tracy reports both to its own
        // logger and to the wrapped handler produces a single log line.
        if ($value instanceof Throwable) {
            ErrorHandler::logException($value, $psrLevel);

            return;
        }

        $this->logger()->log($psrLevel, is_scalar($value) ? (string) $value : print_r($value, true));
    }

    /**
     * Tracy levels onto PSR-3. Tracy's EXCEPTION and ERROR are both an
     * application failure, so both map onto 'error'; only CRITICAL is worse.
     */
    private function toPsrLevel(string $level): string {
        return match ($level) {
            ILogger::CRITICAL  => 'critical',
            ILogger::EXCEPTION,
            ILogger::ERROR     => 'error',
            ILogger::WARNING   => 'warning',
            ILogger::DEBUG     => 'debug',
            default            => 'info',
        };
    }

    /**
     * Built on first use only - a request that logs nothing must not pull in
     * Monolog, the same way ErrorHandler and ErrorPage do it.
     */
    private function logger(): LoggerInterface {
        return $this->logger ??= Log::get();
    }
}
