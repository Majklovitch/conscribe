<?php

namespace App\Core\Console;

use App\Core\Console\Commands\HelpCommand;
use App\Core\Console\Commands\ListCommand;
use App\Core\Container\Container;
use Psr\Container\ContainerExceptionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The console: a registry of commands and the dispatch into one of them.
 *
 * The counterpart of Router for the command line - it resolves a name to a
 * handler, builds that handler through the container so it can declare its
 * dependencies, and turns whatever comes back into an exit code. Commands are
 * registered by class name and only constructed once one is actually chosen, so
 * `tty list` does not pay for every command's dependencies.
 */
final class Kernel {
    /**
     * Command class names, by the word that selects them.
     *
     * @var array<string, class-string<Command>>
     */
    private array $commands = [];

    private Container $container;

    /**
     * Typed as the concrete Container rather than PSR-11 because resolving a
     * command needs make(), which PSR-11 has no notion of - it describes
     * looking entries up, not constructing new ones.
     */
    public function __construct(?Container $container = null) {
        $this->container = $container ?? new Container();

        // The console registers itself, so `list` and `help` - which take the
        // Kernel in their constructor - can be built at all. Without this a
        // standalone `new Kernel()` autowires each of them a fresh, empty
        // console, which lists nothing and reports everything as unavailable.
        $this->container->instance(self::class, $this);

        $this->register(ListCommand::class);
        $this->register(HelpCommand::class);
    }

    /**
     * Adds a command by class name.
     *
     * The name is read from the class's default property value by reflection,
     * never from an instance - so registering a command does not construct it,
     * and one with constructor dependencies can be registered long before those
     * dependencies are satisfiable.
     *
     * @param class-string<Command> $class
     */
    public function register(string $class): void {
        $name = $this->nameOf($class);

        if ($name === '') {
            throw new ConsoleException(sprintf('%s declares no $name.', $class));
        }

        $this->commands[$name] = $class;
    }

    /** @param array<int, class-string<Command>> $classes */
    public function registerMany(array $classes): void {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /** @return array<string, class-string<Command>> */
    public function commands(): array {
        ksort($this->commands);

        return $this->commands;
    }

    public function has(string $name): bool {
        return isset($this->commands[$name]);
    }

    /**
     * A command's one-line description, without building it.
     *
     * Read from the class's default property value, the same way the name is.
     * Listing what exists must not require every command's dependencies to be
     * satisfiable - `tty list` on a machine with no database still has to be
     * able to say what `migrate` is for.
     */
    public function descriptionOf(string $name): string {
        return $this->defaultOf($name, 'description');
    }

    /** A command's usage text - read the same way, without building it. */
    public function helpOf(string $name): string {
        return $this->defaultOf($name, 'help');
    }

    private function defaultOf(string $name, string $property): string {
        $class = $this->commands[$name] ?? null;
        if ($class === null) {
            return '';
        }

        $default = (new \ReflectionClass($class))->getDefaultProperties()[$property] ?? '';

        return is_string($default) ? $default : '';
    }

    /**
     * Builds one command. Public because `help` needs the full detail, which
     * only an instance can be asked for.
     */
    public function resolve(string $name): Command {
        $class = $this->commands[$name]
            ?? throw new ConsoleException(sprintf('There is no command named "%s".', $name));

        $command = $this->container->make($class);

        if (!$command instanceof Command) {
            throw new ConsoleException(sprintf('%s is not a %s.', $class, Command::class));
        }

        return $command;
    }

    /**
     * Runs the command named in $argv.
     *
     * $argv is the raw one, script name included, so an entry point can pass
     * it straight through.
     *
     * @param array<int, string> $argv
     */
    public function run(array $argv, ?Output $output = null): int {
        $output ??= new Output();

        // No command at all is not an error: it is someone finding out what
        // there is, which is exactly what `list` answers.
        $name = $argv[1] ?? 'list';
        $input = Input::fromArgv(array_slice($argv, 2));

        // `tty --help` and `tty build --help` both mean help, which is worth
        // more than making people learn a second spelling of it.
        if ($input->hasOption('help') && $name !== 'help') {
            $input = new Input([$name], []);
            $name = 'help';
        }

        if (!$this->has($name)) {
            $output->error(sprintf('There is no command named "%s".', $name));

            $suggestion = $this->closestTo($name);
            if ($suggestion !== null) {
                $output->line('Did you mean ' . $suggestion . '?');
            }

            $output->line();
            $output->comment('Run `tty list` to see what there is.');

            return Command::FAILURE;
        }

        try {
            $command = $this->resolve($name);
        } catch (ConsoleException | ContainerExceptionInterface $e) {
            // The command could not be built: an unknown name, or a dependency
            // that cannot be satisfied - no database configured, a binding
            // missing. That is a configuration problem, so it gets a sentence
            // rather than a stack trace.
            $output->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            return $command->run($input, $output);
        } catch (Throwable $e) {
            return $this->reportFailure($e, $input, $output);
        }
    }

    /**
     * Turns an exception out of a command into a message and an exit code.
     *
     * Deliberately not a re-throw. The uncaught-exception handler prints the
     * failure but leaves the exit status at 0, and a console whose crash looks
     * like success is worse than useless - `tty migrate && deploy` would carry
     * on after a failed migration. So the exception is logged here, where the
     * trace is kept, and the process exits non-zero.
     *
     * The terminal gets one line unless -v was asked for, because a stack trace
     * is rarely what you want to read first and never what a script wants.
     */
    private function reportFailure(Throwable $e, Input $input, Output $output): int {
        $this->logger()?->error($e->getMessage(), ['exception' => $e]);

        $output->error(sprintf('%s: %s', $e::class, $e->getMessage()));

        if ($input->hasOption('v') || $input->hasOption('verbose')) {
            $output->comment($e->getTraceAsString());
        } else {
            $output->comment('Run it again with -v for the stack trace; it is in the log either way.');
        }

        return Command::FAILURE;
    }

    /**
     * The application's logger, when there is one.
     *
     * Resolved rather than injected so a standalone Kernel - a test, a
     * throwaway script - needs no logging set up at all.
     */
    private function logger(): ?LoggerInterface {
        try {
            return $this->container->has(LoggerInterface::class)
                ? $this->container->get(LoggerInterface::class)
                : null;
        } catch (Throwable) {
            // A logger that cannot itself be built must not replace the failure
            // being reported with its own.
            return null;
        }
    }

    /**
     * The registered name closest to a mistyped one, or null when nothing is
     * close enough to be worth guessing at.
     */
    private function closestTo(string $name): ?string {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (array_keys($this->commands) as $candidate) {
            $distance = levenshtein($name, $candidate);

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        // At least two, because swapping a pair of letters - the commonest
        // typo there is - already costs two substitutions: "sya" has to be able
        // to find "say". Longer names get proportionally more room, and
        // something unlike anything registered still gets no wild guess.
        return $bestDistance <= max(2, (int) (strlen($name) / 3)) ? $best : null;
    }

    /** @param class-string<Command> $class */
    private function nameOf(string $class): string {
        if (!class_exists($class) || !is_subclass_of($class, Command::class)) {
            throw new ConsoleException(sprintf('%s is not a %s.', $class, Command::class));
        }

        $reflection = new \ReflectionClass($class);
        $default = $reflection->getDefaultProperties()['name'] ?? '';

        return is_string($default) ? $default : '';
    }
}
