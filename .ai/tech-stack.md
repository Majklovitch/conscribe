# Tech stack and environment

## Runtime

| Part | What |
|---|---|
| PHP | 8.4+ (Docker image `php:8.4-apache`; tests also run on 8.5) |
| Web server | Apache 2.4 with `mod_rewrite` ([public/.htaccess](../public/.htaccess)) |
| Database | MySQL / MariaDB through PDO (`pdo_mysql`); SQLite in tests |
| Required extensions | `mbstring`, `pdo`, `gd` (`imagick` and `exif` suggested) |

## Dependencies ([composer.json](../composer.json))

- `monolog/monolog` – logging (`App\Core\Logging\Log`)
- `tracy/tracy` – blue screen and debug bar in development, saved dumps in production
- `scssphp/scssphp`, `matthiasmullie/minify` – asset pipeline (`php tty build`)
- `psr/container` – container interface
- `phpmailer/phpmailer` – required but unused until the mailer lands (roadmap 5.2)
- Dev: PHPUnit, PHPStan, PHP-CS-Fixer, symfony/var-dumper (pinned to their current major)

Front end: vendored CookieConsent v3 (`public/js/cookieconsent.umd.js`) and Spotlight.js;
`assets/scss` and `assets/js` compile into `public/css/style.css` and `public/js/main.js`
(build artifacts – never edit them).

## Configuration

- [app/Config/main.example.php](../app/Config/main.example.php) is the documented template.
  Copy it to `app/Config/main.php` (development) and/or `main.prod.php` (production);
  both are git-ignored.
- `Config::path()` picks the file: `APP_ENV=production` → `main.prod.php`,
  `APP_ENV=development|local` → `main.php`; without `APP_ENV` a web request on a local host
  (localhost, an IP, `*.local`, `*.test`) and every console run use `main.php`. Each falls
  back to the other when missing.
- Areas: `conscribe` (environment, base_url, trusted proxies, time zone), `security` (CSP),
  `modules`, `media`, `assets`, `log`, `session`, `db`, `tracking`.

## Docker

- [docker-compose.yml](../docker-compose.yml) – the web container on
  `http://localhost:8080`, project bind-mounted into `/var/www/html`.
- [docker-compose_db.yml](../docker-compose_db.yml) – the same, joined to an external
  `database-network` with a MariaDB container; set the port placeholder `8XXX`.
- [Dockerfile](../Dockerfile) – `php:8.4-apache` plus extensions through a pinned
  `install-php-extensions` release.

## Commands

| Command | What |
|---|---|
| `composer check` | lint + PHPStan (level 5) + PHPUnit |
| `composer fix` / `fix:check` | apply / check code style |
| `php tty list` | all console commands (core + modules) |
| `php tty build [--watch]` | compile SCSS, minify JS |
| `php tty migrate`, `migrate:rollback`, `migrate:status`, `make:migration` | migrations |
| `php tty lint [dir…]` | `php -l` over the project |
