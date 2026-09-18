<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Http\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySession;

/** Counts how often something tried to start the session. */
final class CountingSession extends Session {
    public int $starts = 0;

    public function start(): bool {
        $this->starts++;

        return false;
    }
}

#[CoversClass(Session::class)]
final class SessionTest extends TestCase {
    protected function tearDown(): void {
        unset($_COOKIE['CONSCRIBEID']);
        $_SESSION = [];
    }

    #[TestDox('reading from a session that does not exist never starts one')]
    public function testReadsDoNotStartASession(): void {
        // No session cookie: there is nothing to read, and starting a session
        // to find that out would give an anonymous visitor a cookie - and make
        // the page uncacheable - just because a template asked for a flash
        // message or old input.
        $session = new CountingSession();

        self::assertNull($session->get('cart'));
        self::assertFalse($session->has('cart'));
        self::assertSame('fallback', $session->pull('cart', 'fallback'));
        self::assertSame([], $session->all());
        self::assertNull($session->getFlash('status'));
        self::assertFalse($session->hasFlash('status'));
        self::assertSame([], $session->allFlash());
        self::assertSame('', $session->old('email'));
        $session->keepFlash();
        self::assertFalse($session->validateToken('anything'));

        self::assertSame(0, $session->starts);
    }

    #[TestDox('a visitor who has a session cookie gets the session resumed')]
    public function testReadsResumeAnExistingSession(): void {
        $_COOKIE['CONSCRIBEID'] = 'an-existing-session-id';
        $session = new CountingSession();

        $session->getFlash('status');

        self::assertSame(1, $session->starts);
    }

    #[TestDox('deleting from a session that does not exist never starts one')]
    public function testDeletionsDoNotStartASession(): void {
        // Deleting has as little to do in a session that does not exist as
        // reading does. A logout route hit by a crawler, a prefetch or a
        // repeated POST would otherwise write a session file and send two
        // Set-Cookie headers - one making the session, one clearing it.
        $session = new CountingSession();

        $session->remove('cart');
        $session->clear();
        $session->rotateToken();
        $session->destroy();

        // Nor does asking about the session rather than storing in it.
        self::assertNull($session->id());
        self::assertFalse($session->regenerate());

        self::assertSame(0, $session->starts);
    }

    #[TestDox('deleting from a session the visitor does have still resumes it')]
    public function testDeletionsResumeAnExistingSession(): void {
        $_COOKIE['CONSCRIBEID'] = 'an-existing-session-id';
        $session = new CountingSession();

        $session->remove('cart');
        $session->rotateToken();

        self::assertSame(2, $session->starts);
    }

    #[TestDox('writing still starts a session')]
    public function testWritesStillStartASession(): void {
        $session = new CountingSession();

        $session->set('cart', ['item']);
        $session->flash('status', 'Saved.');
        $session->token();

        self::assertSame(3, $session->starts);
    }

    #[TestDox('keepFlash() does not undo a flash made during this request')]
    public function testKeepFlashDoesNotOverwriteTheNewQueue(): void {
        // The previous request's message, already aged into 'old'.
        $_SESSION = ['_flash' => ['old' => ['status' => 'saved', 'note' => 'kept'], 'new' => []]];

        $session = new ArraySession();
        $session->flash('status', 'updated');
        $session->keepFlash();

        // Written as `$keep + $queues['new']`, the union operator kept the
        // aged value and the message flashed a line earlier was lost.
        self::assertSame('updated', $_SESSION['_flash']['new']['status']);

        // A key only the old queue holds is still carried forward - which is
        // what keepFlash() is for.
        self::assertSame('kept', $_SESSION['_flash']['new']['note']);
    }

    #[TestDox('keepFlash() can be limited to named keys')]
    public function testKeepFlashWithNamedKeys(): void {
        $_SESSION = ['_flash' => ['old' => ['status' => 'saved', 'note' => 'dropped'], 'new' => []]];

        (new ArraySession())->keepFlash(['status']);

        self::assertSame(['status' => 'saved'], $_SESSION['_flash']['new']);
    }

    #[TestDox('passwords and the CSRF token never reach old input, nested ones included')]
    public function testSecretsAreRemoved(): void {
        $clean = Session::withoutSecrets([
            'email' => 'jan@example.cz',
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'current_password' => 'old',
            'csrf_token' => 'abc',
            'profile' => ['name' => 'Jan', 'new_password' => 'x'],
        ]);

        self::assertSame(['email' => 'jan@example.cz', 'profile' => ['name' => 'Jan']], $clean);
    }

    #[TestDox('flashInput() stores the filtered input')]
    public function testFlashInputFilters(): void {
        // A failed login is the commonest failed form; without the filter every
        // one of them wrote the typed password into the session file.
        $session = new class () extends Session {
            /** @var array<string, mixed> */
            public array $flashed = [];

            public function flash(string $key, mixed $value): self {
                $this->flashed[$key] = $value;

                return $this;
            }
        };

        $session->flashInput(['email' => 'jan@example.cz', 'password' => 'hunter2']);

        self::assertSame(['email' => 'jan@example.cz'], $session->flashed['_old_input']);
    }
}
