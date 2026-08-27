<?php
namespace App\Core\Http;

use App\Core\Template\ViewNotFoundException;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

class Router {

    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    /**
     * Error handler: fn(int $code, string $message, Request $request): Response
     *
     * @var callable|null
     */
    private $errorHandler = null;

    /**
     * Session passed on to controllers. Without an argument the shared instance
     * is used, so that a plain new Router() keeps working.
     */
    private Session $session;

    public function __construct(?Session $session = null) {
        $this->session = $session ?? Session::instance();
    }

    public function get(string $url, array $handler): void {
        $this->addRoute('GET', $url, $handler);
    }

    public function post(string $url, array $handler): void {
        $this->addRoute('POST', $url, $handler);
    }

    /**
     * Sets a custom renderer for error pages.
     * The core must know nothing about application models, so the application
     * registers the handler itself at bootstrap (see public/index.php).
     */
    public function setErrorHandler(callable $handler): void {
        $this->errorHandler = $handler;
    }

    private function addRoute(string $method, string $url, array $handler): void {
        if (!isset($handler[0], $handler[1]) || !is_string($handler[0]) || !is_string($handler[1])) {
            throw new \InvalidArgumentException("Route '{$url}' must be registered as [ControllerClass::class, 'method'].");
        }

        // Normalize the URL first and only then build the regex from it -
        // trimming the finished pattern would break expressions ending in a slash.
        $url = trim($url, '/');

        // 'clanek/{slug}' => 'clanek/(?P<slug>[^/]+)'
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $url);

        $this->routes[strtoupper($method)][] = [
            'pattern'    => '#^' . $pattern . '$#D',
            'controller' => $handler[0],
            'action'     => $handler[1],
        ];
    }

    public function run(?Request $request = null): void {
        $request ??= Request::createFromGlobals();

        $response = $this->handle($request);

        // HEAD has the same headers as GET, but must not have a body.
        $response->send(!$request->isHead());
    }

    /**
     * Handles the request and returns the response without sending it -
     * this is what makes routing testable.
     */
    public function handle(Request $request): Response {
        $uri = $request->getPath();
        $method = $request->getMethod();

        // CURRENT_PAGE is defined for backwards compatibility with templates and the menu.
        if (!defined('CURRENT_PAGE')) {
            define('CURRENT_PAGE', $request->getFirstSegment());
        }

        // Static files never belong here (the web server handles them),
        // so answer them right away without rendering any template.
        if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?)$/i', $uri)) {
            return new Response('File was not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // HEAD is routed through the GET table.
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes[$lookupMethod] ?? [] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                // Named parameters only; the numeric indexes from preg_match are dropped.
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return $this->execute($route['controller'], $route['action'], $params, $request);
            }
        }

        // The path exists, just under a different method => 405 instead of 404.
        $allowed = $this->allowedMethodsFor($uri, $lookupMethod);
        if ($allowed !== []) {
            return $this->error(405, 'Method not allowed.', $request)
                ->setHeader('Allow', implode(', ', $allowed));
        }

        return $this->error(404, 'Page not found.', $request);
    }

    /**
     * Methods the given path is registered under, excluding the one just tried.
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

    private function execute(string $controllerClass, string $actionName, array $params, Request $request): Response {
        if (!class_exists($controllerClass)) {
            return $this->error(500, "Controller {$controllerClass} not found.", $request);
        }

        $controller = new $controllerClass();
        if (method_exists($controller, 'setRequest')) {
            $controller->setRequest($request);
        }
        if (method_exists($controller, 'setSession')) {
            $controller->setSession($this->session);
        }

        // Only a public method declared directly on this controller may be called.
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
            // A URL parameter cannot be filled (missing, or the type does not fit),
            // so the route does not actually match.
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

        // A silent empty 200 would turn a missing 'return' into a blank page that
        // nothing reports. Better a 500, so it shows up in the log.
        return $this->error(
            500,
            sprintf(
                'Action %s::%s must return a Response or string, got %s.',
                $controllerClass,
                $actionName,
                get_debug_type($result)
            ),
            $request
        );
    }

    /**
     * Builds the action arguments via reflection. Returns null when a required
     * parameter cannot be filled - passing null instead, as before, would end in
     * a TypeError on a typed parameter.
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

            if ($typeName !== null && ($typeName === Session::class || is_subclass_of($typeName, Session::class))) {
                $args[] = $this->session;
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
     * Casts a value from the URL to the declared parameter type.
     * Untyped and string parameters stay strings, so that '007' does not become 7.
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
     * Builds an error response. With no handler registered it returns plain text,
     * so the core works even without the application templates.
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
