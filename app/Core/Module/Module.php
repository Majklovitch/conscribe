<?php
namespace App\Core\Module;

use App\Core\Http\Request;
use App\Core\Http\Router;
use ReflectionClass;

/**
 * Výchozí implementace modulu – potomek přepíše jen to, co skutečně potřebuje.
 */
abstract class Module implements ModuleInterface {
    /**
     * Adresář, ve kterém modul leží.
     */
    public function path(): string {
        return dirname((new ReflectionClass(static::class))->getFileName());
    }

    public function name(): string {
        return basename($this->path());
    }

    public function boot(Request $request): Request {
        return $request;
    }

    public function registerRoutes(Router $router): void {
    }

    public function helpers(): ?string {
        $file = $this->path() . '/helpers.php';

        return is_file($file) ? $file : null;
    }
}
