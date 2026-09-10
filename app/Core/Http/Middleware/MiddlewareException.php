<?php

namespace App\Core\Http\Middleware;

use RuntimeException;

/**
 * The pipeline was given something it cannot run, or a middleware broke its
 * contract.
 *
 * A type of its own so the router can tell this apart from an exception thrown
 * by the application inside a middleware: this one is a wiring mistake and
 * becomes a logged 500, while an application exception keeps travelling to the
 * error handler, where the debug bar can show it properly.
 */
class MiddlewareException extends RuntimeException {
}
