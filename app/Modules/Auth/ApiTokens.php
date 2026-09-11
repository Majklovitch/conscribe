<?php

namespace Modules\Auth;

use App\Core\Database\Connection;
use PDO;

/**
 * Personal access tokens for the API.
 *
 * A token is 256 random bits with a recognisable prefix (`cst_`), so one pasted
 * into the wrong place - a commit, a ticket - is easy to spot and to search for.
 * Only its SHA-256 is stored and the plaintext is returned exactly once, when
 * the token is issued.
 *
 * SHA-256 rather than password_hash() on purpose. A password is short and
 * guessable, so it needs a deliberately slow hash; a token is already 256 bits
 * of randomness, where a slow hash buys nothing - and lookup has to be by the
 * hash itself, which only a deterministic hash allows.
 */
final class ApiTokens {
    public const TABLE = 'api_tokens';

    private const PREFIX = 'cst_';

    public function __construct(private UserRepository $users, private ?PDO $db = null) {
    }

    /**
     * The connection, on first use.
     *
     * Lazily, so injecting this service opens nothing: a page that only asks
     * whether anyone is signed in must not connect to the database at all.
     */
    private function db(): PDO {
        return $this->db ??= Connection::get();
    }

    /** Issues a token and returns its plaintext - the only time it exists. */
    public function issue(User $user, string $name): string {
        $plain = self::PREFIX . bin2hex(random_bytes(32));

        $this->db()->prepare(sprintf(
            'INSERT INTO %s (user_id, name, token_hash, created_at) VALUES (?, ?, ?, ?)',
            self::TABLE,
        ))->execute([$user->id, $name, self::hash($plain), date('Y-m-d H:i:s')]);

        return $plain;
    }

    /**
     * The user a token belongs to, or null.
     *
     * A token whose user has been deleted resolves to null rather than to a
     * ghost - there is no foreign key to cascade, so this is where it is
     * enforced.
     */
    public function userFor(string $plain): ?User {
        if (!str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $statement = $this->db()->prepare(sprintf('SELECT id, user_id FROM %s WHERE token_hash = ?', self::TABLE));
        $statement->execute([self::hash($plain)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $this->db()->prepare(sprintf('UPDATE %s SET last_used_at = ? WHERE id = ?', self::TABLE))
            ->execute([date('Y-m-d H:i:s'), $row['id']]);

        return $this->users->findById((int) $row['user_id']);
    }

    public function revoke(string $plain): bool {
        $statement = $this->db()->prepare(sprintf('DELETE FROM %s WHERE token_hash = ?', self::TABLE));
        $statement->execute([self::hash($plain)]);

        return $statement->rowCount() > 0;
    }

    /** Revokes every token a user has - what "sign out everywhere" needs. */
    public function revokeAll(User $user): int {
        $statement = $this->db()->prepare(sprintf('DELETE FROM %s WHERE user_id = ?', self::TABLE));
        $statement->execute([$user->id]);

        return $statement->rowCount();
    }

    private static function hash(string $plain): string {
        return hash('sha256', $plain);
    }
}
