<?php

namespace Modules\Auth\Commands;

use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use Modules\Auth\UserRepository;

final class UserCreateCommand extends Command {
    protected string $name = 'user:create';
    protected string $description = 'Creates a user account.';
    protected string $help = <<<'HELP'
        Usage: php tty user:create <email> [--name="Jan Novák"] [--password-stdin]

        Without --password-stdin a random password is generated and printed once.

          echo 'correct horse battery' | php tty user:create jan@example.cz --password-stdin

        There is deliberately no --password=... option: a password on the command
        line ends up in the shell history and in the process list, where anyone
        on the machine can read it.
        HELP;

    private const MIN_LENGTH = 8;

    public function __construct(private UserRepository $users) {
    }

    public function run(Input $input, Output $output): int {
        $email = trim((string) $input->argument(0));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->error('Give a valid email address, e.g. `php tty user:create jan@example.cz`.');

            return self::FAILURE;
        }

        if ($this->users->findByEmail($email) !== null) {
            $output->error(sprintf('A user with %s already exists.', UserRepository::normalizeEmail($email)));

            return self::FAILURE;
        }

        $generated = !$input->hasOption('password-stdin');
        $password = $generated ? self::generatePassword() : rtrim((string) fgets(STDIN), "\r\n");

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $output->error(sprintf('The password must be at least %d characters.', self::MIN_LENGTH));

            return self::FAILURE;
        }

        $name = $input->option('name');
        $user = $this->users->create($email, $password, is_string($name) && $name !== '' ? $name : null);

        $output->success(sprintf('Created user #%d, %s.', $user->id, $user->email));

        if ($generated) {
            $output->line('Password: ' . $password);
            $output->comment('Shown once - it is stored only as a hash.');
        }

        return self::SUCCESS;
    }

    /** 20 characters from an unambiguous alphabet - no 0/O, 1/l/I. */
    private static function generatePassword(): string {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < 20; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
