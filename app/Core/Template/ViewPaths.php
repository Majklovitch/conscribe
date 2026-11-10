<?php

namespace App\Core\Template;

use App\Core\Module\ModuleManager;

/**
 * Where templates live.
 *
 *   orders/show          app/Views/orders/show.php (core)
 *   admin::orders/show   app/Modules/Admin/Views/orders/show.php
 *
 * A namespace is the lower-cased module name and has a list of directories,
 * searched in order - so a theme or the application can put its own
 * directory in front of a module's without the callers changing. Enabled modules register
 * their Views/ folder the first time a namespaced name is looked up.
 */
final class ViewPaths {
    public const SEPARATOR = '::';

    private const NAMESPACE_PATTERN = '#^[a-z][a-z0-9_]*$#';
    private const NAME_PATTERN = '#^[A-Za-z0-9_\-/]+$#';

    /** @var array<string, string[]> */
    private static array $namespaces = [];
    private static bool $modulesLoaded = false;

    /**
     * Adds a directory to a namespace. $prepend puts it before the existing
     * ones (an override), otherwise it is searched last.
     */
    public static function register(string $namespace, string $directory, bool $prepend = false): void {
        $directory = rtrim($directory, '/');
        $current = array_values(array_diff(self::$namespaces[$namespace] ?? [], [$directory]));

        self::$namespaces[$namespace] = $prepend ? [$directory, ...$current] : [...$current, $directory];
    }

    /**
     * Absolute path of a template, or null when it does not exist or the
     * name is not allowed (path traversal, unknown characters).
     *
     * @param string $suffix appended to the name, e.g. '.php' or '.html.php'
     * @param string $subdirectory inside a module's Views/, e.g. 'emails'
     */
    public static function resolve(string $name, string $suffix = '.php', string $subdirectory = ''): ?string {
        [$namespace, $path] = self::split($name);
        if ($path === null) {
            return null;
        }

        $directories = $namespace === null
            ? [self::coreDir() . ($subdirectory === '' ? '' : '/' . $subdirectory)]
            : array_map(
                static fn (string $directory): string => $directory . ($subdirectory === '' ? '' : '/' . $subdirectory),
                self::directories($namespace),
            );

        foreach ($directories as $directory) {
            $file = $directory . '/' . $path . $suffix;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The directories of a namespace, in lookup order.
     *
     * @return string[]
     */
    public static function directories(string $namespace): array {
        self::loadModules();

        return self::$namespaces[$namespace] ?? [];
    }

    /**
     * @return string[]
     */
    public static function namespaces(): array {
        self::loadModules();

        return array_keys(self::$namespaces);
    }

    public static function coreDir(): string {
        return dirname(__DIR__, 2) . '/Views';
    }

    /**
     * Forgets every registration (tests).
     */
    public static function reset(): void {
        self::$namespaces = [];
        self::$modulesLoaded = false;
    }

    /**
     * Splits 'admin::orders/show' into ['admin', 'orders/show'] and a core
     * name into [null, name]; the path is null when the name is not valid.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function split(string $name): array {
        $namespace = null;
        if (str_contains($name, self::SEPARATOR)) {
            [$namespace, $name] = explode(self::SEPARATOR, $name, 2);
            if (!preg_match(self::NAMESPACE_PATTERN, $namespace)) {
                return [null, null];
            }
        }

        $name = trim($name, '/');
        if ($name === '' || str_contains($name, '..') || !preg_match(self::NAME_PATTERN, $name)) {
            return [$namespace, null];
        }

        return [$namespace, $name];
    }

    private static function loadModules(): void {
        if (self::$modulesLoaded) {
            return;
        }
        self::$modulesLoaded = true;

        foreach ((new ModuleManager())->modules() as $module) {
            $directory = $module->views();
            if ($directory !== null) {
                self::register(strtolower($module->name()), $directory);
            }
        }
    }
}
