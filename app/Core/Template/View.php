<?php
namespace App\Core\Template;

use Throwable;

class View {
    private const PATH_PATTERN = '#^[A-Za-z0-9_\-/]+$#';

    public static function exists(string $viewPath): bool {
        return self::resolve($viewPath) !== null;
    }

    /**
     * Vykreslí šablonu (volitelně obalenou hlavičkou a patičkou) a vrátí HTML.
     *
     * @throws ViewNotFoundException pokud šablona nebo layout neexistuje
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

    /**
     * Adresář se šablonami (app/Views).
     */
    private static function viewsDir(): string {
        return dirname(__DIR__, 2) . '/Views';
    }

    /**
     * Převede název šablony na absolutní cestu, nebo null pokud neexistuje
     * či obsahuje nepovolené znaky (ochrana proti path traversal).
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
     * Cesta k souboru layoutu.
     *
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
     * Spustí PHP soubor s předanými daty a zachytí jeho výstup.
     * Proměnné se vkládají s EXTR_SKIP, aby nemohly přepsat interní stav.
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
