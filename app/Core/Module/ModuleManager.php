<?php
namespace App\Core\Module;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Router;

/**
 * Finds the modules in app/Modules/ and hooks them into the request.
 *
 * A module = a directory with a Module.php file holding the class
 * Modules\<Directory>\Module. The list of enabled modules can be narrowed with
 * the 'modules' key in app/Config/main.php; without it, everything found is on.
 */
class ModuleManager {
    private string $modulesPath;

    /**
     * @var ModuleInterface[]|null
     */
    private ?array $modules = null;

    public function __construct(?string $modulesPath = null) {
        $this->modulesPath = $modulesPath ?? dirname(__DIR__, 2) . '/Modules';
    }

    /**
     * @return ModuleInterface[]
     */
    public function modules(): array {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $enabled = Config::get('modules');
        $enabled = is_array($enabled) ? $enabled : null;

        $modules = [];

        foreach ($this->directories() as $directory) {
            $name = basename($directory);

            if ($enabled !== null && !in_array($name, $enabled, true)) {
                continue;
            }

            if (!is_file($directory . '/Module.php')) {
                continue;
            }

            $class = 'Modules\\' . $name . '\\Module';
            if (!class_exists($class) || !is_subclass_of($class, ModuleInterface::class)) {
                continue;
            }

            $modules[$name] = new $class();
        }

        return $this->modules = $modules;
    }

    /**
     * @return string[]
     */
    private function directories(): array {
        if (!is_dir($this->modulesPath)) {
            return [];
        }

        $directories = glob($this->modulesPath . '/*', GLOB_ONLYDIR) ?: [];
        sort($directories);

        return $directories;
    }

    /**
     * Loads the module helpers and lets the modules process the request.
     * Returns the request, possibly modified.
     */
    public function boot(Request $request): Request {
        foreach ($this->modules() as $module) {
            $helpers = $module->helpers();
            if ($helpers !== null) {
                require_once $helpers;
            }

            $request = $module->boot($request);
        }

        return $request;
    }

    public function registerRoutes(Router $router): void {
        foreach ($this->modules() as $module) {
            $module->registerRoutes($router);
        }
    }

    /**
     * @return string[]
     */
    public function names(): array {
        return array_keys($this->modules());
    }
}
