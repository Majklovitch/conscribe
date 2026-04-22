<?php

namespace App\Models;

use PDO;
use Exception;

abstract class BaseModel {
    protected static $db = null;
    protected $table;

    public function __construct() {
        if (self::$db === null) {
            $cfg = require dirname(__DIR__) . '/config.php';
            $c = $cfg['db'];
            $dsn = "mysql:host={$c['host']};dbname={$c['dbname']};charset={$c['charset']}";
            self::$db = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        }
    }

    public function find($id) {
        $stmt = self::$db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function all(): array {
        return self::$db->query("SELECT * FROM {$this->table}")->fetchAll();
    }
    public function count(): int {
        return self::$db->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
    }
}
