<?php
namespace App\Core;

use App\Models\Repositories\MenuRepository;

class Router {

    /**
     * Pole pro ukládání registrovaných tras rozdělené podle HTTP metod.
     */
    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    /**
     * Registrace GET trasy
     */
    public function get(string $url, array $handler): void {
        $this->addRoute('GET', $url, $handler);
    }

    /**
     * Registrace POST trasy
     */
    public function post(string $url, array $handler): void {
        $this->addRoute('POST', $url, $handler);
    }

    /**
     * Interní metoda, která převede uživatelskou URL na regulární výraz a uloží ji.
     */
    private function addRoute(string $method, string $url, array $handler): void {
        // Převede zápis typu 'clanek/{slug}' na regulární výraz 'clanek/(?P<slug>[^/]+)'
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $url);

        // Uzavřeme regulární výraz a zajistíme shodu na celý řetězec
        $pattern = '#^' . trim($pattern, '/') . '$#D';

        $this->routes[strtoupper($method)][] = [
            'pattern'    => $pattern,
            'controller' => $handler[0],
            'action'     => $handler[1]
        ];
    }

    /**
     * Hlavní metoda pro spuštění routeru a odbavení požadavku.
     */
    public function run(?Request $request = null): void {
        if ($request === null) {
            $request = Request::createFromGlobals();
        }

        $uri = $request->getPath();
        $method = $request->getMethod();

        // Definice CURRENT_PAGE pro zachování zpětné kompatibility s tvým stávajícím systémem
        if (!defined('CURRENT_PAGE')) {
            $page = explode('/', $uri)[0];
            define('CURRENT_PAGE', $page === '' ? 'home' : $page);
        }

        // Ignorování statických souborů z tvé původní verze
        if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|svg)$/i', $uri)) {
            $this->error(404, 'File was not found.');
            return;
        }

        // Pokud pro danou HTTP metodu nemáme žádné routy, rovnou vyhodíme 404
        if (!isset($this->routes[$method])) {
            $this->error(404, 'Page not found.');
            return;
        }

        // Prohledáme registrované trasy pro aktuální HTTP metodu
        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {

                // Vytáhneme pouze pojmenované parametry (odfiltrujeme číselné indexy z preg_match)
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Spustíme controller
                $this->execute($route['controller'], $route['action'], $params, $request);
                return;
            }
        }

        // Pokud žádná trasa neodpovídá URL
        $this->error(404, 'Page not found.');
    }

    /**
     * Bezpečné instancování controlleru a zavolání metody.
     */
    private function execute(string $controllerClass, string $actionName, array $params, Request $request): void {
        if (!class_exists($controllerClass)) {
            $this->error(500, "Controller {$controllerClass} not found.");
            return;
        }

        $controller = new $controllerClass();
        if (method_exists($controller, 'setRequest')) {
            $controller->setRequest($request);
        }

        foreach ($params as $key => $value) {
            if (is_numeric($value)) {
                $params[$key] = $value == (int)$value ? (int)$value : (float)$value;
            }
        }

        // Reflexní kontrola: zajištění, že metoda je veřejná a patří přímo danému controlleru
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

        // Sestavení argumentů pro metodu na základě reflexe (vstřikování Request objektu)
        $args = [];
        foreach ($ref->getParameters() as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();

            if ($paramType instanceof \ReflectionNamedType && 
                ($paramType->getName() === Request::class || is_subclass_of($paramType->getName(), Request::class))) {
                $args[] = $request;
            } elseif (array_key_exists($paramName, $params)) {
                $args[] = $params[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        // Volání metody controlleru s předáním argumentů
        $response = call_user_func_array([$controller, $actionName], $args);

        // Pokud akce vrátí objekt Response, odešleme ho
        if ($response instanceof Response) {
            $response->send();
        }
    }

    /**
     * Vypsání chybové stránky.
     */
    private function error(int $code, string $message): void {
        $data = [
            'pageTitle' => "Error $code",
            'code'      => (int) $code,
            'message'   => (string) $message,
            'menuItems' => (new MenuRepository())->all(),
        ];

        $content = View::render('404', $data);
        $response = new Response($content, $code);
        $response->send();
        exit;
    }
}