<?php

use App\Core\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(PDO $db): void {
        // The one non-portable piece of DDL: an auto-incrementing key is
        // spelled differently by every database. Everything else here is plain
        // SQL, so the same file runs on MySQL in production and SQLite in the
        // tests.
        $id = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';

        $db->exec("CREATE TABLE users (
            id {$id},
            email VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            name VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )");

        $db->exec('CREATE UNIQUE INDEX users_email_unique ON users (email)');
    }

    public function down(PDO $db): void {
        $db->exec('DROP TABLE users');
    }
};
