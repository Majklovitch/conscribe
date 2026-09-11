<?php

namespace App\Core\Database;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Base for accessing a single table.
 *
 * A subclass sets $table and optionally $model - results are then returned
 * as model instances instead of bare arrays.
 */
abstract class Repository {
    protected string $table = '';

    /**
     * Model class used to hydrate results; empty = return arrays.
     *
     * @var class-string<Model>|string
     */
    protected string $model = '';

    protected string $primaryKey = 'id';

    private ?PDO $db = null;

    /**
     * The connection is only injected in tests; otherwise it is taken from
     * Connection on the first query.
     */
    public function __construct(?PDO $db = null) {
        $this->db = $db;
    }

    /**
     * Database connection (lazy - a repository with no query connects nowhere).
     */
    protected function db(): PDO {
        return $this->db ??= Connection::get();
    }

    /**
     * Table name, validated so that nothing can be smuggled into the SQL.
     */
    protected function table(): string {
        if ($this->table === '') {
            throw new RuntimeException(static::class . ' has no $table defined.');
        }

        return $this->quoteIdentifier($this->table);
    }

    /**
     * Identifiers cannot be bound, so we hold them to a character whitelist.
     */
    protected function quoteIdentifier(string $name): string {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier '{$name}'.");
        }

        return '`' . $name . '`';
    }

    protected function hydrate(array $row): Model|array {
        if ($this->model === '') {
            return $row;
        }

        if (!is_subclass_of($this->model, Model::class)) {
            throw new RuntimeException(static::class . '::$model must be a subclass of ' . Model::class . '.');
        }

        /** @var Model $model */
        $model = new $this->model();

        return $model->fill($row);
    }

    /**
     * @param array<int, array> $rows
     * @return array<int, Model|array>
     */
    protected function hydrateAll(array $rows): array {
        return array_map(fn (array $row): Model|array => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $conditions column => value
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function buildWhere(array $conditions): array {
        if ($conditions === []) {
            return ['', []];
        }

        $parts = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $parts[] = $this->quoteIdentifier((string) $column) . ' IS NULL';
                continue;
            }

            $parts[] = $this->quoteIdentifier((string) $column) . ' = ?';
            $bindings[] = $value;
        }

        return [' WHERE ' . implode(' AND ', $parts), $bindings];
    }

    /**
     * @param array<string, string> $order column => ASC|DESC
     */
    protected function buildOrderBy(array $order): string {
        if ($order === []) {
            return '';
        }

        $parts = [];
        foreach ($order as $column => $direction) {
            $direction = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
            $parts[] = $this->quoteIdentifier((string) $column) . ' ' . $direction;
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    protected function run(string $sql, array $bindings = []): PDOStatement {
        $statement = $this->db()->prepare($sql);

        // Named placeholders (':id' => ...) are handed to execute() unchanged -
        // they cannot be bound by position, and a subclass writing its own SQL
        // is free to use them.
        if ($bindings !== [] && !array_is_list($bindings)) {
            $statement->execute($bindings);

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

    public function find(mixed $id): Model|array|null {
        $row = $this->findRaw($id);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findRaw(mixed $id): ?array {
        $sql = 'SELECT * FROM ' . $this->table()
            . ' WHERE ' . $this->quoteIdentifier($this->primaryKey) . ' = ? LIMIT 1';

        $row = $this->run($sql, [$id])->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, Model|array>
     */
    public function all(array $order = []): array {
        return $this->hydrateAll($this->allRaw($order));
    }

    public function allRaw(array $order = []): array {
        return $this->run('SELECT * FROM ' . $this->table() . $this->buildOrderBy($order))->fetchAll();
    }

    /**
     * @return array<int, Model|array>
     */
    public function where(array $conditions, array $order = [], ?int $limit = null, int $offset = 0): array {
        [$whereSql, $bindings] = $this->buildWhere($conditions);

        $sql = 'SELECT * FROM ' . $this->table() . $whereSql . $this->buildOrderBy($order);

        // Bound like any other value. The two are int-typed and would be safe
        // interpolated, but this is the only layer that touches SQL and it is
        // easier to keep the rule absolute than to remember the exception.
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $bindings[] = max(0, $limit);
            $bindings[] = max(0, $offset);
        }

        return $this->hydrateAll($this->run($sql, $bindings)->fetchAll());
    }

    public function first(array $conditions, array $order = []): Model|array|null {
        return $this->where($conditions, $order, 1)[0] ?? null;
    }

    public function count(array $conditions = []): int {
        [$whereSql, $bindings] = $this->buildWhere($conditions);

        return (int) $this->run('SELECT COUNT(*) FROM ' . $this->table() . $whereSql, $bindings)->fetchColumn();
    }

    /**
     * @return array{data: array<int, Model|array>, page: int, perPage: int, total: int, pages: int}
     */
    public function paginate(int $page = 1, int $perPage = 20, array $conditions = [], array $order = []): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = $this->count($conditions);

        return [
            'data'    => $this->where($conditions, $order, $perPage, ($page - 1) * $perPage),
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $total,
            'pages'   => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @return string ID of the inserted record
     */
    public function insert(array $data): string {
        if ($data === []) {
            throw new InvalidArgumentException('Nothing to insert.');
        }

        $columns = array_map(fn (string $c): string => $this->quoteIdentifier($c), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = 'INSERT INTO ' . $this->table()
            . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

        $this->run($sql, array_values($data));

        return $this->db()->lastInsertId();
    }

    /**
     * @return int number of affected rows
     */
    public function update(mixed $id, array $data): int {
        if ($data === []) {
            return 0;
        }

        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = $this->quoteIdentifier((string) $column) . ' = ?';
        }

        $sql = 'UPDATE ' . $this->table() . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $this->quoteIdentifier($this->primaryKey) . ' = ?';

        $bindings = array_values($data);
        $bindings[] = $id;

        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * @return int number of affected rows
     */
    public function delete(mixed $id): int {
        $sql = 'DELETE FROM ' . $this->table()
            . ' WHERE ' . $this->quoteIdentifier($this->primaryKey) . ' = ?';

        return $this->run($sql, [$id])->rowCount();
    }

    /**
     * Runs the callback inside a transaction, committing on return and rolling
     * back on any throw. Nesting is handled with savepoints, so two
     * repositories sharing the connection cannot deadlock each other the way
     * two bare beginTransaction() calls would.
     *
     * @template T
     * @param callable(static): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed {
        return Connection::transaction(fn (): mixed => $callback($this), $this->db());
    }

    /** @deprecated Use transaction(); a bare begin cannot nest. */
    public function beginTransaction(): bool {
        return $this->db()->beginTransaction();
    }

    /** @deprecated Use transaction(). */
    public function commit(): bool {
        return $this->db()->commit();
    }

    /** @deprecated Use transaction(). */
    public function rollBack(): bool {
        return $this->db()->rollBack();
    }
}
