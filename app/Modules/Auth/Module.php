<?php

namespace Modules\Auth;

use App\Core\Module\Module as BaseModule;
use Modules\Auth\Commands\TokenCreateCommand;
use Modules\Auth\Commands\UserCreateCommand;

/**
 * Authentication: users, sessions for the web, tokens for the API.
 *
 * Its tables come from ./migrations through the migrator, and its commands
 * through the console, so enabling the module is the whole installation:
 *
 *   php tty migrate
 *   php tty user:create you@example.cz
 */
final class Module extends BaseModule {
    public function commands(): array {
        return [UserCreateCommand::class, TokenCreateCommand::class];
    }
}
