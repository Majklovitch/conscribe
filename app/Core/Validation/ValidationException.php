<?php

namespace App\Core\Validation;

use RuntimeException;

/**
 * Validation failed.
 *
 * Thrown rather than returned so an action can validate on its first line and
 * carry on writing the happy path; the router turns it into a redirect back
 * with the errors, or a 422 for a client that wants JSON.
 */
class ValidationException extends RuntimeException {
    public function __construct(private ErrorBag $errors, string $message = 'The given data was invalid.') {
        parent::__construct($message);
    }

    public function errors(): ErrorBag {
        return $this->errors;
    }
}
