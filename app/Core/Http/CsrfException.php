<?php

namespace App\Core\Http;

use RuntimeException;

/**
 * Thrown when a request carries no valid CSRF token.
 *
 * It is an exception rather than a response so that the check can happen
 * anywhere - in the router, in a controller, in check_csrf() inside a template -
 * without any of those places having to know how this application renders an
 * error. The router catches it and turns it into the configured error page.
 */
class CsrfException extends RuntimeException {
}
