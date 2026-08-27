<?php
namespace App\Core\Database;

use App\Core\Config;
use PDO;
use RuntimeException;

class Connection {
    private static ?PDO $pdo = null;

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
    }

    public static function isConnected(): bool {
        return self::$pdo !== null;
    }

    /**
     * @throws RuntimeException
     */
    private static function create(): PDO {
        if (!is_file(Config::path())) {
            throw new RuntimeException(
                'Database configuration is missing: copy app/Config/main.example.php to app/Config/main.php.'
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
