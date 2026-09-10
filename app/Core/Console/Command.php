<?php

namespace App\Core\Console;

/**
 * One command.
 *
 * A subclass declares its name and description and implements run(). The
 * container builds it, so a command may declare its dependencies in the
 * constructor exactly as a controller does.
 *
 *   final class ImportCommand extends Command {
 *       protected string $name = 'import';
 *       protected string $description = 'Imports the price list.';
 *
 *       public function __construct(private PriceList $prices) {
 *       }
 *
 *       public function run(Input $input, Output $output): int {
 *           $this->prices->import($input->argument(0) ?? 'prices.csv');
 *
 *           return self::SUCCESS;
 *       }
 *   }
 */
abstract class Command {
    public const SUCCESS = 0;
    public const FAILURE = 1;

    /** The word typed after `tty`. */
    protected string $name = '';

    /** One line, shown by `tty list`. */
    protected string $description = '';

    /**
     * Usage detail for `tty help <command>`; empty means the description says
     * everything there is to say.
     */
    protected string $help = '';

    /**
     * @return int an exit code: 0 for success, anything else for failure
     */
    abstract public function run(Input $input, Output $output): int;

    public function name(): string {
        return $this->name;
    }

    public function description(): string {
        return $this->description;
    }

    public function help(): string {
        return $this->help;
    }
}
