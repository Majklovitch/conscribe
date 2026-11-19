<?php

namespace App\Core;

class Config {
    /** The file config (main.php / main.prod.php), or what a test set. */
    private static ?array $data = null;

    /**
     * Values applied on top of the file, flat 'dot.key' => value - e.g.
     * settings an application keeps in its database. A key here replaces the
     * file value at that path as a whole (a list is not merged).
     *
     * @var array<string, mixed>
     */
    private static array $overlay = [];

    /** The file config with the overlay applied; rebuilt when either changes. */
    private static ?array $merged = null;

    /**
     * Picks main.prod.php or main.php, so one checkout can hold the local
     * config and the production one side by side - neither is committed.
     *
     *   APP_ENV=production            main.prod.php
     *   APP_ENV=development|local     main.php
     *   no APP_ENV, web request       main.php on a local host (localhost,
     *                                 an IP, *.local, *.test), else main.prod.php
     *   no APP_ENV, console           main.php - a dev machine must never reach
     *                                 the production DB by accident, so a
     *                                 production cron sets APP_ENV=production
     *
     * Either file falls back to the other when it does not exist, so a site
     * with only main.php behaves exactly as before. A production site reached
     * by a bare IP counts as local; set APP_ENV there.
     */
    public static function path(): string {
        $dir  = dirname(__DIR__) . '/Config';
        $dev  = $dir . '/main.php';
        $prod = $dir . '/main.prod.php';

        $preferred = self::wantsProduction() ? $prod : $dev;
        if (is_file($preferred)) {
            return $preferred;
        }

        return $preferred === $prod ? $dev : $prod;
    }

    private static function wantsProduction(): bool {
        $env = getenv('APP_ENV') ?: null;
        if ($env !== null) {
            return $env === 'production';
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if (PHP_SAPI === 'cli' || $host === '') {
            return false;
        }

        return !self::isLocalHost($host);
    }

    /** localhost, any IP literal, *.local and *.test - with or without a port. */
    private static function isLocalHost(string $host): bool {
        $host = strtolower(trim($host));
        // "[::1]:8080" - drop the port after the brackets, then the brackets.
        $host = preg_match('/^\[(.*)\](?::\d+)?$/', $host, $m) ? $m[1] : preg_replace('/:\d+$/', '', $host);

        return in_array($host, ['localhost', '0.0.0.0'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * The effective configuration: the file, then the overlay on top.
     */
    public static function all(): array {
        if (self::$merged === null) {
            $merged = self::file();
            foreach (self::$overlay as $key => $value) {
                self::assign($merged, explode('.', (string) $key), $value);
            }
            self::$merged = $merged;
        }

        return self::$merged;
    }

    /**
     * The file config alone, without the overlay.
     */
    public static function file(): array {
        if (self::$data === null) {
            $path = self::path();
            $data = is_file($path) ? require $path : [];
            self::$data = is_array($data) ? $data : [];
        }

        return self::$data;
    }

    /**
     * A value from the file config alone (dot notation), ignoring the overlay.
     */
    public static function fileValue(string $key, mixed $default = null): mixed {
        return self::dig(self::file(), $key, $default);
    }

    /**
     * Replaces the overlay ('dot.key' => value). Call it once per request or
     * console run, before anything reads the keys it changes.
     *
     * @param array<string, mixed> $values
     */
    public static function overlay(array $values): void {
        self::$overlay = $values;
        self::$merged = null;
    }

    public static function get(string $key, mixed $default = null): mixed {
        return self::dig(self::all(), $key, $default);
    }

    private static function dig(array $value, string $key, mixed $default): mixed {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function has(string $key): bool {
        return self::get($key, $sentinel = new \stdClass()) !== $sentinel;
    }

    /**
     * Replaces the file config (tests). Also drops the overlay, so a test that
     * seeds the config sees exactly what it seeded.
     */
    public static function set(?array $data): void {
        self::$data = $data;
        self::$overlay = [];
        self::$merged = null;
    }

    /**
     * @param list<string> $path
     */
    private static function assign(array &$target, array $path, mixed $value): void {
        $segment = array_shift($path);
        if ($path === []) {
            $target[$segment] = $value;

            return;
        }

        if (!isset($target[$segment]) || !is_array($target[$segment])) {
            $target[$segment] = [];
        }
        self::assign($target[$segment], $path, $value);
    }
}
