<?php
namespace App\Core;

use App\Core\View;
use App\Models\MenuRepository;

class Router {

    /**
     * Explicit route map: URL segment => controller action method.
     * Only these routes are reachable — everything else gets a 404.
     * Add new pages here.
     */
    private const ROUTES = [
        ''     => 'index',
        'home' => 'index',
        'test' => 'test',
    ];

    public function run(): void {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $uri = trim($uri, '/');

        if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|svg)$/i', $uri)) {
            $this->error(404, 'File was not found.');
            return;
        }

        $parts = $uri === '' ? [] : array_values(array_filter(explode('/', $uri), 'strlen'));

        $page = $parts[0] ?? '';
        $params = array_slice($parts, 1);

        if (!defined('CURRENT_PAGE')) {
            define('CURRENT_PAGE', $page === '' ? 'home' : $page);
        }

        // Allowlist check — reject anything not explicitly registered
        if (!array_key_exists($page, self::ROUTES)) {
            $this->error(404, 'Page not found.');
            return;
        }

        $actionName = self::ROUTES[$page];

        $controllerClass = "App\\Controllers\\WebController";
        if (!class_exists($controllerClass)) {
            $this->error(500, 'Controller not found.');
            return;
        }

        $controller = new $controllerClass();

        // Extra guard: ensure the method actually belongs to WebController,
        // not an inherited or magic method.
        try {
            $ref = new \ReflectionMethod($controller, $actionName);
            if (!$ref->isPublic() || $ref->getDeclaringClass()->getName() !== get_class($controller)) {
                $this->error(404, 'Page not found.');
                return;
            }
        } catch (\ReflectionException) {
            $this->error(404, 'Page not found.');
            return;
        }

        call_user_func_array([$controller, $actionName], $params);
    }

    private function error(int $code, string $message): void {
        http_response_code($code);

        $data = [
            'pageTitle' => "Error $code",
            'code' => (int) $code,
            'message' => (string) $message,
            'menuItems' => (new MenuRepository())->all(),
        ];

        View::render('404', $data);
        exit;
    }
}