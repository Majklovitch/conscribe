<?php

namespace App\Core\Http;

use App\Core\Config;

/**
 * Owns the PHP session lifecycle: cookie parameters, flash messages,
 * old form input and the CSRF token.
 *
 * The session starts lazily - only on the first read or write. A visitor who
 * never touches the session therefore gets no cookie, and their page stays
 * cacheable on proxies and CDNs.
 */
class Session
{
    /** Keys owned by Session itself; they never show up in all(). */
    private const RESERVED_KEYS = ['_flash', '_old_input', '_session_born', 'csrf_token'];

    private const FLASH_KEY = '_flash';
    private const OLD_INPUT_KEY = '_old_input';
    private const BORN_KEY = '_session_born';
    private const TOKEN_KEY = 'csrf_token';

    /** Defaults, so the class works even without a 'session' config section. */
    private const DEFAULTS = [
        'name'                => 'CONSCRIBEID',
        'lifetime'            => 0,
        'path'                => '/',
        'domain'              => '',
        'secure'              => null,
        'httponly'            => true,
        'samesite'            => 'Lax',
        'regenerate_interval' => 1800,
    ];

    private static ?self $instance = null;

    private array $config;
    private bool $flashAged = false;
    private bool $startFailed = false;

    /**
     * @param array<string, mixed> $config Overrides on top of DEFAULTS.
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(self::DEFAULTS, $config);
    }

    /**
     * Shared instance for the global template helpers, which have no object
     * in reach - the same principle as Config or Connection::get().
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            $config = Config::get('session', []);
            self::$instance = new self(is_array($config) ? $config : []);
        }

        return self::$instance;
    }

    /**
     * Sets (or clears) the shared instance. Meant for tests,
     * the counterpart of Config::set().
     */
    public static function setInstance(?self $session): void
    {
        self::$instance = $session;
    }

    /**
     * Starts the session unless it is already running. Returns false when that
     * is impossible - after headers are sent, or in CLI. The caller then simply
     * learns nothing instead of crashing mid-render.
     */
    public function start(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->afterStart();

            return true;
        }

        if ($this->startFailed || headers_sent()) {
            $this->startFailed = true;

            return false;
        }

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');

        if ($this->config['name'] !== '') {
            session_name((string) $this->config['name']);
        }

        session_set_cookie_params($this->cookieParams());

        if (!@session_start()) {
            $this->startFailed = true;

            return false;
        }

        $this->afterStart();

        return true;
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function id(): ?string
    {
        if (!$this->start()) {
            return null;
        }

        $id = session_id();

        return $id === false || $id === '' ? null : $id;
    }

    /**
     * Swaps the session ID while keeping the data - defence against session
     * fixation. Worth calling manually after a login.
     */
    public function regenerate(bool $deleteOld = true): bool
    {
        if (!$this->start() || headers_sent()) {
            return false;
        }

        if (!session_regenerate_id($deleteOld)) {
            return false;
        }

        $_SESSION[self::BORN_KEY] = time();

        return true;
    }

    public function destroy(): void
    {
        if (!$this->isStarted() && !$this->start()) {
            return;
        }

        $_SESSION = [];

        if (!headers_sent() && ini_get('session.use_cookies')) {
            // setcookie() takes 'expires', not 'lifetime' - other keys are reported as unknown.
            $params = $this->cookieParams();
            unset($params['lifetime']);
            $params['expires'] = time() - 42000;
            setcookie(session_name(), '', $params);
        }

        session_destroy();
        $this->flashAged = false;
    }

    // --- Data ---------------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->start()) {
            return $default;
        }

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): self
    {
        if ($this->start()) {
            $_SESSION[$key] = $value;
        }

        return $this;
    }

    public function has(string $key): bool
    {
        return $this->start() && isset($_SESSION[$key]);
    }

    public function remove(string $key): self
    {
        if ($this->start()) {
            unset($_SESSION[$key]);
        }

        return $this;
    }

    /**
     * Reads a value and removes it from the session in one go.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * Application data without Session's own bookkeeping.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (!$this->start()) {
            return [];
        }

        return array_diff_key($_SESSION, array_flip(self::RESERVED_KEYS));
    }

    /**
     * Drops the application data, keeping the internal keys (flash, CSRF token).
     */
    public function clear(): self
    {
        if (!$this->start()) {
            return $this;
        }

        foreach (array_keys($this->all()) as $key) {
            unset($_SESSION[$key]);
        }

        return $this;
    }

    // --- Flash --------------------------------------------------------------

    /**
     * Stores a value for the next request only.
     */
    public function flash(string $key, mixed $value): self
    {
        if ($this->start()) {
            $_SESSION[self::FLASH_KEY]['new'][$key] = $value;
        }

        return $this;
    }

    /**
     * Reads from both 'old' (written by the previous request) and 'new', so that
     * flashing and reading within a single request works too.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        if (!$this->start()) {
            return $default;
        }

        return $_SESSION[self::FLASH_KEY]['old'][$key]
            ?? $_SESSION[self::FLASH_KEY]['new'][$key]
            ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        if (!$this->start()) {
            return false;
        }

        return isset($_SESSION[self::FLASH_KEY]['old'][$key])
            || isset($_SESSION[self::FLASH_KEY]['new'][$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function allFlash(): array
    {
        if (!$this->start()) {
            return [];
        }

        $flash = $_SESSION[self::FLASH_KEY]['old'] + $_SESSION[self::FLASH_KEY]['new'];
        unset($flash[self::OLD_INPUT_KEY]);

        return $flash;
    }

    /**
     * Extends the lifetime of flash values by one more request.
     *
     * @param string[]|null $keys null = all of them
     */
    public function keepFlash(?array $keys = null): self
    {
        if (!$this->start()) {
            return $this;
        }

        $old = $_SESSION[self::FLASH_KEY]['old'];
        $keep = $keys === null ? $old : array_intersect_key($old, array_flip($keys));

        $_SESSION[self::FLASH_KEY]['new'] = $keep + $_SESSION[self::FLASH_KEY]['new'];

        return $this;
    }

    // --- Old form input -----------------------------------------------------

    /**
     * Keeps the submitted data for one request so the form can be pre-filled
     * after an error. Typically $request->getBodyParams().
     *
     * @param array<string, mixed> $input
     */
    public function flashInput(array $input): self
    {
        // The CSRF token does not belong in a re-fill, it is always generated anew.
        unset($input[self::TOKEN_KEY]);

        return $this->flash(self::OLD_INPUT_KEY, $input);
    }

    public function old(string $key, mixed $default = ''): mixed
    {
        $input = $this->getFlash(self::OLD_INPUT_KEY, []);

        return is_array($input) ? ($input[$key] ?? $default) : $default;
    }

    // --- CSRF ---------------------------------------------------------------

    /**
     * Token for the current session, generated on the first call.
     */
    public function token(): string
    {
        if (!$this->start()) {
            return '';
        }

        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::TOKEN_KEY];
    }

    public function validateToken(?string $token): bool
    {
        if (!$this->isStarted() && !$this->start()) {
            return false;
        }

        $expected = $_SESSION[self::TOKEN_KEY] ?? '';

        if ($token === null || $token === '' || $expected === '') {
            return false;
        }

        return hash_equals((string) $expected, $token);
    }

    /**
     * Drops the current token; the next token() generates a new one.
     */
    public function rotateToken(): self
    {
        if ($this->start()) {
            unset($_SESSION[self::TOKEN_KEY]);
        }

        return $this;
    }

    // --- Internals ----------------------------------------------------------

    /**
     * Ages the flash queues and rotates the ID if due. Runs once per request.
     */
    private function afterStart(): void
    {
        if ($this->flashAged) {
            return;
        }

        $this->flashAged = true;

        $flash = $_SESSION[self::FLASH_KEY] ?? [];
        $_SESSION[self::FLASH_KEY] = [
            'old' => is_array($flash['new'] ?? null) ? $flash['new'] : [],
            'new' => [],
        ];

        if (!isset($_SESSION[self::BORN_KEY])) {
            $_SESSION[self::BORN_KEY] = time();

            return;
        }

        $interval = (int) $this->config['regenerate_interval'];
        if ($interval > 0 && time() - (int) $_SESSION[self::BORN_KEY] > $interval) {
            $this->regenerate();
        }
    }

    /**
     * Cookie parameters. 'secure' => null means auto-detection from the protocol,
     * the same as Response::withCookie() does.
     *
     * @return array<string, mixed>
     */
    private function cookieParams(): array
    {
        return [
            'lifetime' => (int) $this->config['lifetime'],
            'path'     => (string) $this->config['path'],
            'domain'   => (string) $this->config['domain'],
            'secure'   => $this->config['secure'] === null
                ? Request::isSecure()
                : (bool) $this->config['secure'],
            'httponly' => (bool) $this->config['httponly'],
            'samesite' => (string) $this->config['samesite'],
        ];
    }
}
