# Conscribe PHP

Conscribe is a small, dependency-light PHP MVC/MVP framework for building classic server-rendered websites or REST API based apps. It gives you an explicit router, a PHP-template view layer with custom helper, a thin PDO data layer, session and CSRF handling, PSR-3 logging with real error pages and a Tracy debug bar, an on-demand image resizing pipeline, a small autowiring container, and a module system for optional features (dunno if i would maintain it this way) — without an ORM or a compile step.

**Version 1.3.9** · Requires **PHP 8.4+**

May work on 8.2. Older versions I didn't test cause I don't want to.

> Note that this project was Co-worked with **Artificial Intelligence**. Mainly to help with documentation and implementation of new features. The code is written by AI and maintained by human(s).

---

What I want to do in future:

- [ ] Rewrite readme with HUMAN sentences and not the code infrastructure
- [ ] Rewrite some stuff in code that are unnecessarily complicated and hard to read
- [ ] Redo the welcome page to be more human and not AI generated
- [ ] Add new features that will make the framework better (dunno what yet)
- [ ] Add a 3rd party ORM or make my own (doctrine or mine)
- [ ] Add a dependency injection container (php-di or something else)
- [ ] Rewrite documentation in docs/reference_guide.md to be more human and not AI generated
- [ ] Create new modules??
- [ ] Refactor Configs to php objects instead of arrays (maybe) - but make them in their own files and not in one big file
- [ ] Maybe create an .ai folder for better AI integration?
- [ ] Redo docker compose

---

Now enjoy AI readme :D


## Table of Contents

1. [Feature Overview](#feature-overview)
2. [Project Structure](#project-structure)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Container](#container)
6. [Console](#console)
7. [Middleware](#middleware)
8. [Routing](#routing)
9. [Controllers](#controllers)
10. [Request](#request)
11. [Response](#response)
12. [Views & Layouts](#views--layouts)
13. [Template Helpers](#template-helpers)
14. [Asset Pipeline (SCSS & Minification)](#asset-pipeline-scss--minification)
15. [Images & Media Pipeline](#images--media-pipeline)
16. [Sessions, Flash & Old Input](#sessions-flash--old-input)
17. [CSRF Protection](#csrf-protection)
18. [Database Layer](#database-layer)
19. [Logging & Error Handling](#logging--error-handling)
20. [Modules](#modules)
21. [Menu](#menu)
22. [Tracking Codes](#tracking-codes)
23. [Security Summary](#security-summary)
24. [Deployment](#deployment)

---

## Feature Overview

| Area | What you get |
| --- | --- |
| **Routing** | Explicit route table for `GET`, `POST`, `PUT`, `PATCH` and `DELETE`, `{param}` placeholders, typed parameter casting, automatic `HEAD` and `OPTIONS` handling, `405 Method Not Allowed` with an `Allow` header, pluggable error pages |
| **Controllers** | Base class with `render()`, `json()`, `redirect()`; `Request` and `Session` injected by reflection into action signatures; constructor dependencies autowired |
| **Container** | PSR-11, autowiring by constructor reflection, shared entries by default, `make()` for fresh ones, cycles reported as the chain that caused them |
| **Console** | `php tty` with autowired commands, `list`/`help`, did-you-mean on a typo, colour that turns itself off in a pipe, modules can add their own |
| **Middleware** | Global and per-route/per-group pipeline, request rewriting, short-circuiting, class names resolved through the container |
| **HTTP** | Immutable-ish `Request` (query/body/JSON/headers/cookies/files), `Response` with header canonicalization, response-splitting protection, cookies, and factories for HTML/JSON/redirect/no-content |
| **Views** | Plain PHP templates with automatic header/footer layout wrapping, isolated variable scopes (`EXTR_SKIP`), path-traversal-safe resolution, reusable components |
| **Helpers** | `esc()`, `dateFormat()`, `truncate()`, `asset()`, `image()`, `image_tag()`, `svg()`, `component()`, `flash()`, `old()`, `csrf_field()`, `renderTrackingCodes()` |
| **Media** | On-demand image resize + WebP conversion, disk cache, Imagick/GD drivers with automatic fallback, named presets, `srcset` builder, atomic writes |
| **Session** | Lazy start (no cookie for visitors who never touch it), hardened cookie params, automatic ID rotation, flash messages, old form input, CSRF token |
| **Security** | CSRF tokens with `hash_equals`, open-redirect protection, SVG sanitization, SQL identifier whitelisting, prepared statements, security response headers, hardened cache directory |
| **Database** | Lazy PDO singleton, `Repository` base with CRUD/pagination/transactions, `Model` base with typed hydration, `ArrayAccess` and `JsonSerializable` |
| **Logging** | Monolog behind PSR-3, daily rotating files, global handlers for exceptions/fatals/warnings, Tracy blue screen and debug bar in development, an HTML dump per failure in both environments, never fatal on failure |
| **Modules** | Drop-in folders under `app/Modules/` that can boot, rewrite the request, register routes, and load their own helpers |
| **Tracking** | GA4, Google Tag, Google Ads, Seznam Sklik and Facebook Pixel rendered from config only when configured |
| **Frontend** | Cookie Consent v3, Spotlight lightbox, accessible mobile navigation, skip link |
| **Docker** | PHP 8.4 + Apache image with `pdo_mysql`, `imagick`, `gd`, `intl`, `zip`, `bcmath`, `exif`, `opcache`, `mbstring`, `fileinfo`, `curl` |

---

## Project Structure

```text
├── app/
│   ├── Config/
│   │   ├── main.example.php       # Configuration template → copy to main.php
│   │   └── routes.php             # Route definitions
│   ├── Controllers/
│   │   ├── ErrorController.php    # Invokable error-page renderer
│   │   └── WebController.php
│   ├── Core/
│   │   ├── Config.php             # Dot-notation config access
│   │   ├── Database/
│   │   │   ├── Connection.php     # Lazy PDO singleton
│   │   │   ├── Model.php          # Data object base
│   │   │   └── Repository.php     # Table access base
│   │   ├── Http/
│   │   │   ├── Controller.php
│   │   │   ├── Request.php
│   │   │   ├── Response.php
│   │   │   ├── Router.php
│   │   │   └── Session.php
│   │   ├── Logging/
│   │   │   ├── ErrorHandler.php   # Global exception/fatal/warning handler
│   │   │   └── Log.php            # PSR-3 logger factory
│   │   ├── Module/
│   │   │   ├── Module.php         # Default module implementation
│   │   │   ├── ModuleInterface.php
│   │   │   └── ModuleManager.php  # Discovery & boot
│   │   └── Template/
│   │       ├── View.php
│   │       └── ViewNotFoundException.php
│   ├── Helpers/
│   │   └── TemplateHelper.php     # Global functions, autoloaded by Composer
│   ├── Models/
│   │   └── Menu/                  # MenuItem, MenuRepository
│   ├── Modules/                   # Optional drop-in modules
│   ├── Services/
│   │   ├── MediaService.php       # Facade over ImageCache
│   │   └── Media/
│   │       ├── ImageCache.php     # Cache layout, freshness, purge
│   │       ├── ImageProcessor.php # Driver contract
│   │       ├── ImagickProcessor.php
│   │       ├── GdProcessor.php
│   │       └── ProcessorFactory.php
│   └── Views/
│       ├── 404.php                # Error page template
│       ├── home.php
│       ├── test.php
│       ├── components/            # Fragments rendered by component()
│       └── layout/                # header.php, footer.php
├── assets/                        # Asset sources (compiled into public/)
│   ├── scss/                      # main.scss + partials
│   └── js/                        # main.js
├── bin/tty                        # Console kernel entry point
├── tty                            # Shim, so it reads as `php tty`
├── public/                        # Document root
│   ├── css/  js/  img/  media/cache/
│   ├── index.php                  # Front controller
│   ├── .htaccess                  # Rewrite rules + asset caching
│   ├── robots.txt  sitemap.xml  llms.txt
├── storage/logs/                  # Rotating log files (git-ignored)
├── docs/reference_guide.md
├── Dockerfile
├── docker-compose.yml
├── docker-compose_db.yml          # Variant attaching to an external DB network
└── composer.json
```

---

## Installation

### Docker (recommended)

```bash
docker compose up -d
```

```bash
docker exec -it conscribe-php composer install
```

The app is then available at `http://localhost:8050`. The project root is bind-mounted into `/var/www/html`, so edits are live without rebuilding.

Use `docker-compose_db.yml` instead when the site should join an existing external Docker network with a database container.

### Local Apache / Nginx

1. Point the document root at `public/`. (A root `.htaccess` also forwards requests into `public/` if you cannot change the docroot.)
2. Enable `mod_rewrite` — `public/.htaccess` needs it.
3. Copy the configuration template and fill it in:

```bash
cp app/Config/main.example.php app/Config/main.php
```

4. Install dependencies:

```bash
composer install
```

### Requirements

- PHP **8.4+** with `ext-mbstring`, `ext-pdo`, `ext-gd`
- Recommended: `ext-imagick` (preferred image driver), `ext-exif` (fixes rotated JPEGs on the GD driver)
- Writable `storage/logs/` and `public/media/cache/`

---

## Configuration

All configuration lives in `app/Config/main.php`, which is git-ignored. Copy it from `app/Config/main.example.php`.

```php
return [
    'conscribe' => [
        'ver'         => '1.3.9',
        'author'      => 'Majklovitch',
        'environment' => 'development',       // 'development' | 'production'
        'base_url'    => 'http://localhost:8080/',
    ],
    'modules' => ['LanguageMutations'],        // whitelist; omit the key to enable all found
    'media' => [
        'driver'        => 'auto',             // 'auto' | 'imagick' | 'gd'
        'source_root'   => null,               // null = public root
        'cache_path'    => 'media/cache',      // relative to public root
        'quality'       => 82,
        'format'        => 'webp',             // webp | jpg | jpeg | png | gif
        'max_dimension' => 4000,
        'presets'       => ['thumb' => [500, 500], 'card' => [600, 400]],
    ],
    'log' => [
        'enabled'   => true,
        'name'      => 'conscribe',
        'path'      => 'storage/logs/app.log', // relative to project root, or absolute
        'level'     => null,                   // null = debug in dev, warning in production
        'max_files' => 14,                     // daily rotation; 0 = keep everything
                                               // also the age limit of the HTML dumps
        'dumps'      => true,                  // Tracy blue screen of every uncaught
                                               // exception/fatal saved next to the log
        'dumps_path' => null,                  // null = storage/exceptions
        'stderr'    => false,                  // also write to stderr (docker logs)
    ],
    'session' => [
        'name'                => 'CONSCRIBEID',
        'lifetime'            => 0,            // 0 = until the browser closes
        'path'                => '/',
        'domain'              => '',
        'secure'              => null,         // null = auto-detect from protocol
        'httponly'            => true,
        'samesite'            => 'Lax',        // Lax | Strict | None
        'regenerate_interval' => 1800,         // seconds between ID rotations; 0 = off
    ],
    'db' => [
        'host' => 'host', 'dbname' => 'database_name',
        'user' => 'user', 'pass' => 'password', 'charset' => 'utf8mb4',
    ],
    'tracking' => [
        'ga4_id' => null, 'gtag_id' => null, 'adwords_id' => null,
        'sklik_id' => null, 'fb_pixel_id' => null,
    ],
];
```

Read values anywhere with dot notation:

```php
use App\Core\Config;

Config::get('media.quality', 82);
Config::has('db.host');
Config::set($array);           // override the whole config (tests)
```

The environment flag drives three things: `display_errors`, the default log threshold, and whether 5xx pages show the internal message or a generic sentence.

---

## Container

A small PSR-11 container. It is not a service locator you reach into from
everywhere: the router uses it to build controllers and to fill action
parameters, and that is most of what it is for.

**Nothing needs registering** if it can be built from its own constructor. A
controller that asks for a service gets one:

```php
class OrderController extends Controller {
    public function __construct(private OrderRepository $orders) {
    }

    public function show(int $id): Response {
        return $this->json($this->orders->find($id));
    }
}
```

An action parameter the URL cannot fill is resolved the same way, so a service
needed by one action only does not have to sit in the constructor:

```php
public function show(int $id, MediaService $media): Response { … }
```

`{id}` still wins over the container for `$id`, and `Request` and `Session`
still come from the request being handled rather than from a binding.

**Interfaces need a binding**, because nothing can be autowired from a name that
has no constructor:

```php
$container = $bootstrap->container();

$container->bind(PaymentGateway::class, fn () => new StripeGateway(Config::get('stripe.key')));
$container->alias(ClockInterface::class, SystemClock::class);
$container->instance(Basket::class, $basket);       // something already built
```

The core registers `Request`, `Session`, `PDO` and `LoggerInterface`. The last
two are factories over the existing lazy accessors, so a request that never
touches the database still opens no connection.

**Entries are shared.** `get()` builds once and remembers; `make()` builds fresh
every time and takes arguments by parameter name:

```php
$container->make(Report::class, ['month' => '2026-09']);
```

**Failures say what went wrong.** An unresolvable parameter names itself and its
class, a cycle is reported as the chain that caused it rather than as a stack
overflow, and a controller the container cannot build becomes a logged 500
instead of a fatal.

The router works without a container — `new Router()` still constructs
controllers with `new` — so nothing that existed before this needs changing.


## Console

`tty` is the command line entry point — the counterpart of `public/index.php`.

```bash
php tty                    # what is there
php tty help build         # what one of them does
php tty build --watch
php tty lint
```

**Writing a command.** Declare a name and a description, implement `run()`, and
return an exit code. The container builds it, so a command may declare its
dependencies exactly as a controller does:

```php
use App\Core\Console\{Command, Input, Output};

final class ImportCommand extends Command {
    protected string $name = 'import';
    protected string $description = 'Imports the price list.';
    protected string $help = 'Usage: php tty import [file]';

    public function __construct(private PriceList $prices) {
    }

    public function run(Input $input, Output $output): int {
        $count = $this->prices->import($input->argument(0, 'prices.csv'));

        $output->success("Imported {$count} rows.");

        return self::SUCCESS;
    }
}
```

Register it in `Bootstrap::kernel()`, or — from a module — by returning it from
`commands()`, which is how a module ships work that is not a request:

```php
final class Module extends \App\Core\Module\Module {
    public function commands(): array {
        return [ImportCommand::class];
    }
}
```

**Input** understands `--env=production`, bare flags (`--watch`, `-v`, `-abc` as
three), positional arguments, and `--` to end option parsing. A flag given with
no value reads as `true`, so `option('watch')` tells "absent" (`null`) from
"given". Commands are registered by class name and built only when chosen, so
`tty list` never pays for another command's dependencies.

**Output** writes to injected streams, which is what makes commands testable.
Errors go to stderr, so `php tty something > file` still shows them. Colour is
decided once — on for a terminal, off in a pipe or when `NO_COLOR` is set — so a
call site never has to ask.

**Exit codes** are the command's own return value: `Command::SUCCESS` (0) or
`Command::FAILURE` (1). A mistyped command name fails with a suggestion; an
exception from a command is reported to stderr and re-thrown, so the error
handler still logs it with its trace.


## Middleware

A middleware wraps the dispatch. It can answer by itself, change the request on
the way in, or change the response on the way out:

```php
use App\Core\Http\Middleware\MiddlewareInterface;

final class Timer implements MiddlewareInterface {
    public function process(Request $request, callable $next): Response {
        $started = microtime(true);

        return $next($request)->setHeader('X-Duration', (string) (int) ((microtime(true) - $started) * 1000));
    }
}
```

This is PSR-15's shape over this project's own `Request` and `Response` rather
than PSR-7 — adopting PSR-15 properly would mean adopting PSR-7 with it and
giving up the lean HTTP layer the rest of the core is built on.

**Registering.** Globally, per group, or per route; entries run in the order
given, and an entry may be an object, a callable, or a class name the container
resolves (so a middleware can declare its own dependencies):

```php
$router->middleware(TrimStrings::class, new Cors($origins));

$router->group(['middleware' => [RequireLogin::class]], function (Router $router): void {
    $router->get('admin', [AdminController::class, 'index']);
    $router->post('admin/save', [AdminController::class, 'save'], [
        'middleware' => [AuditLog::class],     // appended after the group's
    ]);
});
```

**Order.** Global first, then group, then route, then the action — and the
response travels back out in reverse. A route's list is *appended* to its
group's rather than replacing it.

**Global middleware also sees requests that match no route**, so a rate limiter
or an access log covers the 404s and 405s too.

**Short-circuiting** is just not calling `$next`:

```php
public function process(Request $request, callable $next): Response {
    return $this->allowed($request) ? $next($request) : Response::json(['error' => 'Too many requests'], 429);
}
```

**Rewriting** the request is honoured by the routing, since the matching happens
inside the pipeline:

```php
return $next($request->withPath($strippedPath));
```

Route middleware wraps the CSRF check as well as the action, so an endpoint that
authenticates some other way gets to run before anything can reject it.


## Routing

Routes are declared in `app/Config/routes.php`, where `$router` is already in scope:

```php
use App\Controllers\WebController;
use App\Controllers\ArticleController;

$router->get('', [WebController::class, 'index']);
$router->get('test', [WebController::class, 'test']);
$router->get('clanek/{slug}', [ArticleController::class, 'show']);
$router->post('kontakt', [ContactController::class, 'send']);

$router->put('api/order/{id}', [OrderApi::class, 'replace']);
$router->patch('api/order/{id}', [OrderApi::class, 'update']);
$router->delete('api/order/{id}', [OrderApi::class, 'destroy']);
```

**Methods.** `GET`, `POST`, `PUT`, `PATCH` and `DELETE` each have a helper; anything else is
rejected when the route is registered, rather than becoming a table no request will look in.
`map()` registers one URL under several methods at once:

```php
$router->map(['PUT', 'PATCH'], 'api/order/{id}', [OrderApi::class, 'update']);
```

**Placeholders.** `{name}` matches one path segment and is passed to the action by parameter name, not position.

**Typed casting.** The declared parameter type decides the conversion. `int` requires digits, `float` requires a numeric string, `bool` uses `FILTER_VALIDATE_BOOL`. A value that does not fit the type produces a 404 instead of a `TypeError`. Untyped and `string` parameters stay strings, so `007` does not become `7`.

```php
public function show(string $slug, int $page = 1) { … }
```

**Injection.** Parameters typed as `Request` or `Session` receive those objects automatically:

```php
public function send(Request $request, Session $session) { … }
```

**HEAD** requests are routed through the `GET` table and sent without a body.

**OPTIONS** is answered from the route table itself: `204` with an `Allow` header listing what the path is registered under, or `404` when it is registered under nothing. The `Access-Control-*` headers a browser preflight also wants are not sent — CORS is a policy the application owns, not the router.

**405 vs 404.** If a path is registered under a different method, the router answers `405` with an `Allow` header listing the real methods, rather than a misleading `404`. `HEAD` and `OPTIONS` appear in that list because the router answers them, even though neither has a route table of its own.

**Static files** ending in a known asset extension never reach a controller — the router short-circuits them with a plain-text 404, since the web server should have served them.

**Guarded dispatch.** An action is only invoked if it is public and declared directly on the controller class (checked via reflection), so inherited or protected helpers can never be reached from a URL.

**Return contract.** An action must return a `Response` or a string. Anything else — usually a forgotten `return` — is logged as a 500 instead of rendering a silent blank page.

**Error pages.** The router calls a handler registered by the application, so the core never needs to know about your models or templates:

```php
$router->setErrorHandler($errorPage);
```

Without one, it falls back to plain-text errors and stays usable in isolation (which also makes `Router::handle()` testable — it returns a `Response` instead of sending it).

---

## Controllers

```php
namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;

class ArticleController extends Controller
{
    public function show(string $slug): Response
    {
        return $this->render('article', [
            'pageTitle'       => 'Článek',
            'pageDescription' => '…',
        ]);
    }

    public function api(Request $request): Response
    {
        return $this->json(['ok' => true]);
    }

    public function save(): Response
    {
        $this->session()->flash('success', 'Uloženo.');
        return $this->redirect('/');
    }
}
```

| Method | Purpose |
| --- | --- |
| `render($view, $params, $layout = 'main', $status = 200)` | Renders a template into an HTML `Response` |
| `json($data, $status, $headers)` | JSON `Response` |
| `redirect($url, $status = 302, $headers)` | Redirect `Response` (validated, see below) |
| `request()` | The current `Request`; throws if the controller was built outside the router |
| `session()` | The shared `Session` |

---

## Request

`Request::createFromGlobals()` builds the object from the superglobals; the router passes it everywhere, and `withPath()` returns a copy with a rewritten path (used by modules that strip their own prefix).

```php
$request->getPath();            // 'clanek/nazev' — no leading/trailing slash, root is ''
$request->getFirstSegment();    // 'clanek', or 'home' at the root
$request->getMethod();
$request->isGet() / isPost() / isHead() / isAjax();

$request->get('q');             // query, then body
$request->query('q');
$request->post('email');
$request->getQueryParams();
$request->getBodyParams();

$request->cookie('lang');
$request->file('avatar');
$request->getHeader('accept');  // case-insensitive
$request->getHeaders();
$request->getRawBody();

$request->getClientIp();            // REMOTE_ADDR
$request->getClientIp(true);        // X-Forwarded-For, but only from a trusted proxy
Request::isSecure();                // HTTPS / REQUEST_SCHEME / port 443, plus proxy headers
```

**Trusted proxies.** `X-Forwarded-Proto`, `X-Forwarded-For`, `CF-Visitor`, `X-ARR-SSL` and friends
are headers, so any client can send them. They are believed only when `REMOTE_ADDR` matches an
entry in `conscribe.trusted_proxies` (IPs or CIDR blocks, IPv4 and IPv6); the default is an empty
list, so a site that is not behind a proxy trusts only what the web server itself reports:

```php
'conscribe' => [
    'trusted_proxies' => ['10.0.0.0/8', '2400:cb00::/32'],
],
```

**JSON bodies** with `Content-Type: application/json` are decoded and merged into the body params automatically; malformed JSON is flagged via `hasInvalidJson()` rather than silently dropped. `multipart/form-data` skips the raw-body read entirely, since `php://input` is always empty there.

**Header extraction** normalizes `HTTP_*` keys to lowercase dashed names and recovers `Authorization` from `REDIRECT_HTTP_AUTHORIZATION`, which Apache moves during rewrites.

---

## Response

```php
Response::html($content, 200);
Response::json($data);                    // throws JsonException on unencodable data
Response::redirect('/dekujeme');
Response::noContent();                    // 204

$response->setHeader('X-Foo', 'bar')
         ->addHeader('Vary', 'Accept')
         ->withCookie('lang', 'cs', ['expires' => time() + 86400]);
```

- **Header names are canonicalized** (`content-type` → `Content-Type`), so the same header cannot be sent twice under different spellings.
- **Header values are sanitized** — CR, LF and NUL are stripped, closing off HTTP response splitting.
- **Bodyless statuses** (204, 304, 1xx) never emit a body, per RFC 9110.
- **Cookies** default to `path=/`, `HttpOnly`, `SameSite=Lax`, and `Secure` auto-detected from the protocol.

**Open-redirect protection.** `Response::redirect()` runs the target through `sanitizeRedirectUrl()`: an absolute URL is only allowed if its host matches `conscribe.base_url`; protocol-relative (`//evil.com`) and `/\evil.com` forms are rejected; everything else is normalized to a single leading slash. Anything else throws `InvalidArgumentException`.

---

## Views & Layouts

Templates are plain PHP files under `app/Views/`. `Controller::render()` delegates to `View::render()`:

```php
return $this->render('home', ['pageTitle' => 'Domovská stránka']);
```

- With the default `main` layout, `layout/header.php` and `layout/footer.php` are rendered around the view, sharing the same data.
- Pass any other layout name to render the view alone — useful for partial/AJAX responses.
- Data is injected with `extract($data, EXTR_SKIP)`, so template variables can never clobber the renderer's own state.
- View paths are restricted to `[A-Za-z0-9_-/]` and reject `..`, closing off path traversal.
- A missing view throws `ViewNotFoundException`, which the router converts into a clean 404.
- Output buffering is unwound on exceptions, so a template that throws halfway never leaks half a page.

---

## Template Helpers

Defined in `app/Helpers/TemplateHelper.php` and autoloaded by Composer, so they are available in every controller and template.

### Text and formatting

```php
<?= esc($value) ?>                          <!-- null-safe, array-safe HTML escaping -->
<?= dateFormat($row['created_at']) ?>       <!-- 22. 06. 2026; custom format optional -->
<?= truncate($text, 120, '…') ?>            <!-- multibyte-safe -->
```

### Assets

```php
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<!-- /css/style.css?v=1738294821 -->
```

The version comes from `filemtime()`, so a changed file busts the browser cache automatically. The path is resolved against `public/` regardless of the current working directory.

### Components

```php
<?php component('newsletter', ['buttonText' => 'Odebírat']) ?>
```

Renders `app/Views/components/newsletter.php` with its own variable scope. Paths containing `..` are ignored.

### Inline SVG

```php
<?= svg('check-circle', ['class' => 'icon-success', 'id' => 'check']) ?>
```

Reads from `public/img/icons/`, injects the file inline, merges your class into any existing one, and adds `aria-hidden="true"` unless you set it yourself. Because inline SVG executes in the page's JS context, the content is sanitized first: `<script>` and `<foreignObject>` blocks are removed, `on*` handlers are stripped, and `href`/`xlink:href`/`src` attributes carrying `javascript:` or `data:` URIs are dropped.

### Session helpers

```php
<?php if (has_flash('success')): ?><p><?= esc(flash('success')) ?></p><?php endif ?>
<input name="email" value="<?= esc(old('email')) ?>">
```

---

## Asset Pipeline (SCSS & Minification)

`public/css/style.css` and `public/js/main.js` are **build artifacts — never edit
them directly.** Edit the sources under `assets/` and rebuild:

```bash
composer build
```

The pipeline is pure PHP (`scssphp/scssphp` + `matthiasmullie/minify`); there is
no Node toolchain. The compiled output is committed to the repository, so
deployment stays a plain checkout with no build step on the server.

| Command | What it does |
| --- | --- |
| `composer build` | Compressed CSS, minified JS. This is what you commit. |
| `composer build:dev` | Expanded CSS plus `public/css/style.css.map`, JS concatenated untouched. |
| `composer build:watch` | Rebuilds whenever a file under `assets/` changes. |

In development you rarely need any of them: `public/index.php` calls
`AssetBuilder::fromConfig()->build(force: false)` on every request, which
recompiles only when a source file is newer than its output. Production never
runs that check — it is gated on `conscribe.environment`.

### Layout

```
assets/scss/
├── main.scss                # @import list; the order is the cascade order
├── abstracts/               # $breakpoints, the mq() mixin
├── base/                    # reset, design tokens, typography
├── utilities/               # spacing and text helpers
├── layout/                  # header, main, footer
├── components/              # buttons, card, badge, table, form, ...
├── pages/                   # per-page sections
└── _a11y.scss               # :focus-visible, prefers-reduced-motion
```

Two rules when working in there:

- **Design tokens stay CSS custom properties.** The `prefers-color-scheme: dark`
  block in `base/_tokens.scss` overrides them through the cascade at runtime;
  turning them into SCSS variables would resolve them at build time and break
  dark mode. SCSS variables are for build-time values only, such as the
  breakpoints in `abstracts/_variables.scss`.
- **Responsive overrides live with their component**, via `@include mq(md)`, not
  in one collected tail block.

Note that scssphp does not implement Sass modules, so partials are pulled in with
`@import`, not `@use`.

### Keep the JavaScript ES5

`matthiasmullie/minify` is a tokenizer, not a parser, and has no ES6+ awareness.
Template literals, `?.`, `??` and some arrow-function positions can produce
output that is valid syntax but wrong behaviour — and only in the production
build. If you need modern syntax, set `assets.js.minify` to `false` in
`app/Config/main.php`; shipping a few unminified kilobytes beats shipping a
broken page. See `assets/js/README.md`.

### Configuration

The `assets` block in `app/Config/main.php` sets the source and output paths,
`auto_compile` and `source_maps` (both development-only), and `js.minify`. Every
key has a built-in default in `AssetBuilder::defaults()`, so an older deployed
config that predates the block still builds correctly.

Vendored assets (`cookieconsent.*`, `spotlight.bundle.js`) are outside the
pipeline entirely — they are neither inputs nor outputs.

---

## Images & Media Pipeline

`image()` and `image_tag()` generate a resized, format-converted variant on first render and serve it from disk afterwards.

```php
<img src="<?= image('img/photo.jpg', 1200, 500) ?>" alt="">
<!-- /media/cache/1200x500/img/photo.jpg.webp -->

<?= image_tag('img/photo.jpg', 'thumb', 0, ['alt' => 'Náhled', 'class' => 'card__img']) ?>
<!-- <img src="…" alt="Náhled" class="card__img" loading="lazy" decoding="async" width="500" height="500"> -->
```

- **Named presets** from `media.presets` can be used instead of explicit dimensions.
- **`image_tag()`** emits `width`/`height` so the browser reserves layout space, plus `loading="lazy"` and `decoding="async"` by default — all overridable.
- **Cover resizing**: aspect ratio is preserved and the overflow is cropped from the centre.
- **Drivers**: Imagick is preferred, GD is the fallback. `driver` may be pinned to one — an explicitly requested driver is never silently swapped for the other. With neither extension present, helpers degrade to the original file via `asset()` instead of failing the render.
- **Cache layout** is `<cacheRoot>/<width>x<height>/<source subdir>/<file>.<format>`, so deleting one directory drops exactly one size. The original extension is kept in the name so `photo.jpg` and `photo.png` never collide.
- **Freshness** is checked against the source `filemtime`, so a replaced source regenerates itself.
- **Atomic writes**: variants are built into a temp file and `rename()`d into place, so concurrent requests never see a half-written image.
- **Path safety**: `..` and NUL are rejected, the resolved source must stay inside `source_root` (symlinks included), the file must be a real readable image, and dimensions are clamped to `max_dimension`.
- **The cache directory is hardened** — `public/media/cache/.htaccess` disables indexes, turns the PHP engine off, and denies execution of script extensions.

Direct API when you need more:

```php
use App\Services\MediaService;

MediaService::url('img/photo.jpg', 800, 600, ['format' => 'jpg']);
MediaService::preset('card');                        // [600, 400]
MediaService::cache()?->srcset('img/photo.jpg', [400, 800, 1200], 16 / 9);
MediaService::cache()?->purge();                     // whole cache
MediaService::cache()?->purge(1200, 500);            // one size only
MediaService::reset();                               // drop the static instance (tests)
```

Purging matters after changing `format` or `quality` — those are not part of the cache path, so old files would not otherwise invalidate themselves.

---

## Sessions, Flash & Old Input

`App\Core\Http\Session` owns the whole session lifecycle.

**Lazy start.** The session is only started on the first read or write. A visitor who never touches it gets no cookie, and the page stays cacheable on proxies and CDNs.

**Hardened cookies.** `use_only_cookies`, `use_strict_mode` and `use_trans_sid=0` are forced; the cookie name defaults to `CONSCRIBEID` rather than advertising PHP; `SameSite`, `HttpOnly` and `Secure` come from config, with `Secure` auto-detected from the protocol when left `null`.

**Automatic rotation.** The session ID is regenerated every `regenerate_interval` seconds. Call `regenerate()` manually after login to defeat session fixation.

**Never fatal.** If the session cannot start (headers already sent, CLI), every method degrades to a safe default instead of throwing mid-render.

```php
$session->get('user_id');
$session->set('user_id', 7);
$session->has('user_id');
$session->remove('user_id');
$session->pull('once');              // read and delete
$session->all();                     // app data only, without internal bookkeeping
$session->clear();                   // wipes app data, keeps flash and CSRF token
$session->regenerate();
$session->destroy();

$session->flash('success', 'Uloženo.');
$session->getFlash('success');
$session->hasFlash('success');
$session->allFlash();
$session->keepFlash(['success']);    // survive one more request

$session->flashInput($request->getBodyParams());   // repopulate a form after a failure
$session->old('email');
```

Flash values are read from both the previous request's queue and the current one, so flashing and reading within a single request works. The CSRF token is stripped from flashed input, since it is regenerated anyway.

---

## CSRF Protection

Render the hidden field:

```html
<form action="/kontakt" method="POST">
    <?= csrf_field() ?>
    <textarea name="message" required></textarea>
    <button type="submit">Odeslat</button>
</form>
```

**Every state-changing route is verified by the router** — `POST`, `PUT`, `PATCH` and `DELETE`
alike — before the action runs; a missing or wrong token returns 419 through the normal error
page. There is nothing to call. The token is read from the `csrf_token` body field first and then
from the `X-CSRF-Token` header, and `Request` decodes a JSON body whatever the method, so a
`fetch()` with either works.

`PUT`, `PATCH` and `DELETE` are not reachable from an HTML form, but they are reachable from
`fetch()` on an authenticated session, which is exactly what CSRF is about. An endpoint that
authenticates some other way — a token API sends no cookies — opts out explicitly, per route or
per group:

```php
$router->post('api/webhook', [ApiController::class, 'webhook'], ['csrf' => false]);
```

On a route that opted out you can still check by hand; `check_csrf()` throws a `CsrfException`,
which the router turns into the same 419 page:

```php
public function webhook(Request $request): Response
{
    check_csrf($request);   // throws CsrfException if invalid
    // …
}
```

Or branch yourself:

```php
if (!validate_csrf($request)) {
    return $this->redirect('/kontakt');
}
```

Tokens are 32 random bytes, compared with `hash_equals()` to avoid timing leaks, and can be rotated with `$session->rotateToken()`.

For fetch/AJAX, expose the token in the layout and send it as a header — `validate_csrf()` checks the POST field first, then `X-CSRF-TOKEN`:

```html
<meta name="csrf-token" content="<?= csrf_token() ?>">
```

```javascript
fetch('/api/odeslat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify(data),
});
```

---

## Database Layer

### Connection

A lazy PDO singleton with exceptions on, associative fetch by default and emulated prepares disabled. It reports a clear message when `app/Config/main.php` or a required `db.*` key is missing, and `Connection::set()` lets tests inject their own PDO.

```php
use App\Core\Database\Connection;

$pdo = Connection::get();
Connection::isConnected();
```

### Repository

Extend it to get CRUD over one table. The connection is only opened on the first query, so a repository that is never queried costs nothing.

```php
namespace App\Models;

use App\Core\Database\Repository;

class ArticleRepository extends Repository
{
    protected string $table = 'articles';
    protected string $model = Article::class;   // optional; omit to get plain arrays
    protected string $primaryKey = 'id';
}
```

```php
$repo->find(12);
$repo->findRaw(12);
$repo->all(['created_at' => 'DESC']);
$repo->where(['published' => 1], ['created_at' => 'DESC'], limit: 10, offset: 0);
$repo->first(['slug' => $slug]);
$repo->count(['published' => 1]);
$repo->paginate(page: 2, perPage: 20, conditions: ['published' => 1]);
// → ['data' => [...], 'page' => 2, 'perPage' => 20, 'total' => 57, 'pages' => 3]

$repo->insert(['title' => 'Nadpis', 'slug' => 'nadpis']);
$repo->update(12, ['title' => 'Jiný nadpis']);
$repo->delete(12);

$repo->transaction(fn (ArticleRepository $repo) => $repo->insert([...]));
Connection::transaction(fn () => /* several repositories, one unit of work */);
```

`transaction()` commits on return and rolls back on any throw, rethrowing the exception either way.
Nested calls become savepoints, so two repositories sharing the connection compose instead of
colliding. The bare `beginTransaction()` / `commit()` / `rollBack()` passthroughs are deprecated —
they cannot nest.

Values are always bound as parameters. Identifiers (table, columns, order-by) cannot be bound by PDO, so they are validated against `^[A-Za-z_][A-Za-z0-9_]*$` and backtick-quoted — a bad identifier throws instead of reaching SQL. `null` conditions become `IS NULL`, and order directions collapse to `ASC`/`DESC`.

### Model

```php
use App\Core\Database\Model;

class Article extends Model
{
    public int $id;
    public string $title;
    public bool $published;
}
```

- Declared **public** properties are filled from the row; anything else lands in a loose attribute bag. Protected and private state stays internal and never appears in `toArray()` or JSON.
- Values are **coerced to the declared type**, so a string `'1'` from MySQL becomes `int 1` or `bool true` instead of a `TypeError`.
- Implements `ArrayAccess` (`$article['title']`), magic property access, and `JsonSerializable` — `json_encode($article)` just works.
- Reflection results are cached per class.

---

## Logging & Error Handling

Logging runs on [Monolog](https://github.com/Seldaek/monolog) behind the PSR-3 `LoggerInterface`, so application code never depends on Monolog directly.

Files land in `storage/logs/app-YYYY-MM-DD.log` and rotate daily (`max_files`); the HTML dumps described below go to `storage/exceptions/`. If the directory is not writable or the logger cannot be built, it falls back to a `NullLogger` and reports the reason through `error_log()` — logging never takes the site down. Set `log.stderr` to also stream into `docker logs`.

```php
use App\Core\Logging\Log;

Log::get()->info('Order {id} was paid', ['id' => $orderId]);
Log::get()->error('Payment gateway failed', ['exception' => $e]);
```

`{braces}` are interpolated from the context array (PSR-3), an `exception` key expands into a full stack trace, and a `WebProcessor` attaches request metadata automatically.

### What gets logged without you asking

- **Error pages** — `ErrorController` logs every page it renders: 5xx as `error`, 405 as `warning`, 404 as `info`, together with method, path, IP, referer and user agent. Since the production threshold is `warning`, 404s make no noise unless you lower `log.level`.
- **Actions returning nothing** — logged as a 500 rather than showing a blank page.
- **Uncaught exceptions and fatal errors** — `App\Core\Logging\ErrorHandler`, registered in `public/index.php`, catches everything the router did not produce itself, logs it as `critical`/`alert`, discards half-rendered output, and sends the error page.
- **PHP warnings and notices** — logged with file, line and severity name; `@`-suppressed expressions are respected and skipped, and PHP still handles them normally afterwards.

Both the logger and the error controller are built **lazily** — a successful request logs nothing, so Monolog is never loaded and the error page's database lookups never run.

### Leaking nothing in production

5xx messages describe internals (class names, missing controllers) and the error template prints the message, so outside `development` the visitor gets one generic sentence while the detail stays in the log.

### Tracy in development

When `conscribe.environment` is `development`, `App\Core\Logging\Debug` enables [Tracy](https://tracy.nette.org/): failures render as the **blue screen** with source excerpts and a full trace, and every HTML response carries the **debug bar** (time, memory, the request, plus a `Conscribe request` panel with method, path, IP and whether the call was AJAX). `dump()` and `bdump()` are available and land in the bar.

Unlike a passive renderer, the bar needs Tracy's own output hook, so in development Tracy registers the error, exception and shutdown handlers and `ErrorHandler` steps aside. Logging does not go with them: Tracy hands an exception to its logger only when it *cannot* show the blue screen (CLI, AJAX) and a plain warning it merely counts in the bar, so `Debug` wraps Tracy's handlers — every exception, PHP error and fatal is written through `ErrorHandler::logException()` / `logPhpError()` / `logFatal()` first, then Tracy displays it. The message and context shape are therefore identical in both environments, and a failure Tracy reports twice (its own `Debugger::log()` plus the wrapped handler, bridged by `TracyMonologAdapter`) still produces one log line. Tracy writes no log files of its own (`$logDirectory` stays `null`), and `$strictMode` is left off, so a notice lands in the bar's warnings panel instead of replacing the page. Configuration passwords and tokens are hidden from the output by `Dump::configureBlueScreen()` (see below).

### HTML dumps in both environments

The bar and the blue screen are development-only, but the *page* is worth having in production, where the visitor sees one generic sentence and the log holds a message and a `getTraceAsString()` blob. `App\Core\Logging\Dump` therefore renders the same blue screen **to a file** for every uncaught exception and every fatal — `storage/exceptions/exception--Y-m-d--H-i--<hash>.html`, plus the Markdown transcript Tracy writes alongside it. They live in their own directory beside `storage/logs` (one file per failure would bury the log), created on the first failure and overridable with `log.dumps_path`. The file name goes into the record as the `dump` key, so a text line points at the page with the source, the whole trace and the variables:

```
[2026-08-31 18:00:11] conscribe.CRITICAL: Uncaught RuntimeException: boom {"class":"RuntimeException", ...,"dump":"exception--2026-08-31--18-00--dfb73b4ba0.html"}
```

The hash comes from the trace, so the same failure repeating a thousand times reuses one file. Warnings and notices stay text-only. Dumps expire with `log.max_files` days, swept at most once a day, and the whole feature is switched off with `'dumps' => false`; when the directory is missing or unwritable, the dump is silently skipped and the error page renders as usual — a dump that cannot be written must never turn a handled failure into a second one.

Because dumps are written in production too, `tracy/tracy` is a **runtime** dependency (`require`, not `require-dev`), and secret masking lives in `Dump::configureBlueScreen()` rather than in `Debug` — `Debug` never runs in production, and a dump that printed `$_ENV['DB_PASSWORD']` in full would be worse than no dump at all. Keys such as `DB_PASSWORD`, `secret` and `token` are hidden on both `Debugger::$keysToHide` and the blue screen's own `keysToHide`, on top of Tracy's defaults.

**A dump is source code and variables.** `storage/` sits outside the document root and `storage/.htaccess` denies everything, but on any deployment where the log directory could be served, move it (`log.dumps_path`) or turn dumps off.

### Swapping the logger

```php
Log::set($myLogger);   // e.g. a Sentry or Slack handler, before the first Log::get()
```

---

## Modules

A module is a self-contained folder in `app/Modules/` that can be added or deleted without touching the core or `index.php`. `ModuleManager` discovers every directory containing a `Module.php` declaring `Modules\<Folder>\Module`.

```php
namespace Modules\Newsletter;

use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Module\Module as BaseModule;

class Module extends BaseModule
{
    public function boot(Request $request): Request
    {
        // Runs before routing; may return a modified request,
        // e.g. stripping a language prefix: $request->withPath($rest)
        return $request;
    }

    public function registerRoutes(Router $router): void
    {
        $router->post('newsletter', [Controllers\NewsletterController::class, 'subscribe']);
    }
}
```

The base class supplies sensible defaults, so a module only overrides what it needs. A `helpers.php` next to `Module.php` is `require_once`d automatically, letting the module publish its own global template functions.

The `modules` key in the config acts as a whitelist; remove the key to enable everything found. Directories are loaded in sorted order, and anything that is not a `ModuleInterface` implementation is skipped rather than fatal.

> `app/Modules/` currently ships empty — the module system is the extension point, and the config's `LanguageMutations` entry is an example of the whitelist syntax, not a bundled module.

---

## Menu

`MenuRepository` builds the navigation shown in the layout, with nested items and automatic active-state detection:

```php
$menu = new MenuRepository($request);   // the request decides which item is active
$menu->add('Domů', '/');
$menu->add('Služby', '/sluzby');
$menu->addSubmenu('Služby', 'Konzultace', '/sluzby/konzultace');

$menu->all();   // MenuItem[]
```

An item is active when its path matches the current URL; an active child bubbles the active state up to every ancestor, so parent items highlight correctly. Anchor-only links (`#kontakt`) are never marked active. `MenuItem` extends `Model`, so items expose `name`, `link`, `active` and `children`.

---

## Tracking Codes

Fill in only the services you use — anything left `null` or empty renders nothing, and an absent `tracking` config renders nothing at all (so the example file's placeholder IDs can never reach a live site).

```php
'tracking' => [
    'ga4_id'      => 'G-XXXXXXXXXX',
    'gtag_id'     => 'GTM-XXXXXXX',
    'adwords_id'  => 'AW-XXXXXXXXX',
    'sklik_id'    => '123456',
    'fb_pixel_id' => '1234567890',
],
```

```html
<head>
    …
    <?= renderTrackingCodes() ?>
</head>
```

All Google properties (GA4, Tag, Ads) are combined behind a **single `gtag.js` request** with one `gtag('config', …)` per ID. Sklik and Facebook Pixel emit their standard snippets, the Pixel including its `<noscript>` fallback. IDs are escaped on output, and the Sklik ID is cast to `int` because it lands in JavaScript as a numeric literal.

The front end also ships **Cookie Consent v3** (`public/js/cookieconsent.umd.js`) with `necessary` and `analytics` categories and Czech copy, so consent handling is wired up next to the tracking tags.

---

## Security Summary

| Threat | Mitigation |
| --- | --- |
| XSS | `esc()` with `ENT_QUOTES \| ENT_SUBSTITUTE`; SVG sanitization strips scripts, `foreignObject`, `on*` handlers and `javascript:`/`data:` URIs |
| CSRF | Random 32-byte token, `hash_equals()` comparison, body field or `X-CSRF-TOKEN` header; **verified by the router on every state-changing request** (`POST`, `PUT`, `PATCH`, `DELETE`) unless the route sets `['csrf' => false]`, 419 otherwise |
| Session fixation | `use_strict_mode`, interval-based ID rotation, manual `regenerate()` |
| Session hijacking | `HttpOnly`, `SameSite`, `Secure` (auto-detected), cookies-only IDs, non-default cookie name |
| SQL injection | Prepared statements throughout, identifier whitelisting, emulated prepares disabled |
| Open redirect | `sanitizeRedirectUrl()` — same-host absolute URLs only, protocol-relative and `/\` forms rejected |
| HTTP response splitting | CR/LF/NUL stripped from every header name and value |
| Header spoofing | `X-Forwarded-*`, `CF-Visitor` and `X-ARR-SSL` honoured only from `conscribe.trusted_proxies` (empty by default) |
| Script injection | Optional CSP with a per-request nonce (`security.csp`, report-only until you flip `csp_report_only`), stamped into inline scripts by `csp_nonce_attr()` |
| Path traversal | View path whitelist, `..` rejection in `component()`, `svg()`, `image()`, and `realpath()` containment in `ImageCache` |
| Clickjacking / MIME sniffing | `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff` |
| Protocol downgrade | `Strict-Transport-Security` sent on HTTPS requests (proxy-aware detection) |
| Referrer leakage | `Referrer-Policy: strict-origin-when-cross-origin` |
| Device APIs | `Permissions-Policy: geolocation=(), camera=(), microphone=()` |
| Code execution in uploads/cache | `public/media/cache/.htaccess` disables the PHP engine and denies script extensions |
| Information disclosure | Generic 5xx text in production; internals only in the log; secrets masked in the Tracy dumps; `storage/.htaccess` denies all; `Options -Indexes` |

---

## Deployment

```bash
composer install --no-dev --optimize-autoloader
```

Always install without dev dependencies in production, and keep OPcache on. The dev packages (PHPUnit, PHPStan, PHP-CS-Fixer, VarDumper) add roughly 3 000 entries to the generated classmap and several unconditional `files` autoload entries, costing about 15 ms per request when OPcache is disabled. With OPcache enabled the difference disappears.

Deployment checklist:

- Set `conscribe.environment` to `production` and `conscribe.base_url` to the real origin (redirect validation depends on it).
- Point the document root at `public/`.
- Make `storage/logs/` and `public/media/cache/` writable by the web server.
- Enable HTTPS and uncomment the HTTPS redirect block in `public/.htaccess`.
- Confirm `app/Config/main.php` is not in version control — it is git-ignored by default.
- Review `log.level` and `log.max_files` for the traffic you expect.

---

## Credits

Conscribe PHP — by **Majklovitch**. Built on [Monolog](https://github.com/Seldaek/monolog), [PHPMailer](https://github.com/PHPMailer/PHPMailer), [Tracy](https://tracy.nette.org/), [Cookie Consent](https://cookieconsent.orestbida.com/) and [Spotlight.js](https://github.com/nextapps-de/spotlight).
