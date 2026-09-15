<?php

namespace App\Core\Database;

use PDO;

/**
 * A database connection opened on first use.
 *
 * Lazily, so injecting the class that uses it opens nothing: a page that only
 * asks whether anyone is signed in must not connect to the database at all.
 * A connection is injected only in tests; otherwise it comes from Connection.
 */
trait LazyConnection {
    private ?PDO $db = null;

    protected function db(): PDO {
        return $this->db ??= Connection::get();
    }
}
