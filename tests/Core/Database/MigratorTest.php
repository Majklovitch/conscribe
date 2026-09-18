<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use App\Core\Database\Migrations\IrreversibleMigration;
use App\Core\Database\Migrations\MigrationException;
use App\Core\Database\Migrations\MigrationRepository;
use App\Core\Database\Migrations\Migrator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The migrator against a real database.
 *
 * SQLite in memory rather than a mock: a migration runner whose SQL is never
 * executed proves very little, and the bookkeeping DDL was written portable
 * precisely so this could be a real database.
 */
#[CoversClass(Migrator::class)]
#[CoversClass(MigrationRepository::class)]
final class MigratorTest extends TestCase {
    private PDO $db;
    private string $path;

    protected function setUp(): void {
        $this->db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->path = sys_get_temp_dir() . '/conscribe-migrations-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0o775, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->path);
    }

    private function migrator(): Migrator {
        return new Migrator($this->db, $this->path);
    }

    /** Writes a migration file that creates and drops one table. */
    private function writeMigration(string $name, string $table): void {
        file_put_contents($this->path . '/' . $name . '.php', <<<PHP
            <?php
            return new class extends \\App\\Core\\Database\\Migrations\\Migration {
                public function up(\\PDO \$db): void {
                    \$db->exec('CREATE TABLE {$table} (id INTEGER PRIMARY KEY)');
                }
                public function down(\\PDO \$db): void {
                    \$db->exec('DROP TABLE {$table}');
                }
            };
            PHP);
    }

    private function tableExists(string $table): bool {
        $statement = $this->db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    // -----------------------------------------------------------------

    public function testItRunsPendingMigrations(): void {
        $this->writeMigration('2026_01_01_000000_create_a', 'a');
        $this->writeMigration('2026_01_02_000000_create_b', 'b');

        $ran = $this->migrator()->migrate();

        self::assertSame(['2026_01_01_000000_create_a', '2026_01_02_000000_create_b'], $ran);
        self::assertTrue($this->tableExists('a'));
        self::assertTrue($this->tableExists('b'));
    }

    #[TestDox('a second run does nothing')]
    public function testMigrationsRunOnlyOnce(): void {
        $this->writeMigration('2026_01_01_000000_create_a', 'a');

        $this->migrator()->migrate();

        self::assertSame([], $this->migrator()->migrate());
    }

    #[TestDox('files run in filename order, not in the order they were written')]
    public function testOrderIsByFilename(): void {
        $this->writeMigration('2026_03_01_000000_third', 'c');
        $this->writeMigration('2026_01_01_000000_first', 'a');
        $this->writeMigration('2026_02_01_000000_second', 'b');

        self::assertSame(
            ['2026_01_01_000000_first', '2026_02_01_000000_second', '2026_03_01_000000_third'],
            $this->migrator()->migrate(),
        );
    }

    #[TestDox('one run is one batch, and rollback undoes that batch')]
    public function testRollbackUndoesTheLastBatch(): void {
        $this->writeMigration('2026_01_01_000000_create_a', 'a');
        $this->migrator()->migrate();

        $this->writeMigration('2026_02_01_000000_create_b', 'b');
        $this->writeMigration('2026_02_02_000000_create_c', 'c');
        $this->migrator()->migrate();

        $rolledBack = $this->migrator()->rollback();

        // Newest first: a table dropped before something that depends on it
        // would fail, and the reverse order is the only one that can work.
        self::assertSame(['2026_02_02_000000_create_c', '2026_02_01_000000_create_b'], $rolledBack);
        self::assertFalse($this->tableExists('b'));
        self::assertFalse($this->tableExists('c'));
        self::assertTrue($this->tableExists('a'), 'the earlier batch is untouched');
    }

    public function testRollbackWithNothingToUndo(): void {
        self::assertSame([], $this->migrator()->rollback());
    }

    #[TestDox('a rolled-back migration is pending again')]
    public function testRollbackMakesMigrationsPendingAgain(): void {
        $this->writeMigration('2026_01_01_000000_create_a', 'a');
        $this->migrator()->migrate();
        $this->migrator()->rollback();

        self::assertSame(['2026_01_01_000000_create_a'], array_keys($this->migrator()->pending()));
    }

    #[TestDox('status distinguishes applied, pending and missing')]
    public function testStatus(): void {
        $this->writeMigration('2026_01_01_000000_applied', 'a');
        $this->migrator()->migrate();
        $this->writeMigration('2026_02_01_000000_pending', 'b');

        // A migration that ran on this database and was then deleted from the
        // branch - the drift a status command exists to show.
        (new MigrationRepository($this->db))->log('2026_01_15_000000_gone', 1);

        self::assertSame([
            '2026_01_01_000000_applied' => 'applied',
            '2026_01_15_000000_gone' => 'missing',
            '2026_02_01_000000_pending' => 'pending',
        ], $this->migrator()->status());
    }

    #[TestDox('everything before a failure stays recorded as applied')]
    public function testAFailureLeavesEarlierMigrationsRecorded(): void {
        // MySQL commits DDL implicitly, so the migrator cannot unwind a partial
        // run; what it must do instead is record honestly how far it got.
        $this->writeMigration('2026_01_01_000000_create_a', 'a');
        file_put_contents($this->path . '/2026_01_02_000000_broken.php', <<<'PHP'
            <?php
            return new class extends \App\Core\Database\Migrations\Migration {
                public function up(\PDO $db): void {
                    $db->exec('THIS IS NOT SQL');
                }
            };
            PHP);

        try {
            $this->migrator()->migrate();
            self::fail('expected the broken migration to throw');
        } catch (\PDOException) {
            // expected
        }

        self::assertSame(['2026_01_01_000000_create_a'], (new MigrationRepository($this->db))->applied());
        self::assertTrue($this->tableExists('a'));
    }

    #[TestDox('a migration with no down() refuses to roll back')]
    public function testIrreversibleMigration(): void {
        file_put_contents($this->path . '/2026_01_01_000000_oneway.php', <<<'PHP'
            <?php
            return new class extends \App\Core\Database\Migrations\Migration {
                public function up(\PDO $db): void {
                    $db->exec('CREATE TABLE oneway (id INTEGER PRIMARY KEY)');
                }
            };
            PHP);

        $this->migrator()->migrate();

        $this->expectException(IrreversibleMigration::class);
        $this->migrator()->rollback();
    }

    #[TestDox('rolling back a migration whose file is gone says so')]
    public function testRollbackWithAMissingFile(): void {
        $this->writeMigration('2026_01_01_000000_create_a', 'a');
        $this->migrator()->migrate();

        unlink($this->path . '/2026_01_01_000000_create_a.php');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('its file is gone');

        $this->migrator()->rollback();
    }

    #[TestDox('a file that returns something else explains what it should return')]
    public function testFileMustReturnAMigration(): void {
        file_put_contents($this->path . '/2026_01_01_000000_wrong.php', "<?php\nreturn 'not a migration';\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must return a');

        $this->migrator()->migrate();
    }

    #[TestDox('a syntax error names the file it is in')]
    public function testASyntaxErrorIsReportedWithTheFilename(): void {
        // A bare ParseError says "unexpected token" and a line number, which
        // tells you nothing about which of a hundred migrations it came from.
        file_put_contents($this->path . '/2026_01_01_000000_broken.php', '<?php
this is not php;
');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('2026_01_01_000000_broken.php has a syntax error');

        $this->migrator()->migrate();
    }

    #[TestDox('the bookkeeping table is created on demand')]
    public function testTheTableIsCreatedWhenNeeded(): void {
        self::assertFalse($this->tableExists(MigrationRepository::TABLE));

        $this->migrator()->pending();

        self::assertTrue($this->tableExists(MigrationRepository::TABLE));
    }

    #[TestDox('two paths holding the same filename are refused, not silently merged')]
    public function testDuplicateMigrationNamesAcrossPathsThrow(): void {
        $second = sys_get_temp_dir() . '/conscribe-migrations-' . bin2hex(random_bytes(6));
        mkdir($second, 0o775, true);

        try {
            $this->writeMigration('2026_01_01_000000_create_settings', 'settings_app');
            file_put_contents($second . '/2026_01_01_000000_create_settings.php', file_get_contents(
                $this->path . '/2026_01_01_000000_create_settings.php',
            ));

            // A migration is identified by its filename alone, so keeping the
            // last one found would run one file, log the shared name as
            // applied, and leave the other permanently invisible - status
            // would call it done while its table was never created.
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('Two migrations are both named "2026_01_01_000000_create_settings"');

            (new Migrator($this->db, [$this->path, $second]))->available();
        } finally {
            foreach (glob($second . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($second);
        }
    }

    #[TestDox('the same directory listed twice is not a duplicate')]
    public function testTheSameDirectoryTwiceIsNotAClash(): void {
        $this->writeMigration('2026_01_01_000000_create_a', 'a');

        $migrator = new Migrator($this->db, [$this->path, $this->path . '/']);

        self::assertSame(['2026_01_01_000000_create_a'], array_keys($migrator->available()));
    }

    public function testEachCallbackReportsProgress(): void {
        $this->writeMigration('2026_01_01_000000_create_a', 'a');

        $seen = [];
        $this->migrator()->migrate(static function (string $name) use (&$seen): void {
            $seen[] = $name;
        });

        self::assertSame(['2026_01_01_000000_create_a'], $seen);
    }
}
