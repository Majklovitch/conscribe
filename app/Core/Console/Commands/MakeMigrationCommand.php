<?php

namespace App\Core\Console\Commands;

use App\Bootstrap;
use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;

final class MakeMigrationCommand extends Command {
    protected string $name = 'make:migration';
    protected string $description = 'Creates an empty migration file.';
    protected string $help = <<<'HELP'
        Usage: php tty make:migration <name>

          php tty make:migration create_orders_table
          => database/migrations/2026_09_10_190000_create_orders_table.php

        The timestamp is what orders the migrations, so it is both obvious in a
        directory listing and stable across machines - unlike a counter, which two
        branches would both claim.
        HELP;

    public function run(Input $input, Output $output): int {
        $name = $input->argument(0);

        if ($name === null || trim($name) === '') {
            $output->error('A migration needs a name, e.g. `php tty make:migration create_orders_table`.');

            return self::FAILURE;
        }

        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($name)));
        $slug = trim($slug, '_');

        if ($slug === '') {
            $output->error('That name has nothing usable in it.');

            return self::FAILURE;
        }

        $directory = Bootstrap::root() . '/database/migrations';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $output->error(sprintf('Could not create %s.', $directory));

            return self::FAILURE;
        }

        $file = sprintf('%s/%s_%s.php', $directory, date('Y_m_d_His'), $slug);

        if (file_exists($file)) {
            // Only reachable by making two migrations with the same name inside
            // one second, but silently overwriting the first would be the worst
            // possible answer to that.
            $output->error(sprintf('%s already exists.', basename($file)));

            return self::FAILURE;
        }

        if (file_put_contents($file, $this->template()) === false) {
            $output->error(sprintf('Could not write %s.', $file));

            return self::FAILURE;
        }

        $output->success('Created ' . basename($file));
        $output->comment('  ' . $file);

        return self::SUCCESS;
    }

    private function template(): string {
        return <<<'PHP'
            <?php

            // A migration file declares no namespace, so PDO is already the
            // global one - a `use PDO;` here would warn on every run that it
            // has no effect.
            use App\Core\Database\Migrations\Migration;

            return new class extends Migration {
                public function up(PDO $db): void {
                    // $db->exec('CREATE TABLE example (id INT PRIMARY KEY)');
                }

                public function down(PDO $db): void {
                    // $db->exec('DROP TABLE example');
                }
            };

            PHP;
    }
}
