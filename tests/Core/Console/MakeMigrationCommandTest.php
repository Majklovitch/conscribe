<?php

declare(strict_types=1);

namespace Tests\Core\Console;

use App\Bootstrap;
use App\Core\Console\Command;
use App\Core\Console\Commands\MakeMigrationCommand;
use App\Core\Console\Input;
use App\Core\Console\Output;
use App\Core\Database\Migrations\Migration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MakeMigrationCommand::class)]
final class MakeMigrationCommandTest extends TestCase {
    /** @var array<int, string> */
    private array $created = [];

    /** @var resource */
    private $stdout;

    private Output $output;

    protected function setUp(): void {
        $this->stdout = fopen('php://memory', 'r+b');
        $this->output = new Output($this->stdout, $this->stdout, decorated: false);
    }

    protected function tearDown(): void {
        foreach ($this->created as $file) {
            @unlink($file);
        }
    }

    private function make(string $name): string {
        $before = glob(Bootstrap::root() . '/database/migrations/*.php') ?: [];

        $status = (new MakeMigrationCommand())->run(new Input([$name]), $this->output);
        self::assertSame(Command::SUCCESS, $status);

        $after = glob(Bootstrap::root() . '/database/migrations/*.php') ?: [];
        $new = array_values(array_diff($after, $before));

        self::assertCount(1, $new, 'exactly one file should have appeared');
        $this->created[] = $new[0];

        return $new[0];
    }

    #[TestDox('the generated file loads as a Migration, without warnings')]
    public function testTheTemplateIsUsable(): void {
        // The suite fails on warnings, so `use PDO;` in a file with no
        // namespace - which warns that it has no effect - would fail here.
        $file = $this->make('create_orders_table');

        $migration = require $file;

        self::assertInstanceOf(Migration::class, $migration);
    }

    #[TestDox('the name is slugged and prefixed with a sortable timestamp')]
    public function testTheFilenameIsBuiltFromTheName(): void {
        $file = $this->make('Create Orders Table!!');

        self::assertMatchesRegularExpression(
            '/^\d{4}_\d{2}_\d{2}_\d{6}_create_orders_table\.php$/',
            basename($file),
        );
    }

    public function testANameWithNothingUsableIsRefused(): void {
        $status = (new MakeMigrationCommand())->run(new Input(['!!!']), $this->output);

        self::assertSame(Command::FAILURE, $status);
    }

    public function testNoNameIsRefused(): void {
        self::assertSame(Command::FAILURE, (new MakeMigrationCommand())->run(new Input(), $this->output));
    }
}
