<?php

namespace App\Core\Database;

/**
 * The SQL a request ran, for the Tracy bar.
 *
 * Off unless something turns it on - Debug does, where the bar is shown - so
 * production neither keeps the queries in memory nor pays for timing them.
 *
 * Fed by LoggingPdo, which Connection builds while the log is on. The log must
 * therefore be on before the first query opens the connection; Bootstrap
 * enables Debug long before that.
 */
final class QueryLog {
    /**
     * A long import in one request must not grow memory without bound; the
     * count keeps going so the panel can still say how many there were.
     */
    public const LIMIT = 500;

    private static bool $enabled = false;

    /** @var array<int, array{sql: string, bindings: array, ms: float, error: ?string}> */
    private static array $queries = [];

    private static int $count = 0;

    private static float $totalMs = 0.0;

    public static function enable(bool $enabled = true): void {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool {
        return self::$enabled;
    }

    /**
     * Runs a query and records it with its time. A failed query is recorded
     * too - it is the one worth seeing in the bar - and the exception is
     * rethrown on to the blue screen.
     *
     * @template T
     * @param callable(): T $query
     * @return T
     */
    public static function measure(string $sql, array $bindings, callable $query): mixed {
        if (!self::$enabled) {
            return $query();
        }

        $started = hrtime(true);
        $error = null;

        try {
            return $query();
        } catch (\Throwable $e) {
            $error = $e->getMessage();

            throw $e;
        } finally {
            self::record($sql, $bindings, (hrtime(true) - $started) / 1e6, $error);
        }
    }

    public static function record(string $sql, array $bindings, float $ms, ?string $error = null): void {
        if (!self::$enabled) {
            return;
        }

        self::$count++;
        self::$totalMs += $ms;

        if (count(self::$queries) < self::LIMIT) {
            self::$queries[] = ['sql' => $sql, 'bindings' => $bindings, 'ms' => $ms, 'error' => $error];
        }
    }

    /** @return array<int, array{sql: string, bindings: array, ms: float, error: ?string}> */
    public static function queries(): array {
        return self::$queries;
    }

    /** Every query recorded, including those past LIMIT. */
    public static function count(): int {
        return self::$count;
    }

    public static function totalMs(): float {
        return self::$totalMs;
    }

    /** Forgets the queries and turns the log off (tests). */
    public static function reset(): void {
        self::$enabled = false;
        self::$queries = [];
        self::$count = 0;
        self::$totalMs = 0.0;
    }
}
