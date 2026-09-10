<?php

namespace App\Core\Database\Migrations;

use PDO;

/**
 * One change to the schema.
 *
 * A migration file returns an anonymous class rather than declaring a named
 * one, so two migrations can describe the same table without colliding and
 * nothing has to be added to the autoloader:
 *
 *   <?php
 *   return new class extends Migration {
 *       public function up(PDO $db): void {
 *           $db->exec('CREATE TABLE orders (id INT PRIMARY KEY)');
 *       }
 *
 *       public function down(PDO $db): void {
 *           $db->exec('DROP TABLE orders');
 *       }
 *   };
 *
 * The SQL is written by hand on purpose. A schema builder is a project of its
 * own, and it would sit oddly in a framework whose data layer is a thin wrapper
 * over PDO - you would be learning its dialect instead of the one your database
 * already documents.
 */
abstract class Migration {
    abstract public function up(PDO $db): void;

    /**
     * Undoes up().
     *
     * A migration that genuinely cannot be undone - a destructive data change,
     * a dropped column whose contents are gone - should throw
     * IrreversibleMigration rather than leave this empty, so a rollback stops
     * loudly instead of quietly marking it undone.
     */
    public function down(PDO $db): void {
        throw new IrreversibleMigration(static::class . ' cannot be rolled back.');
    }
}
