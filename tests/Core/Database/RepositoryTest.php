<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use App\Core\Database\Model;
use App\Core\Database\Repository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class Note extends Model {
    public int $id = 0;
    public string $title = '';
}

final class NoteRepository extends Repository {
    protected string $table = 'notes';
    protected string $model = Note::class;
}

#[CoversClass(Repository::class)]
final class RepositoryTest extends TestCase {
    private PDO $db;
    private NoteRepository $notes;

    protected function setUp(): void {
        // Deliberately without ATTR_DEFAULT_FETCH_MODE: PDO's own default is
        // FETCH_BOTH, and a repository must not depend on the caller having
        // configured the connection the way Connection does.
        $this->db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, secret TEXT NOT NULL)');
        $this->db->exec("INSERT INTO notes (title, secret) VALUES ('first', 'do-not-publish')");

        $this->notes = new NoteRepository($this->db);
    }

    #[TestDox('hydration never keeps the positional copies of a FETCH_BOTH row')]
    public function testRowsAreFetchedByNameOnly(): void {
        // FETCH_BOTH returns every column twice, by name and by position. Model
        // keeps what it does not recognise, so the positional copies would land
        // in its bag of attributes - and a column a model deliberately leaves
        // out, a password hash above all, would be published by its numeric
        // twin in toArray() and the JSON.
        $note = $this->notes->find(1);
        self::assertInstanceOf(Note::class, $note);

        self::assertSame(['id', 'title', 'secret'], array_keys($note->toArray()));
        self::assertSame([], array_filter(array_keys($note->toArray()), 'is_int'));
    }

    #[TestDox('the same holds for every read, not just find()')]
    public function testAllReadsFetchByNameOnly(): void {
        foreach ([$this->notes->all(), $this->notes->where(['title' => 'first'])] as $rows) {
            self::assertCount(1, $rows);
            self::assertInstanceOf(Note::class, $rows[0]);
            self::assertSame(['id', 'title', 'secret'], array_keys($rows[0]->toArray()));
        }

        self::assertSame(['id', 'title', 'secret'], array_keys((array) $this->notes->findRaw(1)));
    }

    #[TestDox('a repository given no connection does not open one')]
    public function testConnectionIsLazy(): void {
        // Repository's constructor says the connection is only injected in
        // tests and otherwise taken on the first query. Building one must not
        // reach for Connection::get(), which would fail here - there is no
        // database configured for the test suite.
        self::assertInstanceOf(NoteRepository::class, new NoteRepository());
    }
}
