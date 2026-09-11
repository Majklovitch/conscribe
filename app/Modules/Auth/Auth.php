<?php

namespace Modules\Auth;

use App\Core\Http\Session;

/**
 * Who is signed in.
 *
 * Two guards feed it. The session guard is built in: a successful attempt()
 * stores the user's id in the session, and user() loads it back on later
 * requests. The token guard is RequireToken, which resolves a bearer token and
 * hands the user over through setUser(). Either way a controller asks the same
 * question - `$auth->user()` - and does not care how the answer arrived.
 */
final class Auth {
    private const SESSION_KEY = '_auth_user_id';
    private const INTENDED_KEY = '_auth_intended';

    private static ?string $dummyHash = null;

    private ?User $user = null;
    private bool $resolved = false;

    public function __construct(
        private UserRepository $users,
        private Session $session,
        private LoginThrottle $throttle,
    ) {
    }

    /**
     * Checks credentials and, when they hold, signs the user in.
     *
     * $ip is the client address, which the throttle keys on together with the
     * email - pass $request->getClientIp().
     */
    public function attempt(string $email, string $password, string $ip = ''): LoginResult {
        $key = LoginThrottle::keyFor($email, $ip);

        if ($this->throttle->availableIn($key) > 0) {
            return LoginResult::Throttled;
        }

        $user = $this->users->findByEmail($email);

        // Verified against a throwaway hash when there is no such account, so
        // a missing email takes as long to reject as a wrong password. Without
        // it the response time alone tells an attacker which emails exist.
        $verified = password_verify($password, $user?->passwordHash() ?? $this->dummyHash());

        if ($user === null || !$verified) {
            $this->throttle->hit($key);

            return LoginResult::Failed;
        }

        $this->throttle->clear($key);

        // Upgraded at the one moment the plaintext is available: a hash made
        // with an older algorithm or a lower cost is replaced, so raising PHP's
        // default strengthens each account as its owner next signs in.
        if ($this->users->needsRehash($user->passwordHash())) {
            $this->users->updatePasswordHash($user, $this->users->hashPassword($password));
        }

        $this->login($user);

        return LoginResult::Success;
    }

    public function login(User $user): void {
        // A new session id on every change of privilege: an id planted in the
        // visitor's browser before login (session fixation) must not become an
        // authenticated one.
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $user->id);

        // And a fresh CSRF token, so one lifted from the anonymous page is
        // worthless afterwards.
        $this->session->rotateToken();

        $this->user = $user;
        $this->resolved = true;
    }

    public function logout(): void {
        $this->session->remove(self::SESSION_KEY);
        $this->session->regenerate();
        $this->session->rotateToken();

        $this->user = null;
        $this->resolved = true;
    }

    /**
     * The signed-in user, or null.
     *
     * Loaded again on each request rather than kept in the session, so a
     * deleted account stops being signed in at once instead of lingering as a
     * copy.
     */
    public function user(): ?User {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        // Reading from the session would start one, and starting one sends a
        // cookie. A visitor who never had a session is not signed in, and is
        // not handed a session just because a page asked - which a "Sign in /
        // Your account" link in every page header would otherwise do.
        if (!$this->session->exists()) {
            return null;
        }

        $id = $this->session->get(self::SESSION_KEY);

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        return $this->user = $this->users->findById((int) $id);
    }

    public function check(): bool {
        return $this->user() !== null;
    }

    public function id(): ?int {
        return $this->user()?->id;
    }

    /**
     * Sets the user for this request only, without touching the session.
     *
     * What RequireToken uses: an API request authenticated by a token must not
     * start a session, or every response would carry a cookie the client never
     * asked for.
     */
    public function setUser(?User $user): void {
        $this->user = $user;
        $this->resolved = true;
    }

    /** Remembers where a visitor was going before being sent to log in. */
    public function rememberIntended(string $url): void {
        $this->session->set(self::INTENDED_KEY, $url);
    }

    /**
     * Where to send a user after logging in: the page they were stopped at,
     * or $default. Read once, so a second login goes to $default.
     *
     * Pass it to Controller::redirect(), which refuses anything off-site, so a
     * value planted in the session cannot become an open redirect.
     */
    public function intended(string $default = '/'): string {
        $url = $this->session->pull(self::INTENDED_KEY);

        return is_string($url) && $url !== '' ? $url : $default;
    }

    /**
     * A hash to verify against when the account does not exist.
     *
     * Made through UserRepository so it carries the same cost as real ones,
     * and made once per process: hashing is the slow part, and paying it on
     * every unknown email would make those slower than a wrong password - the
     * very difference this exists to hide.
     */
    private function dummyHash(): string {
        return self::$dummyHash ??= $this->users->hashPassword(bin2hex(random_bytes(16)));
    }
}
