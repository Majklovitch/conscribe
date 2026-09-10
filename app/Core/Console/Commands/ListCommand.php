<?php

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Kernel;
use App\Core\Console\Output;

/**
 * What `tty` says when given nothing to do.
 */
final class ListCommand extends Command {
    protected string $name = 'list';
    protected string $description = 'Lists the available commands.';

    public function __construct(private Kernel $kernel) {
    }

    public function run(Input $input, Output $output): int {
        $output->title('Conscribe console');
        $output->line();
        $output->comment('  Usage: php tty <command> [arguments] [--options]');
        $output->line();

        $rows = [];
        foreach (array_keys($this->kernel->commands()) as $name) {
            // Read by reflection rather than from an instance: listing what
            // exists must not require every command's dependencies to be
            // satisfiable. `tty list` on a machine with no database still says
            // what `migrate` is for.
            $rows[$name] = $this->kernel->descriptionOf($name);
        }

        $output->columns($rows);
        $output->line();
        $output->comment('  `php tty help <command>` says more about one of them.');

        return self::SUCCESS;
    }
}
