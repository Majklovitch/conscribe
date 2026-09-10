<?php
namespace App\Core\Http;

use App\Core\Template\ViewNotFoundException;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

class Router {

    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    /**
     * Options applied to every route registered inside the current group().
     * Empty outside a group, so a plain get() behaves exactly as before.
     */
    private array $routeDefaults = [];

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

    /**
     * @param array|callable $handler [ControllerClass::class, 'method'] or a callable
     * @param array          $options route metadata, e.g. ['sitemap' => false]
     */
    public function get(string $url, array|callable $handler, array $options = []): void {
        $this->addRoute('GET', $url, $handler, $options);
    }

    public function post(string $url, array|callable $handler, array $options = []): void {
        $this->addRoute('POST', $url, $handler, $options);
    }

    /**
     * Registers a block of routes sharing the same options.
     *
     * It is what separates public pages from the rest: the route file decides
     * the policy once, instead of every line repeating it. Options given on the
     * route itself still win, so a single page can opt out of its own group.
     *
     *   $router->group(['sitemap' => false], function (Router $router) {
     *       require __DIR__ . '/routes/admin.php';
     *   });
     *
     * @param callable(self): void $register
     */
    public function group(array $defaults, callable $register): void {
        $previous = $this->routeDefaults;
        $this->routeDefaults = array_merge($previous, $defaults);

        try {
            $register($this);
        } finally {
            // Restored even when a route file throws, so one broken group does
            // not leak its defaults onto everything registered afterwards.
            $this->routeDefaults = $previous;
        }
    }

    /**
     * GET routes eligible for the sitemap, in registration order.
     *
     * POST is never considered - a form target is not a page. The router only
     * reports what it knows (the URL template and the metadata); turning that
     * into XML is the sitemap service's job.
     *
     * @return array<int, array{url: string, sitemap: mixed}>
     */
    public function sitemapRoutes(): array {
        $eligible = [];

        foreach ($this->routes['GET'] as $route) {
            $sitemap = $route['options']['sitemap'] ?? null;
            if ($sitemap === false) {
                continue;
            }

            $eligible[] = ['url' => $route['url'], 'sitemap' => $sitemap];
        }

        return $eligible;
    }

    /**
     * Sets a custom renderer for error pages.
     * The core must know nothing about application models, so the application
     * registers the handler itself at bootstrap (see public/index.php).
     */
    public function setErrorHandler(callable $handler): void {
        $this->errorHandler = $handler;
    }

    private function addRoute(string $method, string $url, array|callable $handler, array $options = []): void {
        if (is_array($handler) && (!isset($handler[0], $handler[1]) || !is_string($handler[0]) || !is_string($handler[1]))) {
            throw new \InvalidArgumentException("Route '{$url}' must be registered as [ControllerClass::class, 'method'] or a callable.");
        }

        // Normalize the URL first and only then build the regex from it -
        // trimming the finished pattern would break expressions ending in a slash.
        $url = trim($url, '/');

        // 'clanek/{slug}' => 'clanek/(?P<slug>[^/]+)'
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $url);

        $this->routes[strtoupper($method)][] = [
            'pattern' => '#^' . $pattern . '$#D',
            'handler' => $handler,
            // The template is kept alongside the compiled regex: the sitemap
            // needs the readable URL back, and a regex cannot be un-compiled.
            'url'     => $url,
            'options' => $this->mergeOptions($this->routeDefaults, $options),
        ];
    }

    /**
     * Route options over group defaults.
     *
     * One level deep, so a route refining a single setting keeps the rest of
     * its group: ['sitemap' => ['priority' => 1.0]] inside a group that sets
     * changefreq keeps that changefreq. Replacing the whole value would make
     * the shorthand quietly lose settings the author never mentioned. A scalar
     * still replaces outright, which is what ['sitemap' => false] needs.
     */
    private function mergeOptions(array $defaults, array $options): array {
        foreach ($options as $key => $value) {
            $defaults[$key] = is_array($value) && is_array($defaults[$key] ?? null)
                ? $value + $defaults[$key]
                : $value;
        }

        return $defaults;
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

        // Static files never belong here (the web server handles them),
        // so answer them right away without rendering any template.
        if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?)$/i', $uri)) {
            return new Response('File was not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // HEAD is routed through the GET table.
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes[$lookupMethod] ?? [] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                if (!$this->passesCsrf($route, $request)) {
                    return $this->error(419, 'The form expired or the CSRF token was missing.', $request);
                }

                // Named parameters only; the numeric indexes from preg_match are dropped.
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return $this->execute($route['handler'], $params, $request);
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
     * Verifies the CSRF token of a state-changing request.
     *
     * Checked by default rather than on request: a forgotten check_csrf() in
     * one action would otherwise silently leave that form unprotected, and
     * nothing about the code would look wrong. An endpoint that authenticates
     * some other way opts out explicitly, per route or per group:
     *
     *   $router->post('api/webhook', $handler, ['csrf' => false]);
     *
     * The token is read from the body field first and then from the header, so
     * both a plain form post and a fetch() work.
     */
    private function passesCsrf(array $route, Request $request): bool {
        if ($request->getMethod() !== 'POST') {
            return true;
        }

        if (($route['options']['csrf'] ?? true) === false) {
            return true;
        }

        $token = $request->post('csrf_token') ?? $request->getHeader('x-csrf-token') ?? '';

        return $this->session->validateToken(is_string($token) ? $token : '');
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

    private function execute(array|callable $handler, array $params, Request $request): Response {
        if (is_array($handler)) {
            [$controllerClass, $actionName] = $handler;

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

            $invoke = static fn (array $args): mixed => $ref->invokeArgs($controller, $args);
            $label = $controllerClass . '::' . $actionName;
        } else {
            // A callable handler is already a built object, so there is nothing
            // to construct and no declaring class to check - the two guards
            // above only make sense for controllers resolved from a class name.
            // This is what lets a service needing constructor arguments be a
            // route, the same way setErrorHandler() takes a plain callable.
            try {
                $ref = is_object($handler) && !$handler instanceof \Closure
                    ? new ReflectionMethod($handler, '__invoke')
                    : new ReflectionFunction($handler);
            } catch (ReflectionException) {
                return $this->error(500, 'Route handler is not callable.', $request);
            }

            $invoke = static fn (array $args): mixed => $handler(...$args);
            $label = is_object($handler) ? get_class($handler) : 'callable';
        }

        $args = $this->buildArguments($ref, $params, $request);
        if ($args === null) {
            // A URL parameter cannot be filled (missing, or the type does not fit),
            // so the route does not actually match.
            return $this->error(404, 'Page not found.', $request);
        }

        try {
            $result = $invoke($args);
        } catch (ViewNotFoundException) {
            return $this->error(404, 'Page not found.', $request);
        } catch (CsrfException $e) {
            // Thrown by check_csrf() on a route that opted out of the automatic
            // verification and does the check itself.
            return $this->error(419, $e->getMessage(), $request);
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
            sprintf('Action %s must return a Response or string, got %s.', $label, get_debug_type($result)),
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
    private function buildArguments(ReflectionFunctionAbstract $ref, array $params, Request $request): ?array {
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
