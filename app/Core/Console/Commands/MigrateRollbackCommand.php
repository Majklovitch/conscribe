<?php

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use App\Core\Database\Migrations\Migrator;

final class MigrateRollbackCommand extends Command {
    protected string $name = 'migrate:rollback';
    protected string $description = 'Undoes the most recent batch of migrations.';
    protected string $help = "Usage: php tty migrate:rollback\n\nUndoes the migrations of the last batch, newest first.";

    public function __construct(private Migrator $migrator) {
    }

    public function run(Input $input, Output $output): int {
        $rolledBack = $this->migrator->rollback(static function (string $name) use ($output): void {
            $output->line('  ' . $name);
        });

        if ($rolledBack === []) {
            $output->comment('Nothing to roll back.');

            return self::SUCCESS;
        }

        $output->success(sprintf('Rolled back %d migration(s).', count($rolledBack)));

        return self::SUCCESS;
    }
}
