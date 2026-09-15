<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use App\Core\Database\Connection;
use App\Core\Database\LoggingPdo;
use App\Core\Database\LoggingStatement;
use App\Core\Database\Migrations\Migrator;
use App\Core\Database\QueryLog;
use App\Core\Database\Repository;
use App\Core\Logging\Debug;
use App\Core\Logging\QueryPanel;
use Modules\Auth\LoginThrottle;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;

final class LoggedNoteRepository extends Repository {
    protected string $table = 'notes';

    public function raw(string $sql, array $bindings = []): array {
        return $this->run($sql, $bindings)->fetchAll();
    }
}

#[CoversClass(QueryLog::class)]
#[CoversClass(LoggingPdo::class)]
#[CoversClass(LoggingStatement::class)]
#[CoversClass(QueryPanel::class)]
#[CoversClass(Debug::class)]
final class QueryLogTest extends TestCase {
    private LoggingPdo $db;
    private LoggedNoteRepository $notes;

    protected function setUp(): void {
        QueryLog::reset();

        $this->db = new LoggingPdo('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Before the log is on, so the tests below see only their own queries.
        $this->db->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');
        $this->db->exec("INSERT INTO notes (title) VALUES ('first'), ('second')");

        $this->notes = new LoggedNoteRepository($this->db);
    }

    protected function tearDown(): void {
        QueryLog::reset();
    }

    #[TestDox('nothing is recorded while the log is off, which is how production runs')]
    public function testDisabledRecordsNothing(): void {
        $this->notes->find(1);

        self::assertSame(0, QueryLog::count());
        self::assertSame([], QueryLog::queries());
    }

    #[TestDox('every repository query is recorded with its bindings and time')]
    public function testQueriesAreRecorded(): void {
        QueryLog::enable();

        $this->notes->find(1);
        $this->notes->where(['title' => 'second']);

        $queries = QueryLog::queries();
        self::assertSame(2, QueryLog::count());
        self::assertCount(2, $queries);
        self::assertStringContainsString('SELECT', $queries[0]['sql']);
        self::assertSame([1], $queries[0]['bindings']);
        self::assertSame(['second'], $queries[1]['bindings']);
        self::assertGreaterThanOrEqual(0.0, $queries[0]['ms']);
        self::assertNull($queries[0]['error']);
    }

    #[TestDox('a failing query is recorded with its error and the exception still propagates')]
    public function testFailedQueryIsRecorded(): void {
        QueryLog::enable();

        try {
            $this->notes->raw('SELECT * FROM missing_table');
            self::fail('The query should have thrown.');
        } catch (PDOException) {
        }

        self::assertSame(1, QueryLog::count());
        self::assertStringContainsString('missing_table', (string) QueryLog::queries()[0]['error']);
    }

    #[TestDox('past the limit queries are counted but no longer kept')]
    public function testLimitCapsMemory(): void {
        QueryLog::enable();

        for ($i = 0; $i < QueryLog::LIMIT + 5; $i++) {
            QueryLog::record('SELECT 1', [], 0.1);
        }

        self::assertSame(QueryLog::LIMIT + 5, QueryLog::count());
        self::assertCount(QueryLog::LIMIT, QueryLog::queries());
    }

    #[TestDox('classes outside Repository are recorded too')]
    public function testAuthQueriesAreRecorded(): void {
        $this->db->exec('CREATE TABLE login_attempts (throttle_key VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
        $throttle = new LoginThrottle($this->db, maxAttempts: 2);
        $key = LoginThrottle::keyFor('jan@example.cz', '127.0.0.1');

        QueryLog::enable();

        $throttle->hit($key);
        $throttle->hit($key);

        $sql = implode("\n", array_column(QueryLog::queries(), 'sql'));
        self::assertStringContainsString('INSERT INTO login_attempts', $sql);
        self::assertStringContainsString('UPDATE login_attempts', $sql);
        self::assertSame([$key], QueryLog::queries()[0]['bindings']);
        // Behaviour is unchanged by going through run().
        self::assertGreaterThan(0, $throttle->availableIn($key));
    }

    #[TestDox('exec(), query(), raw prepare() with named and by-reference parameters are all recorded')]
    public function testEveryPdoEntryPointIsRecorded(): void {
        QueryLog::enable();

        $this->db->exec("UPDATE notes SET title = 'x' WHERE id = 2");
        $this->db->query('SELECT COUNT(*) FROM notes')->fetchColumn();

        $named = $this->db->prepare('SELECT * FROM notes WHERE id = :id');
        $named->execute(['id' => 1]);

        $id = 1;
        $byRef = $this->db->prepare('SELECT * FROM notes WHERE id = ?');
        $byRef->bindParam(1, $id, PDO::PARAM_INT);
        $id = 2;
        $byRef->execute();

        $queries = QueryLog::queries();
        self::assertSame(
            ["UPDATE notes SET title = 'x' WHERE id = 2", 'SELECT COUNT(*) FROM notes', 'SELECT * FROM notes WHERE id = :id', 'SELECT * FROM notes WHERE id = ?'],
            array_column($queries, 'sql'),
        );
        self::assertSame(['id' => 1], $queries[2]['bindings']);
        self::assertSame([2], $queries[3]['bindings'], 'the value at execute() time');
    }

    #[TestDox('a statement reused with bindParam() in a loop logs each value, not the last one')]
    public function testBindParamValuesAreSnapshots(): void {
        QueryLog::enable();

        $statement = $this->db->prepare('SELECT * FROM notes WHERE id = ?');
        $id = 0;
        $statement->bindParam(1, $id, PDO::PARAM_INT);

        foreach ([1, 2] as $id) {
            $statement->execute();
        }

        self::assertSame([[1], [2]], array_column(QueryLog::queries(), 'bindings'));
    }

    #[TestDox('a failed connection does not put the password into the stack trace')]
    public function testPasswordStaysOutOfTraces(): void {
        try {
            // An unknown driver fails inside the constructor without a network.
            new LoggingPdo('nosuchdriver:x', 'user', 'correct-horse-battery');
            self::fail('The connection should have failed.');
        } catch (PDOException $e) {
            self::assertStringNotContainsString('correct-horse-battery', var_export($e->getTrace(), true));
        }
    }

    #[TestDox('transactions and savepoints show up in order')]
    public function testTransactionsAreRecorded(): void {
        QueryLog::enable();

        Connection::transaction(function (): void {
            Connection::transaction(fn () => $this->notes->find(1), $this->db);
        }, $this->db);

        self::assertSame(
            ['BEGIN', 'SAVEPOINT conscribe_sp_1', 'SELECT * FROM `notes` WHERE `id` = ? LIMIT 1', 'RELEASE SAVEPOINT conscribe_sp_1', 'COMMIT'],
            array_column(QueryLog::queries(), 'sql'),
        );
    }

    #[TestDox('migrations are recorded, they never went through a repository')]
    public function testMigrationsAreRecorded(): void {
        QueryLog::enable();

        (new Migrator($this->db, [dirname(__DIR__, 3) . '/app/Modules/Auth/migrations']))->migrate();

        $sql = implode("\n", array_column(QueryLog::queries(), 'sql'));
        self::assertStringContainsString('CREATE TABLE users', $sql);
        self::assertStringContainsString('CREATE TABLE api_tokens', $sql);
    }

    #[TestDox('Connection opens a logging PDO only while the log is on')]
    public function testConnectionPicksPdoClass(): void {
        self::assertSame(PDO::class, Connection::pdoClass());

        QueryLog::enable();

        self::assertSame(LoggingPdo::class, Connection::pdoClass());
    }

    #[TestDox('without a bar - the console - the query log stays off')]
    public function testNoBarCollectsNothing(): void {
        $showBar = Debugger::$showBar;
        Debugger::$showBar = false;

        try {
            Debug::registerQueryPanel();

            // The panel is not asserted absent: Tracy's bar is global, and
            // another test may already have added it.
            self::assertFalse(QueryLog::isEnabled());
        } finally {
            Debugger::$showBar = $showBar;
        }
    }

    #[TestDox('with a bar the log is turned on and the panel added once')]
    public function testBarRegistersPanel(): void {
        $showBar = Debugger::$showBar;
        Debugger::$showBar = true;

        try {
            Debug::registerQueryPanel();
            Debug::registerQueryPanel();

            self::assertTrue(QueryLog::isEnabled());
            self::assertInstanceOf(QueryPanel::class, Debugger::getBar()->getPanel('conscribe.sql'));
        } finally {
            Debugger::$showBar = $showBar;
        }
    }

    #[TestDox('the panel escapes SQL, shows bindings and flags repeated statements')]
    public function testPanelRendersQueries(): void {
        QueryLog::enable();

        $this->notes->find(1);
        $this->notes->find(2);
        $this->notes->raw("SELECT '<script>' AS x");

        $panel = new QueryPanel();
        $html = $panel->getPanel();

        self::assertStringContainsString('SQL 3 /', $panel->getTab());
        self::assertStringContainsString('×2', $html);
        self::assertStringContainsString('[2]', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
