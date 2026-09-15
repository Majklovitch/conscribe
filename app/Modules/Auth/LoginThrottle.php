<?php

namespace Modules\Auth;

use App\Core\Database\LazyConnection;
use PDO;

/**
 * Limits failed logins, so a password cannot be guessed by trying all of them.
 *
 * Counted per account *and* address. Per account alone would let anyone lock a
 * real user out by typing their email wrong five times; per address alone would
 * let one machine try a handful of passwords against every account. The pair is
 * the usual compromise, and what OWASP recommends as a baseline.
 *
 * The window is fixed from the first failure: five misses within fifteen minutes
 * lock that pair until the window ends. A successful login clears the count.
 */
final class LoginThrottle {
    use LazyConnection;

    public const TABLE = 'login_attempts';

    public function __construct(
        ?PDO $db = null,
        private int $maxAttempts = 5,
        private int $decaySeconds = 900,
    ) {
        $this->db = $db;
    }

    public static function keyFor(string $email, string $ip): string {
        return hash('sha256', UserRepository::normalizeEmail($email) . '|' . $ip);
    }

    /** Seconds until this key may try again; 0 when it may try now. */
    public function availableIn(string $key): int {
        $row = $this->row($key);
        if ($row === null) {
            return 0;
        }

        $remaining = (int) $row['expires_at'] - time();
        if ($remaining <= 0) {
            return 0;
        }

        return (int) $row['attempts'] >= $this->maxAttempts ? $remaining : 0;
    }

    public function hit(string $key): void {
        $row = $this->row($key);
        $now = time();

        // Select-then-write rather than an upsert, which MySQL and SQLite
        // spell differently. Two failures racing each other can at worst
        // count as one, which errs on the side of the person logging in.
        if ($row === null) {
            $this->db()->prepare(sprintf('INSERT INTO %s (throttle_key, attempts, expires_at) VALUES (?, 1, ?)', self::TABLE))
                ->execute([$key, $now + $this->decaySeconds]);

            return;
        }

        if ((int) $row['expires_at'] <= $now) {
            $this->db()->prepare(sprintf('UPDATE %s SET attempts = 1, expires_at = ? WHERE throttle_key = ?', self::TABLE))
                ->execute([$now + $this->decaySeconds, $key]);

            return;
        }

        $this->db()->prepare(sprintf('UPDATE %s SET attempts = attempts + 1 WHERE throttle_key = ?', self::TABLE))
            ->execute([$key]);
    }

    public function clear(string $key): void {
        $this->db()->prepare(sprintf('DELETE FROM %s WHERE throttle_key = ?', self::TABLE))->execute([$key]);
    }

    /** @return array{attempts: int|string, expires_at: int|string}|null */
    private function row(string $key): ?array {
        $statement = $this->db()->prepare(sprintf('SELECT attempts, expires_at FROM %s WHERE throttle_key = ?', self::TABLE));
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
