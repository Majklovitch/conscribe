<?php

namespace App;

use App\Core\Config;
use App\Core\Console\Commands\BuildCommand;
use App\Core\Console\Commands\LintCommand;
use App\Core\Console\Commands\MakeMigrationCommand;
use App\Core\Console\Commands\MigrateCommand;
use App\Core\Console\Commands\MigrateRollbackCommand;
use App\Core\Console\Commands\MigrateStatusCommand;
use App\Core\Console\Kernel;
use App\Core\Container\Container;
use App\Core\Database\Connection;
use App\Core\Database\Migrations\Migrator;
use App\Core\Http\Csp;
use App\Core\Http\ErrorPage;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Http\Session;
use App\Core\Logging\ErrorHandler;
use App\Core\Logging\Log;
use App\Core\Module\ModuleManager;
use App\Models\Menu\MenuRepository;
use App\Services\Assets\AssetBuilder;
use App\Services\Sitemap\SitemapService;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
    /**
     * The framework's version.
     *
     * Code rather than configuration: app/Config/main.php is per machine and
     * untracked, and a version kept there drifted - four copies disagreed
     * about whether this was 1.3.9 or 1.4.0. README and CHANGELOG repeat it
     * for readers; VersionTest fails the suite when either disagrees.
     */
    public const VERSION = '2.1.0';

    private bool $isDevelopment;
    private ErrorPage $errorPage;
    private ?Request $request = null;
    private ?Router $router = null;
    private ?Container $container = null;
    private ?Kernel $console = null;

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

    public function container(): Container {
        return $this->container ??= $this->buildContainer();
    }

    /**
     * The console, with the core commands and every module's registered.
     *
     * Built on demand rather than by console(), so a bin/ script that only
     * wants configuration and error handling does not pay for it. Named
     * kernel() because console() is already the static boot factory.
     */
    public function kernel(): Kernel {
        if ($this->console !== null) {
            return $this->console;
        }

        // The Kernel registers itself in the container, so `list` and `help`
        // resolve to this console rather than to a fresh empty one.
        $kernel = new Kernel($this->container());
        $kernel->registerMany([
            BuildCommand::class,
            LintCommand::class,
            MakeMigrationCommand::class,
            MigrateCommand::class,
            MigrateRollbackCommand::class,
            MigrateStatusCommand::class,
        ]);

        foreach ((new ModuleManager())->modules() as $module) {
            $kernel->registerMany($module->commands());
        }

        return $this->console = $kernel;
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
        $session       = Session::instance();

        $this->container = $this->buildContainer($this->request, $session);
        $this->router    = new Router($session, $this->container);

        $this->router->setErrorHandler($this->errorPage);
    }

    /**
     * The container as the application sees it.
     *
     * Only the core services are registered, and the two that are
     * request-scoped are handed in rather than built: Request and Session
     * exist before the container has any use for them. The rest are bound as
     * factories over the existing static accessors, so nothing is connected,
     * opened or logged to until something actually asks - a request that never
     * touches the database still does not open a connection.
     *
     * Application services need no registration at all; the container
     * autowires any concrete class from its constructor.
     */
    private function buildContainer(?Request $request = null, ?Session $session = null): Container {
        $container = new Container();

        // Absent on the command line, where there is no request and starting a
        // session would be meaningless. A command asking for either gets a
        // clear "not registered" rather than a fabricated empty one.
        if ($request !== null) {
            $container->instance(Request::class, $request);
        }
        if ($session !== null) {
            $container->instance(Session::class, $session);
        }

        $container->instance(Container::class, $container);
        // Bound under the interface as well, so a command or a service can ask
        // for the standard type rather than this implementation.
        $container->instance(ContainerInterface::class, $container);

        $container->bind(LoggerInterface::class, static fn (): LoggerInterface => Log::get());
        $container->bind(PDO::class, static fn (): PDO => Connection::get());

        // Bound rather than autowired: the migrator needs to be told where the
        // migrations live, and a constructor argument the container cannot see
        // is exactly what a binding is for. Lazy like the rest, so a request
        // that is not a migration never touches the database because of it.
        $container->bind(Migrator::class, static function (): Migrator {
            // The application's own migrations first, then every enabled
            // module's, so a module's tables are created by the same
            // `tty migrate` as everything else. Order across directories is
            // still by filename, which the timestamp prefix keeps meaningful.
            $paths = [self::root() . '/database/migrations'];

            foreach ((new ModuleManager())->modules() as $module) {
                $directory = $module->migrations();
                if ($directory !== null) {
                    $paths[] = $directory;
                }
            }

            return new Migrator(Connection::get(), $paths);
        });

        return $container;
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
        // Registered again: bootHttp() put the request into the container before
        // any module had a chance to rewrite it.
        $this->container()->instance(Request::class, $this->request);
        $modules->registerRoutes($router);

        require self::root() . '/app/Config/routes.php';
    }
}
