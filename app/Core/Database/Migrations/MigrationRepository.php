<?php

namespace App\Core\Database\Migrations;

use PDO;

/**
 * The bookkeeping table: which migrations have run, and in which batch.
 *
 * Batches are what make `rollback` mean something useful. Everything applied by
 * one `migrate` run shares a batch number, so rolling back undoes that run
 * rather than one arbitrary file - which is almost always what you want after a
 * deploy went wrong.
 *
 * The DDL here is deliberately portable: VARCHAR/INTEGER/DATETIME and nothing
 * else, so the same runner works against MySQL in production and SQLite in the
 * tests.
 */
final class MigrationRepository {
    public const TABLE = 'migrations';

    public function __construct(private PDO $db) {
    }

    /**
     * Creates the bookkeeping table if it is not there yet.
     *
     * Called before every operation rather than being a migration of its own:
     * a migration that records migrations would have to record itself.
     */
    public function ensureExists(): void {
        $this->db->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at DATETIME NOT NULL,
                PRIMARY KEY (migration)
            )',
            self::TABLE,
        ));
    }

    /**
     * Every migration that has run, oldest first.
     *
     * @return array<int, string>
     */
    public function applied(): array {
        $statement = $this->db->query(
            sprintf('SELECT migration FROM %s ORDER BY batch ASC, migration ASC', self::TABLE),
        );

        return $statement === false ? [] : array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The migrations of the most recent batch, newest first - the order a
     * rollback has to undo them in.
     *
     * @return array<int, string>
     */
    public function lastBatch(): array {
        $batch = $this->lastBatchNumber();

        if ($batch === 0) {
            return [];
        }

        $statement = $this->db->prepare(
            sprintf('SELECT migration FROM %s WHERE batch = ? ORDER BY migration DESC', self::TABLE),
        );
        $statement->execute([$batch]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function lastBatchNumber(): int {
        $statement = $this->db->query(sprintf('SELECT MAX(batch) FROM %s', self::TABLE));
        $batch = $statement === false ? null : $statement->fetchColumn();

        return $batch === false || $batch === null ? 0 : (int) $batch;
    }

    public function nextBatchNumber(): int {
        return $this->lastBatchNumber() + 1;
    }

    public function log(string $migration, int $batch): void {
        $statement = $this->db->prepare(
            sprintf('INSERT INTO %s (migration, batch, ran_at) VALUES (?, ?, ?)', self::TABLE),
        );
        $statement->execute([$migration, $batch, date('Y-m-d H:i:s')]);
    }

    public function forget(string $migration): void {
        $statement = $this->db->prepare(sprintf('DELETE FROM %s WHERE migration = ?', self::TABLE));
        $statement->execute([$migration]);
    }
}
