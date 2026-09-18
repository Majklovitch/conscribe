# Changelog

All notable changes to Conscribe PHP. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## Unreleased

### Changed

- **Only a write starts a session now.** 2.1.0 stopped reads from starting one,
  but left every other method calling `start()` outright, so the promise held
  only for pages that read. `remove()`, `clear()`, `rotateToken()` and
  `destroy()` delete things that cannot exist in a session that does not
  exist, yet each wrote a session file and sent a cookie to find that out -
  and because `Auth::logout()` calls three of them, plus `regenerate()`, every
  hit on a logout route from a visitor who was never signed in (a crawler, a
  prefetch, a repeated POST) created a session and then immediately cleared
  it. Those four, `regenerate()` and `id()` now resume the session the visitor
  already has and do nothing when there is none; `set()`, `flash()` and
  `token()` - the three calls that genuinely store something - still start one.
  `regenerate()` loses nothing by this: session fixation needs an ID that
  already exists, `start()` sets `session.use_strict_mode`, under which PHP
  refuses an uninitialised ID a visitor supplies, and a planted ID that is
  real arrives with a cookie, which is exactly when it still runs. `id()`
  returns `null` for a visitor with no session instead of minting one, which
  is what its `?string` return type always promised.

### Fixed

- **A size rule's bound must now be a number, not merely present.** 2.1.0
  refused `min`, `max` and `between` when the bound was missing, but a bound
  that was there and not numeric still went through `(float)` and became `0` -
  so `'password' => 'required|min:eight'` cleared every value, the same silent
  pass the missing-bound check was written to stop. `max:abc`, cast the same
  way, failed every value instead. Both are now refused with the bound quoted
  back: `The "min" rule needs a number, not "eight", e.g. min:8.`
- **`between` reports a missing upper bound whatever the value measures.** The
  two bounds were resolved on either side of an `&&`, so a typo like
  `between:5` was only refused for values that cleared the lower bound;
  anything shorter got an ordinary validation error reading "must be between 5
  and 5 characters", because the message falls back to the first bound when
  the second is absent. Both bounds are resolved before the comparison now, so
  the mistake in the rules surfaces on the first submission rather than on
  whichever one happens to be long enough.

## 2.1.0 - 2026-09-18

### Added

- **SQL panel in the Tracy bar.** In development, every statement sent through
  `Connection` is listed with its bindings, time and error: repositories,
  classes using `db()` directly, migrations, `exec()`/`query()` and
  transactions with their savepoints. A statement repeated within a request is
  flagged with its count, which is how an N+1 shows up. Recording is done by
  `LoggingPdo`, which `Connection` builds only while `QueryLog` is on - where
  the bar is shown - so production and the console get a plain `PDO`. At most
  500 queries are kept per request; the count goes on past that.
- `docs/NAVOD.md`, a Czech how-to for adding pages, forms, tables, APIs,
  middleware, commands and modules.

### Fixed

- **A nested `group()` no longer drops its parent's middleware.** The group
  defaults were merged with `array_merge()`, which replaces `middleware`
  wholesale, so an admin group inside a signed-in group ran only its own entry
  and left its pages reachable by anyone. Nested groups now go through the same
  merge a route's own options do: the parent's entries run first, then the
  child's.
- **`min`, `max` and `between` are refused when their bound is missing**, the
  way `regex`, `same` and `different` already were. `min` compared against `0`,
  which every value clears, so a typo that dropped `:8` left the field looking
  validated while accepting a single character.
- **Two migrations with the same filename in different directories are
  refused.** A migration is identified by its filename alone, so the later path
  silently replaced the earlier one: one file ran, the shared name was logged as
  applied, and the other became permanently invisible - `migrate:status` called
  it done while its table had never been created. Listing the same directory
  twice is still not a clash.
- **`keepFlash()` no longer undoes a `flash()` made during the same request.**
  The queues were combined the other way round, so extending an aged message by
  one request overwrote the value flashed a line earlier.

### Changed

- **Reading from the session no longer starts one.** The lazy start promises
  that a visitor who never touches the session gets no cookie and the page
  stays cacheable, but "touch" included reading: `flash()`, `old()`, `errors()`
  and any `$session->get()` in a template wrote a session file and sent a
  cookie to an anonymous visitor just to find it empty. `get`, `has`, `pull`,
  `all`, `getFlash`, `hasFlash`, `allFlash`, `keepFlash` and `old` now return
  their default when there is neither a running session nor a session cookie,
  and `validateToken()` follows the same rule - a POST with no session cannot
  carry a matching token. Writes (`set`, `flash`, `token`) still start one, so
  a page stays session-less only while nothing on it writes; a form still needs
  `csrf_field()`, which creates a session to hold the token.
- **A cached image variant is served without re-validating its source.** Every
  `image()` call used to `realpath()` the source and the root, then `is_file()`,
  `is_readable()` and `getimagesize()` - which opens the file and reads its
  header - before it even looked at the cache. All of that answers whether a
  file may be *converted*, and a variant only exists because its source once
  passed, so `get()` now checks freshness first and validates only when it has
  to generate. `normalizeRelativePath()` still refuses `..` and NUL before
  either path, and the source root's `realpath()` is computed once per instance.
  Measured on one machine, medians of five runs with opcache on: `image()`
  364 us to 100 us, `GET /test` (two images) 772 us to 465 us.
- `LazyConnection` provides `run()`, the typed-binding query helper that was
  `Repository::run()`, and `ApiTokens` and `LoginThrottle` use it. Rows it
  returns are fetched by column name for named placeholders too, not only
  positional ones.
- `LazyLogger` replaces the identical `logger()` helpers in `ErrorPage`,
  `SitemapService` and `TracyMonologAdapter`, the way `LazyConnection` replaces
  the `db()` ones. Constructors keep their signatures.

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
- **`Request::getClientIp()` trusts configured proxies by default.** With
  `conscribe.trusted_proxies` set it returns the client from `X-Forwarded-For`
  instead of the proxy's address; pass `false` for the direct peer. Sites with
  no trusted proxies are unaffected.

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
- A failed validation no longer flashes passwords into the session: fields
  named like a password, and the CSRF token, are dropped from old input.
- `Response::sanitizeRedirectUrl()` refuses targets that start with a backslash
  or contain a tab, which browsers read as `//host`.
- The trailing-slash rule in `public/.htaccess` no longer turns `//evil.com/`
  into `Location: //evil.com`.
- `Request::getClientIp()` honours `trusted_proxies` by default and walks
  `X-Forwarded-For` from the right. It used to return the proxy for every
  visitor unless asked, and the client-written left-most entry when asked -
  either of which defeated the login throttle behind a proxy.

### Fixed

- `PUT`, `PATCH` and `DELETE` bodies sent as forms (`x-www-form-urlencoded` or
  `multipart/form-data`) are parsed; PHP does so for `POST` only, so they reached
  validation and the CSRF check empty.
- `min`, `max` and `between` measure a string of digits by its length unless the
  field is `numeric` or `int`, so phone numbers and PINs validate.
- A `nullable` field that was not sent is left out of the validated data instead
  of being returned as `null`.
- The container falls back to an optional parameter's default when the class
  exists but cannot be autowired, instead of throwing.
- A `Request` injected into a constructor is the request being dispatched, after
  modules and middleware have rewritten it.

### Removed

- `docs/reference_guide.md`, superseded by the README; its links pointed at
  paths on another machine.

### Changed

- Committed CSS and JS are the minified production build again.
- Code style is PSR-12, with opening braces kept on the signature line.

## 1.3.9 and earlier

See the git history.
