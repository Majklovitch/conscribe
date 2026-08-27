<?php
namespace App\Core\Database;

use App\Core\Config;
use PDO;
use RuntimeException;

/**
 * Drží jedno PDO připojení pro celý request a stará se o jeho vytvoření z konfigurace.
 */
class Connection {
    private static ?PDO $pdo = null;

    private const OPTIONS = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /**
     * Vrátí připojení; při prvním volání ho vytvoří podle app/Config/main.php.
     *
     * @throws RuntimeException pokud konfigurace chybí nebo je neúplná
     */
    public static function get(): PDO {
        if (self::$pdo === null) {
            self::$pdo = self::create();
        }

        return self::$pdo;
    }

    /**
     * Nastaví (nebo zahodí) připojení – používá se v testech, např. pro SQLite v paměti.
     */
    public static function set(?PDO $pdo): void {
        self::$pdo = $pdo;
    }

    /**
     * Bylo připojení už navázáno?
     */
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
