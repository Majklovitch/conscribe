# Structure and request lifecycle

## Directory layout

```
conscribe-php/
├── app/
│   ├── Bootstrap.php            # boots web or console: config, errors, assets, headers, session, routes
│   ├── Config/
│   │   ├── main.example.php     # copy to main.php (dev) / main.prod.php (prod); both git-ignored
│   │   ├── routes.php           # route groups – the group decides sitemap inclusion
│   │   └── routes/              # public.php (in sitemap), admin.php (not)
│   ├── Controllers/             # app controllers (WebController is the demo)
│   ├── Core/                    # the framework
│   │   ├── Config.php           # static config: dot keys, overlay, env file picking
│   │   ├── Console/             # Kernel, Command, Input/Output, Commands/ (build, lint, migrate…)
│   │   ├── Container/           # PSR-11 autowiring container
│   │   ├── Database/            # Connection, LazyConnection, Repository, Model, QueryLog, Migrations/
│   │   ├── Http/                # Router, Request, Response, Session, Controller, Csp, ErrorPage, Middleware/
│   │   ├── Logging/             # Log (Monolog), ErrorHandler, Debug/Tracy, Dump, QueryPanel
│   │   ├── Module/              # ModuleInterface, Module base class, ModuleManager
│   │   ├── Template/            # View, ViewPaths (namespaced `shop::view` lookup)
│   │   └── Validation/          # Validator, ErrorBag, Messages
│   ├── Helpers/TemplateHelper.php  # global helpers: esc, asset, image, svg, component, csrf_*, flash, old…
│   ├── Models/Menu/             # MenuRepository + MenuItem (main navigation)
│   ├── Modules/Auth/            # shipped module: users, login throttle, API tokens, middleware, commands
│   ├── Services/                # Assets (SCSS/JS build), Media (image pipeline), Sitemap
│   └── Views/                   # home.php, test.php, errors/, layout/header.php + footer.php, components/
├── assets/                      # SCSS and JS sources (compiled into public/)
├── bin/tty                      # console kernel entry; ./tty is the shim behind `php tty`
├── database/migrations/         # app migrations (modules ship their own)
├── docs/                        # ROADMAP, tasks.html, NAVOD (Czech), website checklist, archive
├── public/                      # web root: index.php, .htaccess, built css/js, media cache, robots.txt
├── storage/                     # logs, exception dumps, caches (not web-reachable)
└── tests/                       # PHPUnit: Core/, Modules/, Support/ fakes
```

## Request lifecycle

1. Apache rewrites every non-file request to [public/index.php](../public/index.php), which
   calls `App\Bootstrap::web()->run()`.
2. [Bootstrap](../app/Bootstrap.php) loads config (`main.php` or `main.prod.php`, see
   `Config::path()`), sets error handling (Tracy in development, error page + log in
   production), the time zone, auto-compiles assets in development, sends security headers
   (and CSP when configured), builds the container, `Request` and `Session`.
3. Routes are registered: `sitemap.xml`, then every enabled module (`ModuleManager` –
   `boot()` may replace the request, `registerRoutes()`), then
   [app/Config/routes.php](../app/Config/routes.php).
4. [Router](../app/Core/Http/Router.php) matches method + path (typed params, groups,
   405 with `Allow`), runs the middleware pipeline, resolves the controller from the
   container and injects the `Request` and route params into the action.
5. The action returns a [Response](../app/Core/Http/Response.php) – usually
   `$this->render('view', $data)`, which goes through
   [View](../app/Core/Template/View.php): the view is wrapped in `layout/header.php` +
   `layout/footer.php` unless another layout is requested.
6. `Response::send()` writes status, headers and body. Errors become
   [ErrorPage](../app/Core/Http/ErrorPage.php) responses (HTML or problem+json).

The console follows the same boot without HTTP: `Bootstrap::console()->kernel()` registers
the core commands and every module's commands.

## Core pieces worth knowing

- **Config** – `Config::get('db.host')`; `Config::overlay([...])` lays values on top (e.g.
  from the database); tests call `Config::set([...])`.
- **Container** – autowires constructor dependencies; `Bootstrap::buildContainer()` holds the
  explicit bindings (logger, connection, migrator, error page …).
- **Session** – starts only when something is written; flash, old input, CSRF token.
- **Repository / Model** – SQL-first: `find`, `where`, `first`, `count`, `paginate`, `insert`,
  `update`, `delete`, `transaction`; identifiers are quoted, values always bound.
- **Modules** – a directory in `app/Modules/<Name>/` with `Module.php` (class
  `Modules\<Name>\Module`). It can supply routes, helpers, commands, migrations and views
  (`<name>::view`). The `modules` config key lists the enabled ones; a listed name that cannot
  be loaded is logged as a warning.
- **Logging** – `Log::get()` returns the shared PSR-3 logger; it never throws.
