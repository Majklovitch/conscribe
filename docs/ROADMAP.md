# Conscribe PHP – Roadmap

Written 2026-11-19 against `dev` @ `c19b0b3` (version 2.1.1).
State at that point: PHPUnit 334 tests OK, PHPStan level 5 clean (`--memory-limit=1G`).

The plan is split into phases. Each phase is one minor line (2.2.x, 2.3.x …), and each item
in it ships as the next third-number release as soon as it is ready (see
[Release plan](#release-plan)). Work that does not change the shipped framework
(CI, static analysis, docs) is unversioned and lands on `dev` whenever it is ready.
Every item lists **why**, **what to do**, and **done when** (acceptance criteria).
Effort: S = hours, M = a day or two, L = several days.
Items marked **⚠ BC** touch the public API and say how they stay backward compatible.

Principle: the core stays small and readable, but it is **not dependency-free** –
a feature may pull in a focused, well-maintained Composer package when it needs one.

**Upstream first.** The two largest sites on Conscribe – **shopette** (e-shop, ~650 PHP
files, modules Shop/Admin/Account/Chatbot) and **supportgroup** (presentation site +
Chatbot) – already contain working, tested versions of several items below. Where an item
says *port from shopette*, the job is to generalise that code into the framework (as 2.1.1 did
with module views), not to write it again. Both sites then switch to the framework version.

**This file is the single list of framework tasks.** Earlier lists were merged into it:
the old `tasks.html` tracker, the README wishlist and `docs/audit.md`.

| File | What it is |
|---|---|
| `docs/ROADMAP.md` | this plan – versioning, status, every open and finished task |
| [docs/tasks.html](tasks.html) | the same tasks as a clickable checklist (ticks are kept in your browser) |
| [docs/website-checklist.md](website-checklist.md) | checklist for building a *client website* on Conscribe, not for the framework |
| [docs/archive/audit-1.x.md](archive/audit-1.x.md) | security audit of the 1.x code, kept for history – all findings resolved or listed here |
| [docs/NAVOD.md](NAVOD.md) | Czech user guide |
| [CHANGELOG.md](../CHANGELOG.md) | what each released version changed |

When a task is finished: tick it here and in `tasks.html`, and add the CHANGELOG entry.

---

## Current state (2026-11-19)

| Check | Result |
|---|---|
| Version | 2.1.1 committed on `dev` (`c19b0b3`); `master` is at `43050c4`; latest tag `2.1.0` |
| Tests | PHPUnit 334 tests, 601 assertions – OK |
| Static analysis | PHPStan level 5 – no errors (needs `--memory-limit=1G`) |
| Code style | PHP-CS-Fixer configured (`composer fix:check`) |
| CI | none |
| Runtime | PHP 8.4+ (Docker `php:8.4-apache`), MySQL/MariaDB via PDO |
| Dependencies | Monolog, Tracy, PHPMailer (unused), scssphp, minify, psr/container (Latte planned for 2.4.0) |
| Modules | Auth (users, API tokens, login throttle) |
| Sites on it | shopette (2.1.0), supportgroup (2.1.0) – each with its own copy of `app/Core` (shopette's differs in 12 files); see *Upstream first* and Phase 2 |
| Git leftovers | `stash@{0}` "pre-gitea-pull" (old 1.3.3 config/composer edits); untracked `.ai/`, `AGENTS.md`, `docs/` files |

### Already done
Framework features in place and covered by tests:

- [x] Front controller, router with GET/POST/PUT/PATCH/DELETE, groups, typed route params, 405 handling
- [x] Middleware pipeline, CORS middleware
- [x] PSR-11 autowiring container
- [x] Immutable `Request` (query, body, JSON, headers, cookies, files, attributes, trusted proxies)
- [x] `Response` factories: HTML, JSON, problem+json, safe redirects, `redirectExternal()`
- [x] Sessions started only on write, flash messages, old input, CSRF (419 on expiry)
- [x] Security headers (HSTS, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy); CSP with nonces (off by default)
- [x] Validator with error bag and messages
- [x] Views with layouts, components, module views (`shop::view`), `esc()` helpers
- [x] PDO connection (lazy), Repository and Model, query log in the Tracy bar
- [x] Migrations with rollback and status, `make:migration`
- [x] Console kernel `php tty` (build, lint, migrate, help, list)
- [x] Module system with routes, commands, migrations and views
- [x] Auth module: login, `RequireLogin` / `Guest` / `RequireToken` middleware, API tokens, login throttle
- [x] Config per environment (`main.php` / `main.prod.php`), dot keys, overlay, time zone
- [x] Logging (Monolog), error pages, Tracy in development, exception dumps
- [x] Asset pipeline: SCSS compile, JS/CSS minify, watch mode
- [x] Image pipeline: resize, WebP, GD/Imagick, cache, `srcset()`
- [x] Sitemap generated from the route table
- [x] Cookie consent banner in the base layout
- [x] Tracking codes (GA4, Ads, Sklik, FB Pixel) – but see 0.1, they ignore consent

Findings of the 1.x audit ([archive](archive/audit-1.x.md)), re-checked against current code:

- [x] Reply-To header injection – mail code removed (comes back safely in 5.2)
- [x] SSRF and unrestricted extensions in `MediaService` – download code removed
- [x] Unquoted table names – `Repository` quotes identifiers
- [x] Language cookie without flags – module removed; session cookie is hardened
- [x] Open redirect – `Response::sanitizeRedirectUrl()` allows relative or own-host URLs only
- [x] `Modules\` namespace autoload, missing-view 404, wrong `asset()` path, `die()` calls, `javascript:` URL, hard-coded sitemap URL, method-unaware router, static DB singleton
- [ ] PDO connection errors could leak credentials – verify in production mode (task 0.8)
- [ ] CSP missing by default – becomes default in 3.0.0 (Phase 8)
- [ ] `@fopen` in `LintCommand.php:83` – low priority (task 0.8)

---

## Versioning rules

Versions are `<major>.<minor>.<fixes/additions>`, and the changelog follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

- **major** – breaking changes. Existing sites may need code or config changes (`UPGRADING.md`).
- **minor** – a new feature line: one roadmap phase, a bigger feature or a new module.
- **fixes/additions** – everything shipped inside a line: bug fixes, security fixes and
  smaller backward-compatible additions (a helper, a method, a config key, a command).

### What counts as public API
A change to any of these is judged by the rules below:
- public classes and methods in `app/Core/`, `app/Services/` and shipped modules
  (`app/Modules/*`), including `ModuleInterface`, `MiddlewareInterface` and `Migration`;
- global helpers in `app/Helpers/TemplateHelper.php`;
- config keys and their defaults in `main.example.php`;
- `php tty` command names, arguments and options;
- route URLs the framework itself serves (`/sitemap.xml`, `/robots.txt`, `/up`);
- the database schema of shipped migrations;
- the minimum PHP version and required extensions.

Not public API: the demo controller and views (`WebController`, `home`, `test`), the SCSS/JS
starter files, `@internal` classes, private/protected members of final classes, tests.

### Which number moves
| Change | Version |
|---|---|
| Bug or security fix; new helper, method, config key or command; a deprecation; each roadmap item of the current line | **fixes/additions** – 2.2.**x** |
| Start of a new roadmap phase; a new module; a feature large enough to need its own README chapter | **minor** – 2.**x**.0 |
| Removed or renamed API, changed signature or return type, changed default that alters an existing site's behaviour, new required extension, higher minimum PHP, a migration that changes existing tables | **major** – **x**.0.0 |

Rule of thumb: if an existing site can update without touching its code or config, it is
not a major. Whether it is a minor or a third-number release is about size, not risk.

A security fix may change behaviour in a third-number release when there is no safe
alternative; the CHANGELOG then says so under **Security** with what to check.

### Deprecation policy
- Nothing public is removed outside a major. It is first marked `@deprecated` with the
  replacement named, and triggers `E_USER_DEPRECATED` (logged in development only).
- The CHANGELOG lists it under **Deprecated**; the next major removes it under **Removed**,
  together with an `UPGRADING.md` section (before → after code).
- A new default that would change existing sites ships as an opt-in config key first and
  becomes the default in the next major.

### Release process
1. Work lands on `dev`; the CHANGELOG collects it under `## Unreleased`.
2. Release commit on `dev`: rename `## Unreleased` to `## X.Y.Z - YYYY-MM-DD`, bump
   `Bootstrap::VERSION` and the README version line. `VersionTest` fails if the three disagree.
3. `composer check` passes (and CI, once Phase 1 exists).
4. Merge `dev` into `master` (`--no-ff`), annotated tag `X.Y.Z` on the merge commit
   (same style as `2.1.0`, no `v` prefix), push branch and tag to `gitea` and `github`.
5. Patch releases for an older line, if ever needed, go on a `2.x` maintenance branch cut
   from the last tag.

Pre-releases (`3.0.0-beta.1`) are allowed before a major; `VersionTest`'s regex must
accept the suffix first.

The header of `CHANGELOG.md` still says versions follow Semantic Versioning; replace that
sentence with the three-part rule above in the next release commit.

### Support
Only the newest line gets fixes. After 3.0.0 ships, 2.x gets security fixes for
six months on the `2.x` branch.

---

## Phase 0 – Fixes and housekeeping (2.1.2)

**Release 2.1.1 first:** it is committed on `dev` (`c19b0b3`) but neither merged into
`master` nor tagged (latest tag is `2.1.0`). Finish it with the release process above before
anything else lands.

### 0.1 Tracking scripts must wait for cookie consent (S) – priority
**Why:** `tracking_codes()` prints GA4 / Ads / Sklik / Facebook Pixel as live scripts.
They run before the visitor answers the cookie banner – a GDPR problem on every client site.

**What to do**
- In [TemplateHelper.php](../app/Helpers/TemplateHelper.php) render tracker scripts as
  `type="text/plain" data-category="analytics"` (GA4, gtag) and `data-category="marketing"`
  (Ads, Sklik, FB Pixel). CookieConsent v3 activates them after consent.
- For external `src` scripts use `data-src` so nothing is downloaded before consent.
- Add Google Consent Mode v2: an inline `gtag('consent', 'default', {... 'denied'})` before
  gtag loads, and `gtag('consent', 'update', ...)` from the CookieConsent `onConsent` /
  `onChange` callbacks in [assets/js/main.js](../assets/js/main.js).
- Add a `marketing` category to the consent banner config (text + preferences section).
- Escape tracker IDs for JavaScript context (`json_encode`) instead of `esc()`.
- The FB `<noscript>` pixel cannot be consent-gated – drop it.

**Done when:** with no consent, the network tab shows no request to Google, Seznam or Facebook;
after "Accept all" they load. A unit test checks the rendered markup.

### 0.2 Missing modules must not fail silently (S)
- [ModuleManager.php](../app/Core/Module/ModuleManager.php): when the `modules` config
  lists a name with no `Module.php` / class, log a warning. (Throwing would break sites that
  boot today, so that waits for 3.0.0.)
- [main.example.php](../app/Config/main.example.php): remove `LanguageMutations` from
  `modules` (until 3.6 brings i18n) and the unused `contact_form` key (comes back in 3.5).

**Done when:** a typo in `modules` is visible in the log; test covers it.

### 0.3 robots.txt – generated version comes in the 2.3.x line
A generated robots.txt belongs with the routing work – see 3.6. For 2.1.2 only replace the
`localhost:8080` line in [public/robots.txt](../public/robots.txt) with a commented
placeholder and a note to set it per site.

### 0.4 Consistent dev ports (S)
- `docker-compose.yml` publishes 8050, `base_url` and robots say 8080 – pick one (8080)
  and use it everywhere, including README and NAVOD.

### 0.5 Reproducible tooling (S)
- [composer.json](../composer.json): replace `"*"` in `require-dev` with real constraints
  matching what `composer.lock` has now: `phpunit/phpunit: ^13.3`, `phpstan/phpstan: ^2.2`,
  `friendsofphp/php-cs-fixer: ^3.95`, `symfony/var-dumper: ^8.1`.
- [Dockerfile](../Dockerfile): pin `install-php-extensions` to a release instead of `latest`.

### 0.6 Test page (S)
`/test` is the showcase of framework elements – it stays. Optional: rename it to
`/showcase` (route, view, controller action, menu) and keep it out of the sitemap with
`['sitemap' => false]`.

### 0.7 Docs cleanup (S)
- `AGENTS.md` points to `.ai/CONTEXT.md`, which does not exist – create it (short summary +
  links) or point `AGENTS.md` at `.ai/index.md`.
- `.ai/structure.md` and `.ai/workflows.md` describe the 1.x layout (`BaseModel`,
  `app/Core/Router.php`, `ContactSender`, `LanguageMutations`) and use the old path
  `/home/mbrotys/Web/...`. Rewrite them for 2.x with repo-relative links.
- [x] Task lists merged into this file; `tasks.html`, the website checklist and the 1.x
  audit moved into `docs/` (2026-11-19).
- [x] "Dependency-free" wording removed from README and `.ai/` (2026-11-19).
- Delete the stray file `adsadsadas` (a pasted `git log`). Keep `tty` in the root – it is
  the shim behind `php tty` and the composer scripts.
- Commit `.ai/`, `AGENTS.md` and `docs/`.
- Reapply or drop `stash@{0}` (old 1.3.3 config/composer edits from before the Gitea pull).
- Copy `main.example.php` to `main.php` locally and check the site boots with it.

### 0.8 Small hardening (S)
- Check that a failed DB connection in production shows the generic error page and logs
  without the DSN/password (`Connection.php`, `ErrorHandler`); add a test.
- Replace `@fopen` in [LintCommand.php:83](../app/Core/Console/Commands/LintCommand.php#L83)
  with an explicit check and error message.

---

## Phase 1 – Quality gate (unversioned, start alongside Phase 0)

None of this changes the shipped framework, so it gets no release of its own. It should be
in place before 2.2.0 so every later release is checked by CI.

### 1.0 Release helper (S)
`php tty release <version>` (dev-only command): checks the working tree is clean, renames
`## Unreleased` in the CHANGELOG, bumps `Bootstrap::VERSION` and the README line, runs
`composer check`, commits `Release X.Y.Z`. Merging and tagging stay manual.

### 1.1 CI pipeline (M)
- Gitea Actions workflow (and/or GitHub, both remotes exist): `composer validate`,
  `composer install`, `php tty lint`, `php-cs-fixer --dry-run`, PHPStan, PHPUnit,
  and `php tty latte:lint` once Latte is in (4.0).
- Matrix: PHP 8.4 and 8.5.
- Run on push to `dev` and on pull requests to `master`.

**Done when:** a failing test or style error blocks a merge.

### 1.2 Stricter static analysis (M)
- Raise PHPStan from level 5 to 6 (missing types), then 7–8 if the noise is manageable.
- Store the memory limit in `composer analyse` (already) and in CI.
- Use a baseline file only as a temporary step, never as a permanent dumping ground.

---

## Phase 2 – Ready for apps built on Conscribe (2.2.x)

The CMS will be **its own project built on Conscribe** (like shopette and supportgroup), not
a part of this repository. This phase gives such projects what they need from the framework.

**Why it comes first:** until the core is a package, every later release (named routes,
Latte, mailer …) would have to be copied by hand into shopette and supportgroup. Once it
is, every release after 2.2.x reaches all sites through `composer update`.

**The problem today:** every site carries a *copy* of `app/Core`. Shopette's copy already
differs from the framework in 12 files (its own `Database/Schema.php`,
`Logging/AlertHandler.php`, changes to `Request`, `Response`, `Container`, `View` …), so
each framework release has to be ported by hand into each site. A CMS would be a third copy.

2.2.0 core as a Composer package → 2.2.1 modules as Composer packages →
2.2.2 extension points → 2.2.3 app skeleton and upgrade guide.

### 2.1 Core as a Composer package (L)
- Split the framework (`app/Core`, `app/Services` that are generic, helpers, `bin/tty`
  kernel) into a package, e.g. `majklovitch/conscribe`, installed from Gitea/GitHub via a
  Composer VCS repository (Packagist optional).
- This repository becomes the **app skeleton** that requires the package; shopette,
  supportgroup and the CMS require it the same way and update with `composer update`.
- **⚠ BC:** 2.x keeps the `App\Core\…` namespace inside the package so existing sites
  only delete their `app/Core` copy. The move to a `Conscribe\…` namespace is a 3.0.0
  change (with `class_alias` shims for one major).
- Before the switch, fold the site-specific core changes worth keeping back into the
  framework (shopette `Schema`, `AlertHandler`, the `Request`/`Response` changes); the rest
  must become extension points (2.3) instead of core edits.

### 2.2 Modules as Composer packages (M)
- `ModuleManager` also discovers modules from installed packages
  (`composer.json` → `"extra": {"conscribe": {"modules": ["Vendor\\Cms\\Module"]}}`),
  with routes, commands, migrations, views and assets.
- `php tty vendor:publish <module>` copies a module's config / views / assets into the app
  for overriding.
- Lets parts of the CMS (or shopette's Chatbot, shared by both sites) be reused as packages.

### 2.3 Extension points (M)
Everything here is generic – no CMS code in the framework. What ships in 2.2.2:
- **Fallback route:** `$router->fallback($handler)` that runs after all app and module
  routes – a CMS serves pages by path from the database through it.
- **Replaceable core services:** every core service the sites patched (`Request` factory,
  `Response` sending, `View`, logger handlers such as shopette's `AlertHandler`) is resolved
  through the container, so an app swaps it with a binding instead of editing the package.
- **Route and sitemap providers from modules** (sitemap providers already exist), view
  overrides (already exist via `ViewPaths`).
- A `slug()` helper.

What a CMS also needs arrives with its own item later – each is written as an extension
point from the start:
- events with `ModelSaved` / `ModelDeleted` hooks (5.4) and cache tags (5.1);
- uploads (3.2), image variants (4.2), settings (5.6), i18n (6.1);
- model casts incl. JSON (7.2);
- admin menu items, widgets, permissions and settings sections from modules (7.5);
- `Redirects::add($old, $new)` so a renamed page keeps its URL (7.6).

### 2.4 App skeleton and upgrade guide (S/M)
- `composer create-project` (or a Gitea template repo) for a new site: skeleton + package.
- `docs/building-apps.md`: how to structure an app on Conscribe – modules, overrides,
  what belongs in the app and what must never be edited in the package.
- Migration guide for shopette and supportgroup from the copied core to the package.

**Done when:** shopette and supportgroup run on the package without their own `app/Core`,
and a new project created from the skeleton can serve pages from the database through the
fallback route without patching the framework.

**CMS project (separate repository, for reference):** pages tree, content blocks
(JSON + Latte templates), media library, menus, revisions and scheduled publishing,
posts/news, translations, editor roles. The public side can start after 2.2.x; the editing
side needs uploads (2.3.x), Latte (2.4.x), settings (2.5.x), the query builder and the admin
(2.7.x–2.8.x).

---

## Phase 3 – Routing and HTTP (2.3.x)

2.3.0 named routes → 2.3.1 `/up` + maintenance → 2.3.2 robots.txt + server config →
2.3.3 uploads (`file()` deprecated) → 2.3.4 file/cache responses → 2.3.5 rate limiting.

### 3.1 Named routes and URL generation (M) – biggest missing piece
**Why:** every link in views is hard-coded; renaming a URL means grep-and-replace.

**What to do**
- `Router::get('clanek/{slug}', $h, ['name' => 'article'])` and a `->name('article')`
  style alternative if it reads better.
- `Router::url(string $name, array $params = [], array $query = [], bool $absolute = false)`.
- Template helper `route('article', ['slug' => $a->slug])`.
- Fail loudly on unknown names and missing params; encode params with `rawurlencode`.
- Use names in the menu (`MenuRepository`) and the sitemap.

**Done when:** README examples and the demo views use `route()`; tests cover params,
optional segments, query string and absolute URLs.

### 3.2 Uploaded files (M)
- `App\Core\Http\UploadedFile`: name, client MIME, real MIME (`finfo`), size, error,
  `moveTo()`, normalised multi-file arrays.
- **⚠ BC:** `Request::file()` returns the raw `$_FILES` array today. Add
  `Request::upload(string $key): UploadedFile|array|null` alongside it, deprecate `file()`
  in the same release, and remove it in 3.0.0.
- `UploadService`: size limit, MIME + extension allowlist, random file names,
  storage outside the web root (`storage/uploads`), optional hand-off to the image pipeline.
- Validator rules: `file`, `image`, `mimes:jpg,png`, `max_size:2M`.

### 3.3 Responses for files and caching (M)
- `Response::download($path, $name)` and `Response::file($path)` with correct
  `Content-Type`, `Content-Disposition` and `Content-Length`; streaming for large files.
- `Response::withCache(maxAge, public)`, `withEtag()`, `withLastModified()`, and a
  conditional-request check returning 304. Use it for `sitemap.xml`, robots and media cache.

### 3.4 Rate-limit middleware (M)
- *Port from shopette:* `App\Services\RateLimit\RateLimiter` and its `rate_limits`
  migration. Generalise it to a `RateLimiter` (key, max attempts, decay window) with DB and
  APCu stores.
- `RateLimit` middleware: per IP or per custom key, `429` with `Retry-After`, JSON problem
  response for API routes. `LoginThrottle` then uses `RateLimiter`.
- **⚠ BC:** `LoginThrottle` keeps its public methods and the `login_attempts` table; the new
  store gets its own migration.

### 3.5 Health endpoint and maintenance mode (S)
- `GET /up` returns 200 (and optionally checks the DB) – for uptime monitors and Docker
  `HEALTHCHECK`. *Port from shopette:* `App\Services\Ops\HealthCheck`.
- `php tty down [--secret=...]` / `php tty up`: a flag file makes every request return 503
  with `Retry-After` and a maintenance page; the secret cookie lets the developer through.

### 3.6 robots.txt from config (S)
- Replace the static [public/robots.txt](../public/robots.txt) with a route like `sitemap.xml`
  that writes `Sitemap: {base_url}sitemap.xml`.
- Config key `seo.robots_disallow` (list) and an automatic `Disallow: /` outside production,
  so staging is not indexed.
- **⚠ BC:** a site that edited the static file keeps it – the route only answers when
  `public/robots.txt` does not exist (Apache serves real files first). The CHANGELOG says to
  delete the file to switch over.

**Done when:** production never ships `localhost` in robots.txt; test for both environments.

### 3.7 Server config (S)
- [public/.htaccess](../public/.htaccess): add `mod_deflate` (and `mod_brotli` where
  available) for text types.
- **⚠ BC:** the HTTPS redirect stays opt-in in 2.x (`SetEnv CONSCRIBE_FORCE_HTTPS 1` or an
  uncommented block that skips `localhost` / `*.test` / `*.local`) because turning it on
  would break sites served over plain HTTP. On by default from 3.0.0.
- Ship an `nginx.conf.example` with the same rules.

---

## Phase 4 – Templates and front end (2.4.x)

2.4.0 Latte → 2.4.1 SEO helpers → 2.4.2 picture helper → 2.4.3 SCSS polish.

Latte comes before the services line so that HTML e-mails, the contact module and the admin
are written in Latte from the start.

### 4.0 Latte templates (M)
**Why:** plain PHP views are only safe if every value goes through `esc()`. Latte escapes
automatically and by context (HTML, attributes, `<script>`, `<style>`, URLs), and brings
layouts, blocks, `{include}` and `n:attributes`. Standalone package `latte/latte` – no Nette
needed.

**What to do**
- Add `latte/latte` (^3) to `composer.json`.
- `App\Core\Template\LatteEngine`, built once in the container:
  - temp dir `storage/cache/latte`; `setAutoRefresh(true)` in development only;
  - a loader on top of `ViewPaths`, so `shop::orders/show` and module overrides
    (`ViewPaths::register(..., prepend: true)`) resolve `.latte` files the same way;
  - the global helpers registered as Latte functions (`asset`, `route`, `csrf_field`,
    `old`, `flash`, `config`, `tracking_codes`, `component` …) – without `esc`, which Latte
    makes unnecessary; `|money`, `|date` style filters where helpers exist today;
  - the CSP nonce passed to every template and printed on `<script>` / `<style>`;
  - the Tracy Latte panel (`Latte\Bridges\Tracy\LattePanel`) in development.
- **⚠ BC:** `View::render('home')` looks for `home.latte` first and falls back to
  `home.php`, so existing PHP views keep working. A Latte page can extend a Latte layout;
  PHP pages keep `layout/header.php` + `footer.php`.
- Ship a Latte layout (`app/Views/@layout.latte`) that matches the current header/footer.
- Convert the demo views (`home`, `test`, `errors/error`) to `.latte` as the example.
- `php tty cache:clear` empties the Latte cache (also used by the cache layer in 5.1).
- `php tty latte:lint` (wraps `Latte\Tools\Linter`) – and run it in CI.
- README and NAVOD: a "Templates" chapter with the PHP → Latte cheat sheet
  (`<?= esc($x) ?>` → `{$x}`, `<?php foreach ?>` → `{foreach}`, `n:if`, `{block}`, `{include}`).

**Done when:** a page, a module view and a component render from `.latte`; escaping is
tested in HTML, attribute, JS and URL context; a `.php` view still renders unchanged;
`composer check` passes.

### 4.1 SEO helpers (M)
- One `seo()` builder used by controllers: title, description, canonical, robots meta,
  Open Graph, Twitter Card. The layout prints it with `{seo_tags()}` (Latte) or
  `seo_tags()` (PHP views). Helpers that build markup return `Latte\Runtime\Html` so
  Latte does not escape them a second time.
- `json_ld(array $data)` helper with ready builders for `Organization`, `WebSite`,
  `BreadcrumbList`, `Article`, `LocalBusiness`.
- Breadcrumbs from `MenuRepository`.

### 4.2 Image helper (S/M)
- `picture('img/x.jpg', preset: 'card', widths: [400, 800, 1200], sizes: '...')` that
  uses `ImageCache::srcset()` and prints `<picture>`/`<img>` with `width`, `height`,
  `loading="lazy"` and `decoding="async"` (opt out with `eager: true` for the LCP image).
- AVIF output where the driver supports it, with WebP fallback.
- Registered as a Latte function too: `{picture('img/x.jpg', preset: 'card')}`.

### 4.3 Base SCSS polish (S)
- Dark-mode tokens (`prefers-color-scheme` + `[data-theme]`), skip link,
  `prefers-reduced-motion`, visible focus styles.

---

## Phase 5 – Services (2.5.x)

2.5.0 cache → 2.5.1 mailer → 2.5.2 contact module → 2.5.3 events → 2.5.4 scheduler →
2.5.5 settings.

### 5.1 Cache layer (M)
- PSR-16 (`psr/simple-cache`) interface with File and APCu drivers, plus Null for tests.
- `remember($key, $ttl, $callback)` helper.
- `php tty cache:clear` (already exists from 4.0) clears the cache stores as well.
- Use it for menus, the sitemap and expensive queries.

### 5.2 Mailer (M)
**Why:** PHPMailer is a required dependency, but nothing uses it.
- *Port from shopette:* `App\Services\Mail` – `Mailer`, `MailMessage`, `Mailable`,
  `MailAddress`, `MailAttachment`, transports, `HtmlToText`, `MailTemplateRenderer`,
  `UnsubscribeLinks`, the mail queue (`Queue/`, `mail_queue` migration) and the commands
  `mail:send` and `mail:test`.
- Drivers `smtp` (PHPMailer), `log` (writes the message to the log) and `null`.
  Development defaults to `log` or Mailpit.
- HTML body from a Latte template (switch `MailTemplateRenderer` to the 4.0 engine) with
  the plain-text part generated by `HtmlToText`.
- Config area `mail` (driver, host, port, user, pass, encryption, from).
- Header-injection safety: reject CR/LF in addresses and subjects.

### 5.3 Contact-form module (M)
- `Modules\Contact`: route, controller, Latte view, Validator rules, CSRF, honeypot field,
  minimum fill-time check, `RateLimit` middleware, Mailer.
- Config: recipient, sender, subject prefix, optional storage of messages in the DB
  (migration included).
- Flash success message, old input on errors.

**Done when:** the module can be dropped into a client site by adding it to `modules`.

### 5.4 Event dispatcher (S/M)
- Small PSR-14 style dispatcher in the container; modules register listeners in
  `Module::boot()`.
- Initial events: `RequestHandled`, `UserLoggedIn`, `UserLoggedOut`, `MailSent`.

### 5.5 Scheduler (M)
- *Port from shopette:* `ScheduleRunCommand` and `CronRunRepository` (`cron_runs` table,
  so the admin can see when each task last ran).
- `php tty schedule:run` called by cron every minute; tasks registered in
  `app/Config/schedule.php` and by modules (`everyMinute()`, `daily()`, `cron('...')`).
- Overlap lock so a slow task does not run twice.
- First users: clean old login attempts, old logs, media cache, the mail queue.

### 5.6 Settings stored in the database (S/M)
- *Port from shopette:* `SettingsRepository` (`settings` table) feeding `Config::overlay()`
  (the overlay is already in 2.1.1), and `settings:export` / `settings:import`.
- Needed by the admin (7.5) and the CMS (site name, contacts, social links, SEO defaults).

---

## Phase 6 – i18n (2.6.x)

2.6.0 ships the module; follow-ups (more plural rules, translated validator messages if not
ready in time) are 2.6.1, 2.6.2 …

### 6.1 Translation module (L)
- `Modules\I18n`: language files `lang/{locale}/*.php`, `trans('key', ['name' => ...])`
  with plurals, fallback locale.
- Latte translator (`TranslatorExtension`), so templates use `{_'contact.title'}` and
  `{translate}…{/translate}`.
- Locale from URL prefix (`/en/...`), default locale without prefix; redirects from browser
  language only on the homepage and only once (cookie).
- Named routes per locale; `route()` keeps the current locale.
- `hreflang` alternates in the SEO helper and in the sitemap.
- Validator messages translated through the same files.

---

## Phase 7 – Data layer and admin (2.7.x and 2.8.x)

None of this has to break anything, so it stays in 2.x.
2.7.0 query builder → 2.7.1 relation loading → 2.7.2 paginator (array form deprecated) →
2.7.3 seeders.
2.8.0 admin shell → 2.8.1 password reset + 2FA + audit log → 2.8.2 redirect manager.

### Decision: SQL-first, own query builder, no ORM (2026-11-19)
Checked against the two real sites:

- **shopette** runs ~600 query calls across 65 files: correlated subqueries, `EXISTS`
  filters, `GROUP BY` reports with `SUM`/`COALESCE`, `SELECT … FOR UPDATE` in invoicing,
  transactions, and a store scope (`product_store`) that overlays per-store prices onto typed
  `ProductModel`s. Domain logic lives in services (`OrderWorkflow`, `OrderService`), not in
  entities. **supportgroup** is a small presentation site with one module's repositories.
- **Doctrine** is too heavy (ORM, DBAL, persistence, proxies, metadata cache) and wants
  domain logic inside entities – the opposite of how both sites are written.
- **Nette Database Explorer** is light, but works with `ActiveRow` instead of the typed
  `Model`s both sites use, its relation magic would fight the store overlay, and the reports
  would stay raw SQL anyway – two styles in one codebase.
- **Eloquent / Cycle** – same objections as Doctrine to a lesser degree, plus Laravel
  baggage (Eloquent) or a schema compile step (Cycle).

What the sites actually lack is a safe way to build **dynamic** SQL. Today
`ProductRepository::adminSearch()` assembles `$where[]` / `$params[]` by hand, reports
build `IN (?, ?, …)` with a private `marks()` helper, and the store scope is concatenated
into strings (`storeJoin()`, `$sc`). The builder below targets exactly that; raw SQL stays a
first-class citizen for reports.

If a future project really needs an ORM, add Cycle ORM as an optional module there –
not in the core.

### 7.1 Query builder (L)
- `$db->select('products p')` / `Repository::query()` returning a fluent, immutable
  builder: `columns()`, `join() / leftJoin()`, `where()`, `orWhere()`, grouped conditions
  (`where(fn ($q) => …)`), `whereIn()` (placeholders generated, empty list → `1 = 0`),
  `whereExists(subquery)`, `like()` with proper `ESCAPE`, `when($cond, fn)` for optional
  filters, `groupBy() / having()`, `orderBy()`, `limit() / offset()`, `forUpdate()`.
- `raw('…', $bindings)` for expressions, and subqueries as builders – nothing forces the
  caller back to string concatenation.
- **Scopes:** a repository declares reusable conditions (`scopeStore(int $id)`), so the
  shopette store filter becomes one call instead of a string fragment.
- Terminal methods: `get()` → typed `Model`s via the repository, `first()`, `value()`,
  `count()` (reuses the same WHERE), `exists()`, `paginate()`, `insert / update / delete`,
  `upsert()` (`ON DUPLICATE KEY UPDATE`).
- Always bound parameters, identifiers quoted with the existing `quoteIdentifier()`;
  MySQL/MariaDB first, SQLite for tests.
- `Repository::where()` / `count()` / `paginate()` keep their signatures and use the
  builder inside.

**Done when:** `adminSearch()` and one `SalesReport` query from shopette can be rewritten
with it without losing any condition, and the generated SQL is covered by tests.

### 7.2 Relation loading (M)
- Shopette loads relations by hand (`withRelations()` → `attachMedia()`,
  `attachGradings()`). Generalise it: `Repository::load($models, 'media', fn ($q) => …)`
  runs one `WHERE product_id IN (…)` query per relation and attaches the results –
  no N+1, no magic properties.
- Declared per repository: `hasMany`, `belongsTo`, `belongsToMany` (pivot table).
- `Model` casts: `json`, `bool`, `int`, `DateTimeImmutable`, enums – needed by the CMS
  (content blocks as JSON).

### 7.3 Paginator (S)
- `Paginator` object (items, total, page, perPage, links) and a `pagination()` view helper
  with accessible markup; built on the query builder's `count()` + `limit()`.
- **⚠ BC:** `Repository::paginate()` returns an array shape today. Add
  `Repository::paginator()` returning the object, deprecate the array form, and make
  `paginate()` return `Paginator` in 3.0.0. `Paginator` implements `ArrayAccess` for the old
  keys (`data`, `page`, `perPage`, `total`, `pages`) to soften the switch.

### 7.4 Seeders and factories (M)
- `php tty db:seed [Class]`, seeders in `database/seeders` and per module.
- Simple factories for tests.

### 7.5 Admin shell (L)
- *Port from shopette's `Admin` module* what is not shop-specific: layout, menu, bulk
  actions (`Http/BulkAction`), `AuditLogRepository`, roles/permissions (`RoleRepository`),
  `StaffTwoFactor` (TOTP).
- Login-protected layout under `/admin` (routes file already exists), dashboard, user list.
- Password reset by email (token table, expiry, rate limit) – needs the Mailer.
- Optional TOTP two-factor login and an audit log of admin actions.
- Extension points so modules (Shop, CMS) add menu items, dashboard widgets and
  permissions without editing the admin.

### 7.6 Redirect manager (M)
- Table of old URL → new URL (301/302) checked before the 404, with a CLI import from CSV.
  Useful when moving an old client site onto Conscribe without losing search ranking.

---

## Ongoing – developer experience

- **Generators:** `make:controller`, `make:module`, `make:middleware`, `make:repository`,
  `make:command` (only `make:migration` exists now).
- **`php tty routes`:** table of method, URL, name, handler, middleware.
- **`php tty serve`:** PHP built-in server with the front controller as router.
- **`php tty media:clear`:** empty the image cache.
- **Docker rewrite:** dev compose with web + MariaDB + Mailpit (and the scheduler);
  a production image with `composer install --no-dev`, opcache settings, no bind mount,
  `HEALTHCHECK` on `/up`.
- **README rewrite** in plain human language (wishlist item in README); keep the long
  reference in `docs/`. Translate or mirror `docs/NAVOD.md` in English.
- **Config objects:** typed config classes per area (README wishlist) – planned for 3.0.0.
- **Simplify code** that is harder to read than it needs to be (README wishlist): go through
  `Router`, `Session`, `Request` and `Bootstrap` (the largest classes) one at a time,
  behaviour unchanged, tests first.
- **New welcome page:** redo `home` so it reads as written by a person, keep `/test` as the
  element showcase (README wishlist).
- [x] **ORM decision** (README wishlist): decided 2026-11-19 – SQL-first with an own query
  builder and relation loading, no ORM in the core (see Phase 7).
- **Backups:** *port from shopette* `backup:run`, `backup:list`, `backup:verify`
  (`Services/Backup`) – DB dump + files archive with rotation.
- **`config:check`** and **`key:generate`**: *port from shopette*.

New `tty` commands and generators are third-number additions in whichever line is current;
Docker and docs changes are unversioned.

---

## Phase 8 – 3.0.0 (major)

Only the breaking changes collected during 2.x, so upgrading is one well-documented step.
Release `3.0.0-beta.1` first and run it on one real client site before the final tag.

- Remove everything deprecated in 2.x: `Request::file()` array form, `paginate()` array shape.
- `Repository::paginate()` returns `Paginator`.
- A module listed in `modules` but missing throws instead of logging a warning.
- HTTPS redirect on by default in `public/.htaccess` (local hosts still skipped).
- CSP on by default with the starter policy, report-only switched off.
- Package namespace `App\Core\…` → `Conscribe\…` (`class_alias` shims kept for the 3.x line).
- Typed config objects per area (arrays still readable through `Config::get()`).
- Latte is the default for new views and `make:*` generators; `.php` views keep working
  (dropping them would need its own major and is not planned).
- Re-evaluate the minimum PHP version (raise only here, never in a minor).
- `UPGRADING.md` with a before/after section for each item, and a `php tty upgrade:check`
  command that scans the app for deprecated calls.

---

## Release plan

| Line | Theme | Releases in order | Breaking? |
|---|---|---|---|
| 2.1.x | current line | 2.1.1 merge + tag (already on `dev`) · 2.1.2 consent fix, module warning, robots placeholder, ports, pinned dev tools, small hardening, docs | no |
| – | unversioned | CI, PHPStan level 6+, release helper | – |
| 2.2.x | Apps on Conscribe | 2.2.0 core as Composer package · 2.2.1 modules as packages · 2.2.2 extension points (fallback route, replaceable services) · 2.2.3 app skeleton + guide; shopette and supportgroup switch | no |
| 2.3.x | Routing & HTTP | 2.3.0 named routes · 2.3.1 `/up` + maintenance · 2.3.2 robots.txt + server config · 2.3.3 uploads · 2.3.4 file/cache responses · 2.3.5 rate limiting | no |
| 2.4.x | Templates & front end | 2.4.0 Latte · 2.4.1 SEO helpers · 2.4.2 picture helper · 2.4.3 SCSS polish | no |
| 2.5.x | Services | 2.5.0 cache · 2.5.1 mailer · 2.5.2 contact module · 2.5.3 events · 2.5.4 scheduler · 2.5.5 settings | no |
| 2.6.x | i18n | 2.6.0 translation module · follow-ups | no |
| 2.7.x | Data layer | 2.7.0 query builder · 2.7.1 relation loading + casts · 2.7.2 paginator · 2.7.3 seeders | no |
| 2.8.x | Admin | 2.8.0 admin shell · 2.8.1 password reset + 2FA + audit log · 2.8.2 redirect manager | no |
| – | separate repo | CMS built on Conscribe – public side after 2.2.x, editing side after 2.8.x | – |
| 3.0.x | Breaking clean-up | 3.0.0-beta.1 · 3.0.0 removals and new defaults from Phase 8, typed config | **yes** |

The numbers inside a line are a plan, not a promise: fixes found along the way take the
next free number (e.g. a router bug after 2.3.0 makes the uptime work 2.3.2), and any
generator or `tty` command slots in wherever it is ready. Each release ships with tests,
a CHANGELOG entry and a README/NAVOD section.
