<?php

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Kernel;
use App\Core\Console\Output;

final class HelpCommand extends Command {
    protected string $name = 'help';
    protected string $description = 'Explains one command.';
    protected string $help = 'Usage: php tty help <command>';

    public function __construct(private Kernel $kernel) {
    }

    public function run(Input $input, Output $output): int {
        $name = $input->argument(0);

        if ($name === null) {
            $output->line($this->help);

            return self::FAILURE;
        }

        if (!$this->kernel->has($name)) {
            $output->error(sprintf('There is no command named "%s".', $name));

            return self::FAILURE;
        }

        // Read by reflection rather than from an instance, like `list`: help
        // for `migrate` has to work on a machine with no database, which is
        // exactly when someone reads it.
        $output->title($name);
        $output->line($this->kernel->descriptionOf($name));

        $help = $this->kernel->helpOf($name);
        if ($help !== '') {
            $output->line();
            $output->line($help);
        }

        return self::SUCCESS;
    }
}
