<?php

namespace App\Core\Database;

use PDO;
use PDOStatement;

/**
 * A PDO that reports every statement to QueryLog.
 *
 * Recording at the connection rather than in Repository is what makes the
 * Tracy bar complete: migrations, Connection::transaction(), classes using
 * db() directly and hand-written queries all go through here.
 *
 * Connection builds one only while QueryLog is on - in development, where the
 * bar is shown - so production gets a plain PDO and pays nothing.
 */
class LoggingPdo extends PDO {
    /**
     * The password is marked sensitive as PDO's own is: a failed connection
     * throws from here, and without the attribute this frame would print the
     * password into every stack trace, log line and dump of that exception.
     */
    public function __construct(
        string $dsn,
        ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        ?array $options = null,
    ) {
        parent::__construct($dsn, $username, $password, $options);

        // Every prepare() and query() hands back a statement that records its
        // own execute().
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggingStatement::class, []]);
    }

    /**
     * A statement is recorded when it executes; one that fails already here
     * - MySQL with real prepares, SQLite always, reject a missing table at
     * prepare time - would otherwise never reach the log at all.
     */
    public function prepare(string $query, array $options = []): PDOStatement|false {
        try {
            return parent::prepare($query, $options);
        } catch (\Throwable $e) {
            QueryLog::record($query, [], 0.0, $e->getMessage());

            throw $e;
        }
    }

    public function exec(string $statement): int|false {
        return QueryLog::measure($statement, [], fn () => parent::exec($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return QueryLog::measure($query, [], fn () => $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs));
    }

    public function beginTransaction(): bool {
        return QueryLog::measure('BEGIN', [], fn () => parent::beginTransaction());
    }

    public function commit(): bool {
        return QueryLog::measure('COMMIT', [], fn () => parent::commit());
    }

    public function rollBack(): bool {
        return QueryLog::measure('ROLLBACK', [], fn () => parent::rollBack());
    }
}
