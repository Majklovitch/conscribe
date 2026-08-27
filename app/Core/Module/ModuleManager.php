<?php
namespace App\Core\Module;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Router;

/**
 * Najde moduly v app/Modules/ a zapojí je do requestu.
 *
 * Modul = složka se souborem Module.php obsahujícím třídu Modules\<Složka>\Module.
 * Seznam povolených modulů lze omezit klíčem 'modules' v app/Config/main.php;
 * bez něj jsou zapnuté všechny nalezené.
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
     * Načte a instancuje povolené moduly.
     *
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
     * Načte helpery modulů a nechá je zpracovat request.
     * Vrací případně upravený request.
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

    /**
     * Nechá moduly zaregistrovat vlastní trasy.
     */
    public function registerRoutes(Router $router): void {
        foreach ($this->modules() as $module) {
            $module->registerRoutes($router);
        }
    }

    /**
     * Názvy zapojených modulů.
     *
     * @return string[]
     */
    public function names(): array {
        return array_keys($this->modules());
    }
}
