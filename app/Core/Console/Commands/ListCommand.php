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
        foreach ($this->kernel->commands() as $name => $class) {
            // Built one at a time and discarded: reading a description must
            // not require the command's dependencies to be satisfiable.
            $rows[$name] = $this->describe($name);
        }

        $output->columns($rows);
        $output->line();
        $output->comment('  `php tty help <command>` says more about one of them.');

        return self::SUCCESS;
    }

    private function describe(string $name): string {
        try {
            return $this->kernel->resolve($name)->description();
        } catch (\Throwable) {
            // A command whose dependencies cannot be built is still worth
            // listing - it is more useful to see that it exists than to have
            // the whole listing fail because of one of them.
            return '(unavailable)';
        }
    }
}
