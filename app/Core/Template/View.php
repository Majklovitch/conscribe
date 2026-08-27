<?php
namespace App\Core\Template;

use Throwable;

class View {
    private const PATH_PATTERN = '#^[A-Za-z0-9_\-/]+$#';

    public static function exists(string $viewPath): bool {
        return self::resolve($viewPath) !== null;
    }

    /**
     * Renders a template, optionally wrapped in the header and footer,
     * and returns the HTML.
     *
     * @throws ViewNotFoundException when the template or the layout is missing
     * @throws Throwable
     */
    public static function render(string $viewPath, array $data = [], string $layout = 'main'): string {
        $viewFile = self::resolve($viewPath);
        if ($viewFile === null) {
            throw new ViewNotFoundException("View '{$viewPath}' was not found.");
        }

        $content = self::capture($viewFile, $data);

        if ($layout !== 'main') {
            return $content;
        }

        return self::capture(self::layoutFile('header'), $data)
            . $content
            . self::capture(self::layoutFile('footer'), $data);
    }

    private static function viewsDir(): string {
        return dirname(__DIR__, 2) . '/Views';
    }

    /**
     * Turns a template name into an absolute path, or null when it does not
     * exist or contains disallowed characters (path traversal protection).
     */
    private static function resolve(string $viewPath): ?string {
        $viewPath = trim($viewPath, '/');

        if ($viewPath === '' || str_contains($viewPath, '..') || !preg_match(self::PATH_PATTERN, $viewPath)) {
            return null;
        }

        $file = self::viewsDir() . '/' . $viewPath . '.php';

        return is_file($file) ? $file : null;
    }

    /**
     * @throws ViewNotFoundException
     */
    private static function layoutFile(string $name): string {
        $file = self::viewsDir() . '/layout/' . $name . '.php';
        if (!is_file($file)) {
            throw new ViewNotFoundException("Layout '{$name}' was not found.");
        }
        return $file;
    }

    /**
     * Runs a PHP file with the given data and captures its output.
     * Variables are extracted with EXTR_SKIP so they cannot overwrite
     * internal state.
     */
    private static function capture(string $__file, array $__data): string {
        ob_start();
        try {
            extract($__data, EXTR_SKIP);
            require $__file;
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
