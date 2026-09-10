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

        $command = $this->kernel->resolve($name);

        $output->title($command->name());
        $output->line($command->description());

        if ($command->help() !== '') {
            $output->line();
            $output->line($command->help());
        }

        return self::SUCCESS;
    }
}
