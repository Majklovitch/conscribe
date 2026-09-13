<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Http\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Session::class)]
final class SessionTest extends TestCase {
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
