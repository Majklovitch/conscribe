<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Http\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

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

    #[TestDox('writing still starts a session')]
    public function testWritesStillStartASession(): void {
        $session = new CountingSession();

        $session->set('cart', ['item']);
        $session->flash('status', 'Saved.');

        self::assertSame(2, $session->starts);
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
