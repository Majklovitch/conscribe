<?php

declare(strict_types=1);

namespace Tests\Core\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleException;
use App\Core\Console\Input;
use App\Core\Console\Kernel;
use App\Core\Console\Output;
use App\Core\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class Greeting {
    public function __construct(public string $text = 'hello') {
    }
}

final class EchoCommand extends Command {
    protected string $name = 'say';
    protected string $description = 'Says something.';
    protected string $help = 'Usage: php tty say <word>';

    public function run(Input $input, Output $output): int {
        $output->line($input->argument(0, 'nothing'));

        return self::SUCCESS;
    }
}

/** Only constructible through the container. */
final class NeedsGreeting extends Command {
    protected string $name = 'greet';
    protected string $description = 'Greets.';

    public function __construct(private Greeting $greeting) {
    }

    public function run(Input $input, Output $output): int {
        $output->line($this->greeting->text);

        return self::SUCCESS;
    }
}

final class FailingCommand extends Command {
    protected string $name = 'fail';
    protected string $description = 'Fails.';

    public function run(Input $input, Output $output): int {
        return self::FAILURE;
    }
}

final class ThrowingCommand extends Command {
    protected string $name = 'boom';
    protected string $description = 'Throws.';

    public function run(Input $input, Output $output): int {
        throw new \DomainException('something broke');
    }
}

final class Nameless extends Command {
    public function run(Input $input, Output $output): int {
        return self::SUCCESS;
    }
}

#[CoversClass(Kernel::class)]
#[CoversClass(Input::class)]
#[CoversClass(Output::class)]
final class ConsoleTest extends TestCase {
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private Output $output;

    protected function setUp(): void {
        $this->stdout = fopen('php://memory', 'r+b');
        $this->stderr = fopen('php://memory', 'r+b');
        // Undecorated, so assertions compare text rather than escape codes.
        $this->output = new Output($this->stdout, $this->stderr, decorated: false);
    }

    private function stdout(): string {
        rewind($this->stdout);

        return (string) stream_get_contents($this->stdout);
    }

    private function stderr(): string {
        rewind($this->stderr);

        return (string) stream_get_contents($this->stderr);
    }

    // -----------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------

    public function testItSeparatesArgumentsFromOptions(): void {
        $input = Input::fromArgv(['assets', '--env=production', '-v', 'extra']);

        self::assertSame(['assets', 'extra'], $input->arguments());
        self::assertSame('production', $input->option('env'));
        self::assertTrue($input->option('v'));
    }

    #[TestDox('a flag with no value reads as true, and absence as null')]
    public function testFlagsAndAbsence(): void {
        $input = Input::fromArgv(['--watch']);

        self::assertTrue($input->option('watch'));
        self::assertTrue($input->hasOption('watch'));
        self::assertNull($input->option('env'));
        self::assertFalse($input->hasOption('env'));
    }

    #[TestDox('-abc is three flags')]
    public function testShortOptionsCanBeBundled(): void {
        $input = Input::fromArgv(['-abc']);

        self::assertTrue($input->option('a'));
        self::assertTrue($input->option('b'));
        self::assertTrue($input->option('c'));
    }

    #[TestDox('everything after -- is an argument')]
    public function testDoubleDashEndsOptionParsing(): void {
        $input = Input::fromArgv(['--env=dev', '--', '--not-an-option', '-x']);

        self::assertSame('dev', $input->option('env'));
        self::assertSame(['--not-an-option', '-x'], $input->arguments());
    }

    #[TestDox('an empty value is kept, not turned into a flag')]
    public function testEmptyOptionValueIsPreserved(): void {
        self::assertSame('', Input::fromArgv(['--name='])->option('name'));
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    public function testErrorsGoToStderr(): void {
        $this->output->line('to stdout');
        $this->output->error('to stderr');

        self::assertSame('to stdout' . PHP_EOL, $this->stdout());
        self::assertSame('to stderr' . PHP_EOL, $this->stderr());
    }

    #[TestDox('undecorated output carries no escape codes')]
    public function testColourCanBeTurnedOff(): void {
        $this->output->success('done');

        self::assertSame('done' . PHP_EOL, $this->stdout());
        self::assertStringNotContainsString("\033", $this->stdout());
    }

    public function testColumnsAreAligned(): void {
        $this->output->columns(['a' => 'short name', 'longer' => 'long name']);

        self::assertStringContainsString('  a       short name', $this->stdout());
        self::assertStringContainsString('  longer  long name', $this->stdout());
    }

    // -----------------------------------------------------------------
    // Kernel
    // -----------------------------------------------------------------

    private function kernel(?Container $container = null): Kernel {
        $kernel = new Kernel($container);
        $kernel->registerMany([EchoCommand::class, FailingCommand::class, ThrowingCommand::class]);

        return $kernel;
    }

    public function testItRunsTheNamedCommand(): void {
        $status = $this->kernel()->run(['tty', 'say', 'world'], $this->output);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('world' . PHP_EOL, $this->stdout());
    }

    #[TestDox('a command builds through the container, so it can have dependencies')]
    public function testCommandsAreAutowired(): void {
        $container = new Container();
        $container->instance(Greeting::class, new Greeting('good evening'));

        $kernel = new Kernel($container);
        $kernel->register(NeedsGreeting::class);

        $kernel->run(['tty', 'greet'], $this->output);

        self::assertSame('good evening' . PHP_EOL, $this->stdout());
    }

    #[TestDox('the exit code is the command\'s own')]
    public function testExitCodeIsReturned(): void {
        self::assertSame(Command::FAILURE, $this->kernel()->run(['tty', 'fail'], $this->output));
    }

    #[TestDox('no command at all lists them')]
    public function testEmptyArgvListsCommands(): void {
        $status = $this->kernel()->run(['tty'], $this->output);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('say', $this->stdout());
        self::assertStringContainsString('Says something.', $this->stdout());
    }

    #[TestDox('an unknown command fails and suggests the closest match')]
    public function testUnknownCommandSuggests(): void {
        $status = $this->kernel()->run(['tty', 'sya'], $this->output);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('There is no command named "sya"', $this->stderr());
        self::assertStringContainsString('Did you mean say?', $this->stdout());
    }

    #[TestDox('something unlike any command is not given a wild guess')]
    public function testNoSuggestionWhenNothingIsClose(): void {
        $this->kernel()->run(['tty', 'zzzzzzzz'], $this->output);

        self::assertStringNotContainsString('Did you mean', $this->stdout());
    }

    #[TestDox('--help on a command shows its help instead of running it')]
    public function testHelpOptionIsRoutedToHelp(): void {
        $this->kernel()->run(['tty', 'say', '--help'], $this->output);

        self::assertStringContainsString('Usage: php tty say <word>', $this->stdout());
        self::assertStringNotContainsString('nothing', $this->stdout(), 'the command itself must not have run');
    }

    public function testHelpWithoutACommandNameFails(): void {
        self::assertSame(Command::FAILURE, $this->kernel()->run(['tty', 'help'], $this->output));
    }

    #[TestDox('an exception from a command is reported and re-thrown for the log')]
    public function testCommandExceptionsReachTheErrorHandler(): void {
        // Reported to stderr so a script sees something, but re-thrown so the
        // registered handler logs it with its trace - swallowing it would turn
        // a crash into a silent exit code.
        $this->expectException(\DomainException::class);

        try {
            $this->kernel()->run(['tty', 'boom'], $this->output);
        } finally {
            self::assertStringContainsString('something broke', $this->stderr());
        }
    }

    public function testRegisteringANamelessCommandIsRefused(): void {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('declares no $name');

        $this->kernel()->register(Nameless::class);
    }

    public function testRegisteringSomethingThatIsNotACommandIsRefused(): void {
        $this->expectException(ConsoleException::class);

        /** @phpstan-ignore argument.type */
        $this->kernel()->register(Greeting::class);
    }
}
