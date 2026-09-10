<?php

namespace App\Core\Database;

use App\Core\Config;
use PDO;
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

        return new PDO($dsn, $config['user'], $config['pass'] ?? '', self::OPTIONS);
    }
}
