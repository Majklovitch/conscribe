<?php

namespace App\Core\Database;

use PDO;
use PDOStatement;

/**
 * A database connection opened on first use.
 *
 * Lazily, so injecting the class that uses it opens nothing: a page that only
 * asks whether anyone is signed in must not connect to the database at all.
 * A connection is injected only in tests; otherwise it comes from Connection.
 *
 * run() is the way SQL should be sent from a class using this trait: it binds
 * values by type, so an int in LIMIT reaches MySQL as an int.
 */
trait LazyConnection {
    private ?PDO $db = null;

    protected function db(): PDO {
        return $this->db ??= Connection::get();
    }

    protected function run(string $sql, array $bindings = []): PDOStatement {
        $statement = $this->db()->prepare($sql);

        // Named placeholders (':id' => ...) are handed to execute() unchanged -
        // they cannot be bound by position, and a subclass writing its own SQL
        // is free to use them.
        if ($bindings !== [] && !array_is_list($bindings)) {
            $statement->execute($bindings);
            $statement->setFetchMode(PDO::FETCH_ASSOC);

            return $statement;
        }

        // Positional bindings are bound one by one rather than through
        // execute($bindings), because execute() sends every value as a string -
        // and MySQL rejects a string in LIMIT/OFFSET once emulated prepares are
        // off, which they are.
        foreach ($bindings as $index => $value) {
            $statement->bindValue(
                $index + 1,
                $value,
                match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                },
            );
        }

        $statement->execute();

        // Named columns only, whatever the connection's default fetch mode is.
        // PDO's own default is FETCH_BOTH, which returns every column twice -
        // once by name and once by position - and Model keeps what it does not
        // recognise, so the positional copies end up in its bag of attributes
        // and from there in toArray() and the JSON. A column deliberately kept
        // out of a model, a password hash above all, would be published by its
        // numeric twin. Connection sets ASSOC for the application's own handle;
        // this is for every other one, which Repository explicitly allows.
        $statement->setFetchMode(PDO::FETCH_ASSOC);

        return $statement;
    }
}
