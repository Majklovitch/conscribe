<?php

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use App\Core\Database\Migrations\Migrator;

final class MigrateCommand extends Command {
    protected string $name = 'migrate';
    protected string $description = 'Runs every migration that has not run yet.';
    protected string $help = <<<'HELP'
        Usage: php tty migrate

        Everything applied by one run shares a batch number, so `migrate:rollback`
        undoes that run rather than one arbitrary file.

        Note that MySQL commits DDL implicitly: a failing migration cannot be undone
        by the database, so the run stops at the first failure and everything before
        it stays applied. `migrate:status` will show you exactly where it stopped.
        HELP;

    public function __construct(private Migrator $migrator) {
    }

    public function run(Input $input, Output $output): int {
        $ran = $this->migrator->migrate(static function (string $name) use ($output): void {
            $output->line('  ' . $name);
        });

        if ($ran === []) {
            $output->comment('Nothing to migrate.');

            return self::SUCCESS;
        }

        $output->success(sprintf('Migrated %d migration(s).', count($ran)));

        return self::SUCCESS;
    }
}
