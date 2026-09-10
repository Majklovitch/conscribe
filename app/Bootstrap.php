<?php

namespace App;

use App\Core\Config;
use App\Core\Http\Csp;
use App\Core\Http\ErrorPage;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Logging\ErrorHandler;
use App\Core\Module\ModuleManager;
use App\Models\Menu\MenuRepository;
use App\Services\Assets\AssetBuilder;
use App\Services\Sitemap\SitemapService;

/**
 * Application wiring.
 *
 * Everything an entrypoint has to do before it can serve a request lives here,
 * so the web front controller, the CLI scripts under bin/ and the test suite
 * all boot the same way instead of each repeating - and eventually
 * contradicting - the same sequence.
 *
 *   Bootstrap::web()->run();        public/index.php
 *   Bootstrap::console();           bin/*.php
 */
final class Bootstrap {
    private bool $isDevelopment;
    private ErrorPage $errorPage;
    private ?Request $request = null;
    private ?Router $router = null;

    private function __construct() {
        $this->isDevelopment = Config::get('conscribe.environment', 'production') === 'development';

        $this->bootEnvironment();
        $this->bootErrorHandling();
    }

    /**
     * Full HTTP boot: error handling, assets, security headers, session,
     * router and the route table. Ready to dispatch.
     */
    public static function web(): self {
        $bootstrap = new self();

        $bootstrap->bootAssets();
        $bootstrap->sendSecurityHeaders();
        $bootstrap->bootHttp();
        $bootstrap->bootRoutes();

        return $bootstrap;
    }

    /**
     * CLI boot: configuration and error handling only. A command line has no
     * session and no response headers, and loading the route table would only
     * pull in code no command needs.
     */
    public static function console(): self {
        return new self();
    }

    /** Project root - the directory holding app/, public/ and vendor/. */
    public static function root(): string {
        return dirname(__DIR__);
    }

    public function isDevelopment(): bool {
        return $this->isDevelopment;
    }

    public function router(): Router {
        return $this->router
            ?? throw new \LogicException('The router is only built by Bootstrap::web().');
    }

    public function request(): Request {
        return $this->request
            ?? throw new \LogicException('The request is only built by Bootstrap::web().');
    }

    public function run(): void {
        $this->router()->run($this->request());
    }

    private function bootEnvironment(): void {
        error_reporting(E_ALL);
        ini_set('display_errors', $this->isDevelopment ? '1' : '0');
        ini_set('display_startup_errors', $this->isDevelopment ? '1' : '0');
        ini_set('log_errors', '1');
    }

    private function bootErrorHandling(): void {
        // The template is picked by the status code (app/Views/errors/*.php); the
        // menu and the logger are built only once something actually fails, so a
        // successful request pays for neither.
        $this->errorPage = new ErrorPage(static fn (Request $request): array => [
            'menuItems' => (new MenuRepository($request))->all(),
        ]);

        // In development this hands the error, exception and shutdown handlers to
        // Tracy (blue screen + debug bar); production keeps the handlers in
        // ErrorHandler, which render $errorPage. Either way everything ends up in
        // the same Monolog log.
        ErrorHandler::register($this->isDevelopment, $this->errorPage);
    }

    private function bootAssets(): void {
        // Assets are compiled artifacts committed to the repository; in
        // development they are refreshed whenever a file under assets/ is newer
        // than its output, so there is no separate watcher to remember to start.
        // In production the condition is false and the builder is never even
        // autoloaded.
        if ($this->isDevelopment && Config::get('assets.auto_compile', true)) {
            // A typo in a stylesheet must not take every route down with it: the
            // error is logged and the previously compiled assets keep being
            // served. Only a first build, where there is nothing to fall back
            // on, is fatal.
            AssetBuilder::fromConfig()->buildOrKeepLastGood();
        }
    }

    private function sendSecurityHeaders(): void {
        if (headers_sent()) {
            return;
        }

        if (Request::isSecure()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

        // Sent only when 'security.csp' is configured, and report-only unless
        // the site says otherwise - see App\Core\Http\Csp.
        $csp = Csp::header();
        if ($csp !== null) {
            header($csp[0] . ': ' . $csp[1]);
        }
    }

    private function bootHttp(): void {
        $this->request = Request::createFromGlobals();
        // Router::__construct falls back to Session::instance() itself.
        $this->router  = new Router();

        $this->router->setErrorHandler($this->errorPage);
    }

    private function bootRoutes(): void {
        $router = $this->router();

        // Built from the route table on request, so it can never drift from the
        // routes. Explicitly excluded from itself. Registered before the route
        // files, but the table is only read once the request comes in, so the
        // order does not matter.
        $router->get('sitemap.xml', new SitemapService($router), ['sitemap' => false]);

        $modules = new ModuleManager();
        $this->request = $modules->boot($this->request());
        $modules->registerRoutes($router);

        require self::root() . '/app/Config/routes.php';
    }
}
