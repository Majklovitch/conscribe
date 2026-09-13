# Changelog

All notable changes to Conscribe PHP. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## 2.0.0 - 2026-09-13

A major version because the base grew from a website boilerplate into one that
also carries an e-shop or a JSON API: a container, middleware, a console,
migrations, validation and authentication, all tested. Several of those change
behaviour an existing site relies on - read **Breaking** first.

### Breaking

- **The asset and lint scripts are console commands.** `bin/build.php` and
  `bin/lint.php` are gone; use `php tty build` and `php tty lint`. The composer
  scripts (`composer build`, `composer lint`) work as before.
- **CSRF is verified for `PUT`, `PATCH` and `DELETE`**, not only `POST`. A route
  that authenticates some other way - a token API - opts out with
  `['csrf' => false]`, per route or per group.
- **Errors sent to API clients are RFC 9457 problem documents**
  (`application/problem+json`) instead of the HTML page or plain text. A failed
  validation answers `422` in the same shape, with the field messages under
  `errors`. Browsers still get the page.
- **`ModuleInterface` requires `commands()` and `migrations()`.** Modules
  extending `App\Core\Module\Module` inherit both; a class implementing the
  interface directly must add them.
- **The framework version is `App\Bootstrap::VERSION`**, no longer
  `conscribe.ver` in the configuration. The key is ignored and can be removed.
- **Repositories fetch rows by column name** whatever the connection's default
  fetch mode is. Code reading positional keys from a repository row must use
  names.
- **The container leaves an optional parameter at its default** rather than
  running a factory that has not run yet, so a `?PDO $db = null` no longer
  opens a connection on construction.
- The router refuses, at registration, an unsupported HTTP method and a route
  whose middleware places anything before `Cors`.

### Added

- **Routing:** `put()`, `patch()`, `delete()`, `map()`; automatic `OPTIONS`;
  `Allow` on `405` including `HEAD` and `OPTIONS`.
- **Container:** a PSR-11 autowiring container; controllers and action
  parameters are resolved through it.
- **Middleware:** a global and per-route/per-group pipeline, and `Cors` with an
  origin whitelist, credentials and `Vary: Origin`.
- **Console:** `php tty` with `list`, `help`, typo suggestions and commands that
  modules can add.
- **Migrations:** `migrate`, `migrate:rollback`, `migrate:status`,
  `make:migration`; batches; drift detection; module migrations.
- **Validation:** rule-based validation that returns only the validated fields,
  with a redirect back for browsers and `422` for API clients; `errors()`,
  `has_error()`, `error()` template helpers.
- **Auth module:** users, session login, API tokens, `RequireLogin`,
  `RequireToken`, `Guest`, `user:create`, `token:create`.
- `Request::wantsJson()`, `Response::problem()`, `Session::exists()`.
- A test suite (PHPUnit), static analysis (PHPStan level 5) and a coding style
  (php-cs-fixer), run together by `composer check`.

### Security

- A model's JSON could contain columns it deliberately leaves out - including a
  password hash - through the positional duplicates of PDO's default
  `FETCH_BOTH` mode. Repositories now always fetch by name.
- CSRF covers every state-changing method.
- A forged `Referer` can no longer turn a failed form into a `500` or an open
  redirect.
- Authentication rotates the session id and CSRF token on login, throttles
  failed logins per email and address, hides which accounts exist from response
  times, stores API tokens only as hashes, and upgrades password hashes as their
  cost moves.
- The console exits non-zero when a command fails, so `tty migrate && deploy`
  stops after a failed migration.

### Removed

- `docs/reference_guide.md`, superseded by the README; its links pointed at
  paths on another machine.

### Changed

- Committed CSS and JS are the minified production build again.
- Code style is PSR-12, with opening braces kept on the signature line.

## 1.3.9 and earlier

See the git history.
