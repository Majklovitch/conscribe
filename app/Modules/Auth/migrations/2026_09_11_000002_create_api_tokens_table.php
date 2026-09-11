<?php

use App\Core\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(PDO $db): void {
        $id = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';

        // Only the SHA-256 of a token is stored. The plaintext is shown once,
        // when the token is issued, and a leaked copy of this table cannot be
        // replayed against the API.
        $db->exec("CREATE TABLE api_tokens (
            id {$id},
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            last_used_at DATETIME NULL,
            created_at DATETIME NOT NULL
        )");

        $db->exec('CREATE UNIQUE INDEX api_tokens_hash_unique ON api_tokens (token_hash)');
        $db->exec('CREATE INDEX api_tokens_user_index ON api_tokens (user_id)');
    }

    public function down(PDO $db): void {
        $db->exec('DROP TABLE api_tokens');
    }
};
