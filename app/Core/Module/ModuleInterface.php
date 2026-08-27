<?php
namespace App\Core\Module;

use App\Core\Http\Request;
use App\Core\Http\Router;

/**
 * The module contract. A module is a self-contained directory in app/Modules/
 * with a Module.php file, which can be added or deleted without touching the
 * core or index.php.
 */
interface ModuleInterface {
    /**
     * Module name (matches the directory name).
     */
    public function name(): string;

    /**
     * Runs before routing. Returns the request - a module may modify it, for
     * instance by stripping its own prefix from the path (/en/...).
     */
    public function boot(Request $request): Request;

    public function registerRoutes(Router $router): void;

    /**
     * Absolute path to the file with the global functions of the module, or null.
     */
    public function helpers(): ?string;
}
