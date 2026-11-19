<?php

namespace App\Core\Module;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Logging\Log;

/**
 * Finds the modules in app/Modules/ and hooks them into the request.
 *
 * A module = a directory with a Module.php file holding the class
 * Modules\<Directory>\Module. The list of enabled modules can be narrowed with
 * the 'modules' key in app/Config/main.php; without it, everything found is on.
 *
 * A name listed there that cannot be loaded - a typo, a deleted directory, a
 * Module.php without the expected class - is skipped with a warning in the
 * log. (It does not throw, because sites with such a leftover boot today.)
 */
class ModuleManager {
    private string $modulesPath;

    /**
     * @var ModuleInterface[]|null
     */
    private ?array $modules = null;

    /**
     * Names already reported as missing. The manager is built several times
     * per request, and one warning per name and process is enough.
     *
     * @var array<string, true>
     */
    private static array $reported = [];

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
        $problems = [];

        foreach ($this->directories() as $directory) {
            $name = basename($directory);

            if ($enabled !== null && !in_array($name, $enabled, true)) {
                continue;
            }

            if (!is_file($directory . '/Module.php')) {
                $problems[$name] = 'it has no Module.php';
                continue;
            }

            $class = 'Modules\\' . $name . '\\Module';
            if (!class_exists($class) || !is_subclass_of($class, ModuleInterface::class)) {
                $problems[$name] = "Module.php does not define {$class} implementing " . ModuleInterface::class;
                continue;
            }

            $modules[$name] = new $class();
        }

        if ($enabled !== null) {
            foreach ($enabled as $name) {
                if (!is_string($name) || isset($modules[$name])) {
                    continue;
                }

                $this->reportMissing($name, $problems[$name] ?? 'there is no such directory in ' . $this->modulesPath);
            }
        }

        return $this->modules = $modules;
    }

    private function reportMissing(string $name, string $reason): void {
        $key = $this->modulesPath . "\0" . $name;
        if (isset(self::$reported[$key])) {
            return;
        }
        self::$reported[$key] = true;

        Log::get()->warning(sprintf('Module "%s" is listed in the "modules" config but was not loaded: %s.', $name, $reason), [
            'module' => $name,
            'reason' => $reason,
        ]);
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
