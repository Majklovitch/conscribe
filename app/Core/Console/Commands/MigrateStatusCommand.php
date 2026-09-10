<?php

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use App\Core\Database\Migrations\Migrator;

final class MigrateStatusCommand extends Command {
    protected string $name = 'migrate:status';
    protected string $description = 'Shows which migrations have run.';

    public function __construct(private Migrator $migrator) {
    }

    public function run(Input $input, Output $output): int {
        $status = $this->migrator->status();

        if ($status === []) {
            $output->comment('There are no migrations.');

            return self::SUCCESS;
        }

        $output->columns($status);

        // "missing" means the table records a migration whose file is gone -
        // a branch that ran on this database and was then deleted. Worth a
        // non-zero exit so a deploy script notices the drift.
        $missing = count(array_filter($status, static fn (string $state): bool => $state === 'missing'));
        if ($missing > 0) {
            $output->line();
            $output->warning(sprintf('%d migration(s) recorded but no longer present as files.', $missing));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
