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
            throw new RuntimeException(static::class . "::\$model must be a subclass of " . Model::class . '.');
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
        $statement->execute($bindings);

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

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);
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

    public function beginTransaction(): bool {
        return $this->db()->beginTransaction();
    }

    public function commit(): bool {
        return $this->db()->commit();
    }

    public function rollBack(): bool {
        return $this->db()->rollBack();
    }
}
