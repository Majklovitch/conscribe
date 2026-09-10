<?php

namespace App\Core\Database\Migrations;

use RuntimeException;

/**
 * A migration that cannot be undone was asked to roll back.
 */
class IrreversibleMigration extends RuntimeException {
}
