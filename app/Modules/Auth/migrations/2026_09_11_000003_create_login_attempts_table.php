<?php

use App\Core\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(PDO $db): void {
        // The key is a SHA-256 of email and address, so the table holds no
        // email addresses. Times are Unix seconds rather than DATETIME, which
        // keeps the arithmetic in PHP and identical on every database.
        $db->exec('CREATE TABLE login_attempts (
            throttle_key CHAR(64) NOT NULL PRIMARY KEY,
            attempts INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        )');
    }

    public function down(PDO $db): void {
        $db->exec('DROP TABLE login_attempts');
    }
};
