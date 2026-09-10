<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Http\Session;

/**
 * A Session whose CSRF token is fixed and known.
 *
 * The real Session::validateToken() starts a PHP session, which under the CLI
 * SAPI cannot set a cookie and therefore always fails - it would make every
 * token look invalid and the accepting half of the CSRF tests untestable.
 * Overriding the one method keeps the rest of Session real.
 */
final class FakeSession extends Session {
    public const VALID_TOKEN = 'valid-test-token';

    public function validateToken(?string $token): bool {
        return $token === self::VALID_TOKEN;
    }
}
