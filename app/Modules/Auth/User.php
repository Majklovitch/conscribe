<?php

namespace Modules\Auth;

use App\Core\Database\Model;

final class User extends Model {
    public int $id = 0;
    public string $email = '';
    public ?string $name = null;
    public string $created_at = '';
    public string $updated_at = '';

    /**
     * Private, so the hash is in neither toArray() nor the JSON nor array
     * access. Model would otherwise put the `password` column in its bag of
     * loose attributes, and `json_encode($user)` in any API response would
     * publish every user's hash.
     */
    private string $passwordHash = '';

    public function offsetSet(mixed $offset, mixed $value): void {
        if ($offset === 'password') {
            $this->passwordHash = (string) $value;

            return;
        }

        parent::offsetSet($offset, $value);
    }

    public function passwordHash(): string {
        return $this->passwordHash;
    }
}
