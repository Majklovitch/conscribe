<?php
namespace App\Core\Template;

use RuntimeException;

/**
 * Vyhozeno, pokud šablona neexistuje nebo má nepovolenou cestu.
 */
class ViewNotFoundException extends RuntimeException {
}
