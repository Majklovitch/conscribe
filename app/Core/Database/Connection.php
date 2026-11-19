<?php

namespace App\Core\Database;

use App\Core\Config;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class Connection {
    private static ?PDO $pdo = null;

    /**
     * Nesting depth per connection, so an inner transaction() knows to use a
     * savepoint instead of a second BEGIN - which PDO refuses outright. Keyed
     * by the PDO object, because a test may well drive an injected connection
     * alongside the shared one.
     *
     * @var \SplObjectStorage<PDO, int>|null
     */
    private static ?\SplObjectStorage $transactionLevels = null;

    private const OPTIONS = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    public static function get(): PDO {
        if (self::$pdo === null) {
            self::$pdo = self::create();
        }

        return self::$pdo;
    }

    public static function set(?PDO $pdo): void {
        self::$pdo = $pdo;
        self::$transactionLevels = null;
    }

    public static function isConnected(): bool {
        return self::$pdo !== null;
    }

    /**
     * Runs the callback in a transaction: commit on return, roll back on any
     * throw, and the exception is rethrown either way. Nested calls become
     * savepoints, so a service that wraps two repository operations still gets
     * all-or-nothing behaviour.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     *
     * @throws Throwable whatever the callback threw
     */
    public static function transaction(callable $callback, ?PDO $pdo = null): mixed {
        $pdo ??= self::get();

        $levels = self::$transactionLevels ??= new \SplObjectStorage();
        $level = $levels[$pdo] ?? 0;

        $isOutermost = $level === 0 && !$pdo->inTransaction();
        $savepoint = 'conscribe_sp_' . $level;

        if ($isOutermost) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        $levels[$pdo] = $level + 1;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $levels[$pdo] = $level;

            if ($isOutermost) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }

            throw $e;
        }

        $levels[$pdo] = $level;

        if ($isOutermost) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }

        return $result;
    }

    /**
     * @throws RuntimeException
     */
    private static function create(): PDO {
        if (!is_file(Config::path())) {
            throw new RuntimeException(
                'Database configuration is missing: copy app/Config/main.example.php to app/Config/main.php.',
            );
        }

        $config = Config::get('db');
        if (!is_array($config)) {
            throw new RuntimeException("Configuration key 'db' is missing in app/Config/main.php.");
        }

        foreach (['host', 'dbname', 'user'] as $key) {
            if (!isset($config[$key])) {
                throw new RuntimeException("Configuration key 'db.{$key}' is missing in app/Config/main.php.");
            }
        }

        $charset = $config['charset'] ?? 'utf8mb4';
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$charset}";

        return self::connect($dsn, (string) $config['user'], (string) ($config['pass'] ?? ''));
    }

    /**
     * Opens the connection. A failure is rethrown without the DSN or the
     * password: the message ends up in the log, and the stack trace in Tracy's
     * saved blue screens, so #[\SensitiveParameter] keeps the password out of
     * both (PDO's own constructor marks it the same way). Visitors never see
     * any of it - production renders the generic 500 page.
     *
     * @throws RuntimeException
     */
    private static function connect(string $dsn, string $user, #[\SensitiveParameter] string $password): PDO {
        $class = self::pdoClass();

        try {
            return new $class($dsn, $user, $password, self::OPTIONS);
        } catch (PDOException $e) {
            // PDO's message names the server's complaint (access denied,
            // unknown host, missing driver) but never the password.
            throw new RuntimeException('Could not connect to the database: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * LoggingPdo while the query log is on, a plain PDO otherwise - so the
     * Tracy bar sees every statement and production pays nothing for it.
     *
     * @return class-string<PDO>
     */
    public static function pdoClass(): string {
        return QueryLog::isEnabled() ? LoggingPdo::class : PDO::class;
    }
}
