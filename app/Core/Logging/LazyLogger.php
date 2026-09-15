<?php

namespace App\Core\Logging;

use Psr\Log\LoggerInterface;

/**
 * A logger built on first use, so constructing the class that uses it stays free.
 * Null means the default logger from the configuration.
 */
trait LazyLogger {
    private ?LoggerInterface $logger = null;

    protected function logger(): LoggerInterface {
        return $this->logger ??= Log::get();
    }
}
