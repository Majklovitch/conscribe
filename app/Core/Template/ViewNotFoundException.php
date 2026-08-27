<?php
namespace App\Core\Template;

use RuntimeException;

/**
 * Thrown when a template does not exist or has a disallowed path.
 */
class ViewNotFoundException extends RuntimeException {
}
