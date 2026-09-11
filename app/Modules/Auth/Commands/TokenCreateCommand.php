<?php

namespace Modules\Auth\Commands;

use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use Modules\Auth\ApiTokens;
use Modules\Auth\UserRepository;

final class TokenCreateCommand extends Command {
    protected string $name = 'token:create';
    protected string $description = 'Issues an API token for a user.';
    protected string $help = <<<'HELP'
        Usage: php tty token:create <email> [name]

          php tty token:create jan@example.cz "mobile app"

        The token is printed once and stored only as a hash, so it cannot be shown
        again. Send it as `Authorization: Bearer <token>`.
        HELP;

    public function __construct(private UserRepository $users, private ApiTokens $tokens) {
    }

    public function run(Input $input, Output $output): int {
        $email = (string) $input->argument(0);
        $user = $email === '' ? null : $this->users->findByEmail($email);

        if ($user === null) {
            $output->error($email === '' ? 'Give the email of the user the token is for.' : sprintf('There is no user %s.', $email));

            return self::FAILURE;
        }

        $token = $this->tokens->issue($user, $input->argument(1) ?? 'default');

        $output->success(sprintf('Issued a token for %s.', $user->email));
        $output->line($token);
        $output->comment('Shown once - it is stored only as a hash.');

        return self::SUCCESS;
    }
}
