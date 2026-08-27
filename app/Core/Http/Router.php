<?php
namespace App\Core\Http;

use App\Core\Template\ViewNotFoundException;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

class Router {

    /**
     * Pole pro ukládání registrovaných tras rozdělené podle HTTP metod.
     */
    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    /**
     * Obsluha chybových stavů: fn(int $code, string $message, Request $request): Response
     *
     * @var callable|null
     */
    private $errorHandler = null;

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
     * Nastaví vlastní vykreslení chybových stránek.
     * Jádro nesmí vědět nic o modelech aplikace, proto si obsluhu registruje
     * aplikace sama (viz app/Config/routes.php).
     */
    public function setErrorHandler(callable $handler): void {
        $this->errorHandler = $handler;
    }

    /**
     * Interní metoda, která převede uživatelskou URL na regulární výraz a uloží ji.
     */
    private function addRoute(string $method, string $url, array $handler): void {
        if (!isset($handler[0], $handler[1]) || !is_string($handler[0]) || !is_string($handler[1])) {
            throw new \InvalidArgumentException("Route '{$url}' must be registered as [ControllerClass::class, 'method'].");
        }

        // Nejdřív normalizujeme URL, teprve pak z ní stavíme regulární výraz –
        // trimovat až hotový vzor by rozbilo výrazy končící lomítkem.
        $url = trim($url, '/');

        // Převede zápis typu 'clanek/{slug}' na regulární výraz 'clanek/(?P<slug>[^/]+)'
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $url);

        $this->routes[strtoupper($method)][] = [
            'pattern'    => '#^' . $pattern . '$#D',
            'controller' => $handler[0],
            'action'     => $handler[1],
        ];
    }

    /**
     * Hlavní metoda pro spuštění routeru a odbavení požadavku.
     */
    public function run(?Request $request = null): void {
        $request ??= Request::createFromGlobals();

        $response = $this->handle($request);

        // HEAD má stejné hlavičky jako GET, ale nesmí mít tělo.
        $response->send(!$request->isHead());
    }

    /**
     * Zpracuje požadavek a vrátí odpověď (bez odeslání) – takto je routování testovatelné.
     */
    public function handle(Request $request): Response {
        $uri = $request->getPath();
        $method = $request->getMethod();

        // Definice CURRENT_PAGE pro zachování zpětné kompatibility se šablonami a menu.
        if (!defined('CURRENT_PAGE')) {
            define('CURRENT_PAGE', $request->getFirstSegment());
        }

        // Statické soubory sem nikdy patřit nemají (řeší je webserver),
        // takže je odbavíme rovnou, bez vykreslování šablon.
        if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?)$/i', $uri)) {
            return new Response('File was not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // HEAD se routuje podle GET tabulky.
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes[$lookupMethod] ?? [] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                // Vytáhneme pouze pojmenované parametry (odfiltrujeme číselné indexy z preg_match)
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return $this->execute($route['controller'], $route['action'], $params, $request);
            }
        }

        // Cesta existuje, jen pod jinou metodou => 405 místo 404.
        $allowed = $this->allowedMethodsFor($uri, $lookupMethod);
        if ($allowed !== []) {
            return $this->error(405, 'Method not allowed.', $request)
                ->setHeader('Allow', implode(', ', $allowed));
        }

        return $this->error(404, 'Page not found.', $request);
    }

    /**
     * Metody, pod kterými je daná cesta registrovaná (kromě té právě zkoušené).
     *
     * @return string[]
     */
    private function allowedMethodsFor(string $uri, string $exceptMethod): array {
        $allowed = [];

        foreach ($this->routes as $method => $routes) {
            if ($method === $exceptMethod) {
                continue;
            }

            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $uri)) {
                    $allowed[] = $method;
                    if ($method === 'GET') {
                        $allowed[] = 'HEAD';
                    }
                    break;
                }
            }
        }

        return $allowed;
    }

    /**
     * Bezpečné instancování controlleru a zavolání metody.
     */
    private function execute(string $controllerClass, string $actionName, array $params, Request $request): Response {
        if (!class_exists($controllerClass)) {
            return $this->error(500, "Controller {$controllerClass} not found.", $request);
        }

        $controller = new $controllerClass();
        if (method_exists($controller, 'setRequest')) {
            $controller->setRequest($request);
        }

        // Reflexní kontrola: zajištění, že metoda je veřejná a patří přímo danému controlleru
        try {
            $ref = new ReflectionMethod($controller, $actionName);
            if (!$ref->isPublic() || $ref->getDeclaringClass()->getName() !== get_class($controller)) {
                return $this->error(404, 'Page not found.', $request);
            }
        } catch (ReflectionException) {
            return $this->error(404, 'Page not found.', $request);
        }

        $args = $this->buildArguments($ref, $params, $request);
        if ($args === null) {
            // Parametr z URL nejde naplnit (chybí, nebo nesedí typ) – trasa tedy neodpovídá.
            return $this->error(404, 'Page not found.', $request);
        }

        try {
            $result = $ref->invokeArgs($controller, $args);
        } catch (ViewNotFoundException) {
            return $this->error(404, 'Page not found.', $request);
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        return new Response();
    }

    /**
     * Sestaví argumenty akce podle reflexe. Vrací null, pokud povinný parametr nelze naplnit –
     * dřívější doplňování null by na typovaném parametru skončilo TypeError.
     *
     * @return array<int, mixed>|null
     */
    private function buildArguments(ReflectionMethod $ref, array $params, Request $request): ?array {
        $args = [];

        foreach ($ref->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if ($typeName !== null && ($typeName === Request::class || is_subclass_of($typeName, Request::class))) {
                $args[] = $request;
                continue;
            }

            if (array_key_exists($name, $params)) {
                $value = $this->castParam($params[$name], $typeName);
                if ($value === null && $typeName !== null && !$type->allowsNull()) {
                    return null;
                }
                $args[] = $value;
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            if ($type === null || $type->allowsNull()) {
                $args[] = null;
                continue;
            }

            return null;
        }

        return $args;
    }

    /**
     * Přetypuje hodnotu z URL podle deklarovaného typu parametru.
     * Netypované a string parametry zůstávají řetězcem, aby se '007' nezměnilo na 7.
     */
    private function castParam(string $value, ?string $typeName): mixed {
        return match ($typeName) {
            'int'   => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            default => $value,
        };
    }

    /**
     * Vytvoření chybové odpovědi. Bez registrované obsluhy vrací prostý text,
     * aby jádro fungovalo i bez šablon aplikace.
     */
    private function error(int $code, string $message, Request $request): Response {
        if ($this->errorHandler !== null) {
            $response = ($this->errorHandler)($code, $message, $request);
            if ($response instanceof Response) {
                return $response;
            }
        }

        return new Response("Error {$code}: {$message}", $code, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
