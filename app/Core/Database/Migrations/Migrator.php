<?php

namespace App\Core\Database\Migrations;

use PDO;
use Throwable;

/**
 * Finds migration files, runs the ones that have not run, and undoes them.
 *
 * Ordering is by filename, which is why the generated names begin with a
 * timestamp: it makes the order both obvious in a directory listing and stable
 * across machines, without a counter that two branches would both claim.
 *
 * **DDL is not transactional in MySQL.** A CREATE TABLE commits implicitly,
 * so a migration that half succeeds cannot be rolled back by the database, and
 * wrapping the run in a transaction would only make it look safe. The migrator
 * therefore records each migration as it finishes and stops at the first
 * failure, leaving everything before it applied - which is recoverable, because
 * you can see exactly where it stopped. Postgres and SQLite do support
 * transactional DDL; a migration that wants that guarantee can open a
 * transaction in up() itself.
 */
final class Migrator {
    private MigrationRepository $repository;

    /** @var array<int, string> */
    private array $paths;

    /** @param array<int, string>|string $paths directories holding migration files */
    public function __construct(
        private PDO $db,
        array|string $paths,
        ?MigrationRepository $repository = null,
    ) {
        $this->paths = (array) $paths;
        $this->repository = $repository ?? new MigrationRepository($db);
    }

    /**
     * Every migration file found, by name, in the order they must run.
     *
     * @return array<string, string> name => absolute path
     */
    public function available(): array {
        $found = [];

        foreach ($this->paths as $path) {
            foreach (glob(rtrim($path, '/\\') . '/*.php') ?: [] as $file) {
                $found[basename($file, '.php')] = $file;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Migrations that have not run yet, in order.
     *
     * @return array<string, string> name => absolute path
     */
    public function pending(): array {
        $this->repository->ensureExists();

        return array_diff_key($this->available(), array_flip($this->repository->applied()));
    }

    /**
     * The state of every migration, for `migrate:status`.
     *
     * Includes names recorded in the table whose file is gone - a migration
     * that ran on the server and was then deleted from the branch is exactly
     * the kind of drift a status command exists to show.
     *
     * @return array<string, string> name => 'applied' | 'pending' | 'missing'
     */
    public function status(): array {
        $this->repository->ensureExists();

        $available = $this->available();
        $applied = $this->repository->applied();

        $status = [];
        foreach ($available as $name => $file) {
            $status[$name] = in_array($name, $applied, true) ? 'applied' : 'pending';
        }

        foreach ($applied as $name) {
            if (!isset($available[$name])) {
                $status[$name] = 'missing';
            }
        }

        ksort($status);

        return $status;
    }

    /**
     * Runs every pending migration as one batch.
     *
     * $each is called with the name before each one, so a command can report
     * progress without the migrator knowing what output is.
     *
     * @param null|callable(string): void $each
     * @return array<int, string> the names that ran
     * @throws Throwable whatever a migration threw, after recording the ones before it
     */
    public function migrate(?callable $each = null): array {
        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = $this->repository->nextBatchNumber();
        $ran = [];

        foreach ($pending as $name => $file) {
            if ($each !== null) {
                $each($name);
            }

            $this->resolve($file)->up($this->db);

            // Recorded immediately rather than after the loop: if the next one
            // fails, this one really has been applied and the table must say so.
            $this->repository->log($name, $batch);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Undoes the most recent batch, newest migration first.
     *
     * @param null|callable(string): void $each
     * @return array<int, string> the names that were rolled back
     * @throws MigrationException when a recorded migration's file is gone
     */
    public function rollback(?callable $each = null): array {
        $this->repository->ensureExists();

        $batch = $this->repository->lastBatch();
        if ($batch === []) {
            return [];
        }

        $available = $this->available();
        $rolledBack = [];

        foreach ($batch as $name) {
            $file = $available[$name] ?? throw new MigrationException(sprintf(
                'Cannot roll back "%s": its file is gone, so there is nothing to undo it with.',
                $name,
            ));

            if ($each !== null) {
                $each($name);
            }

            $this->resolve($file)->down($this->db);

            $this->repository->forget($name);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    public function repository(): MigrationRepository {
        return $this->repository;
    }

    /**
     * Loads one migration file.
     *
     * @throws MigrationException when the file returns something else
     */
    private function resolve(string $file): Migration {
        try {
            $migration = require $file;
        } catch (\ParseError $e) {
            // Named, because "syntax error, unexpected token" with a line
            // number says nothing about which of a hundred migrations it is in.
            throw new MigrationException(
                sprintf('%s has a syntax error: %s', basename($file), $e->getMessage()),
                0,
                $e,
            );
        }

        if (!$migration instanceof Migration) {
            throw new MigrationException(sprintf(
                '%s must return a %s; got %s. A migration file ends with `return new class extends Migration { ... };`.',
                basename($file),
                Migration::class,
                get_debug_type($migration),
            ));
        }

        return $migration;
    }
}
