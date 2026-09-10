<?php

namespace App\Core\Console;

use RuntimeException;

/**
 * The console was asked for something it cannot do: an unknown command, a class
 * registered that is not a command, a command with no name.
 *
 * A type of its own so the kernel can report it as a message rather than as a
 * stack trace - it is a wiring mistake, not a crash.
 */
class ConsoleException extends RuntimeException {
}
