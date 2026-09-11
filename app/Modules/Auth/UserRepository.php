<?php

namespace Modules\Auth;

use App\Core\Config;
use App\Core\Database\Repository;
use RuntimeException;

final class UserRepository extends Repository {
    protected string $table = 'users';
    protected string $model = User::class;

    /**
     * The form every email is stored and looked up in.
     *
     * Without it, `Jan@Example.cz` and `jan@example.cz` would be two accounts
     * - and a login would fail on nothing more than how the address was typed.
     */
    public static function normalizeEmail(string $email): string {
        return mb_strtolower(trim($email));
    }

    public function findByEmail(string $email): ?User {
        $user = $this->first(['email' => self::normalizeEmail($email)]);

        return $user instanceof User ? $user : null;
    }

    public function findById(int $id): ?User {
        $user = $this->find($id);

        return $user instanceof User ? $user : null;
    }

    /**
     * Creates a user from a plaintext password, which is hashed here and
     * nowhere else - so no caller can store one unhashed by accident.
     */
    public function create(string $email, string $password, ?string $name = null): User {
        $now = date('Y-m-d H:i:s');

        $id = $this->insert([
            'email' => self::normalizeEmail($email),
            'password' => $this->hashPassword($password),
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $id)
            ?? throw new RuntimeException(sprintf('User %s was inserted but cannot be read back.', $id));
    }

    /**
     * The hashing policy, in one place.
     *
     * Creating an account, upgrading an old hash and the timing decoy Auth
     * uses for unknown emails all go through here. The decoy is the reason it
     * matters: if it were hashed at a different cost than real passwords, an
     * unknown email would answer measurably faster or slower than a wrong
     * password, and the difference would say which accounts exist.
     *
     * The cost comes from 'auth.hash_options' - PHP's default when unset.
     */
    public function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT, $this->hashOptions());
    }

    public function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, $this->hashOptions());
    }

    /** @return array<string, int> */
    private function hashOptions(): array {
        $options = Config::get('auth.hash_options', []);

        return is_array($options) ? $options : [];
    }

    public function updatePasswordHash(User $user, string $hash): void {
        $this->update($user->id, ['password' => $hash, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
