<?php

namespace App\Core\Database;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Základ pro přístup k jedné tabulce.
 *
 * Potomek nastaví $table a volitelně $model – pak se výsledky vrací
 * jako instance modelu místo holých polí.
 */
abstract class Repository {
    /**
     * Název tabulky.
     */
    protected string $table = '';

    /**
     * Třída modelu pro hydrataci výsledků; prázdné = vracet pole.
     *
     * @var class-string<Model>|string
     */
    protected string $model = '';

    protected string $primaryKey = 'id';

    private ?PDO $db = null;

    /**
     * Připojení se předává jen v testech; jinak se bere z Connection až při první dotazu.
     */
    public function __construct(?PDO $db = null) {
        $this->db = $db;
    }

    /**
     * Připojení k databázi (lazy – repozitář bez dotazu se nikam nepřipojuje).
     */
    protected function db(): PDO {
        return $this->db ??= Connection::get();
    }

    /**
     * Název tabulky s kontrolou, aby se nedal propašovat do SQL.
     */
    protected function table(): string {
        if ($this->table === '') {
            throw new RuntimeException(static::class . ' has no $table defined.');
        }

        return $this->quoteIdentifier($this->table);
    }

    /**
     * Identifikátory nelze bindovat, takže je držíme na whitelistu znaků.
     */
    protected function quoteIdentifier(string $name): string {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier '{$name}'.");
        }

        return '`' . $name . '`';
    }

    /**
     * Převede řádek na model, pokud je nastavený.
     */
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
     * Sestaví WHERE klauzuli z pole sloupec => hodnota.
     *
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
     * Sestaví ORDER BY z pole sloupec => ASC|DESC.
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

    /**
     * Spustí dotaz s parametry.
     */
    protected function run(string $sql, array $bindings = []): PDOStatement {
        $statement = $this->db()->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /**
     * Najde záznam podle primárního klíče.
     */
    public function find(mixed $id): Model|array|null {
        $row = $this->findRaw($id);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Najde záznam podle primárního klíče jako pole.
     */
    public function findRaw(mixed $id): ?array {
        $sql = 'SELECT * FROM ' . $this->table()
            . ' WHERE ' . $this->quoteIdentifier($this->primaryKey) . ' = ? LIMIT 1';

        $row = $this->run($sql, [$id])->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Vrátí všechny záznamy.
     *
     * @return array<int, Model|array>
     */
    public function all(array $order = []): array {
        return $this->hydrateAll($this->allRaw($order));
    }

    /**
     * Vrátí všechny záznamy jako pole.
     */
    public function allRaw(array $order = []): array {
        return $this->run('SELECT * FROM ' . $this->table() . $this->buildOrderBy($order))->fetchAll();
    }

    /**
     * Vrátí záznamy odpovídající podmínkám.
     *
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

    /**
     * První záznam odpovídající podmínkám.
     */
    public function first(array $conditions, array $order = []): Model|array|null {
        return $this->where($conditions, $order, 1)[0] ?? null;
    }

    /**
     * Počet záznamů (volitelně s podmínkami).
     */
    public function count(array $conditions = []): int {
        [$whereSql, $bindings] = $this->buildWhere($conditions);

        return (int) $this->run('SELECT COUNT(*) FROM ' . $this->table() . $whereSql, $bindings)->fetchColumn();
    }

    /**
     * Stránkování. Vrací data i metadata pro vykreslení stránkovače.
     *
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
     * Vloží záznam a vrátí jeho ID.
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
     * Upraví záznam podle primárního klíče. Vrací počet dotčených řádků.
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
     * Smaže záznam podle primárního klíče. Vrací počet dotčených řádků.
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
