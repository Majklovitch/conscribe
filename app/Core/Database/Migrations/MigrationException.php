<?php

namespace App\Core\Database\Migrations;

use RuntimeException;

/**
 * The migrator was given something it cannot run: a file that returns no
 * migration, a rollback of a batch that is not there.
 */
class MigrationException extends RuntimeException {
}
