<?php

namespace App\Core\Database;

use PDO;
use PDOStatement;

/**
 * The statement class LoggingPdo installs: execute() reports to QueryLog with
 * the values that were actually bound.
 *
 * PDO creates it itself, so the constructor must stay protected and take the
 * arguments given in ATTR_STATEMENT_CLASS - none.
 */
class LoggingStatement extends PDOStatement {
    /** @var array<int|string, mixed> */
    private array $bound = [];

    protected function __construct() {
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        $this->bound[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    public function bindParam(
        int|string $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null,
    ): bool {
        // By reference, so the log shows the value at execute() time, which
        // is the point of bindParam().
        $this->bound[$param] = &$var;

        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function execute(?array $params = null): bool {
        $bindings = $params ?? self::positional($this->bound);

        return QueryLog::measure($this->queryString, $bindings, fn () => parent::execute($params));
    }

    /**
     * Positions 1..n as a plain list, so the panel shows [7, "a"] rather than
     * {"1": 7, "2": "a"}. Named parameters are left as they are.
     */
    private static function positional(array $bound): array {
        if ($bound === []) {
            return [];
        }

        // A copy of the values, not of the references bindParam() keeps -
        // otherwise every logged execution of a statement reused in a loop
        // would later show the variable's last value.
        $bound = array_map(static fn (mixed $value): mixed => $value, $bound);
        ksort($bound);

        return array_keys($bound) === range(1, count($bound)) ? array_values($bound) : $bound;
    }
}
