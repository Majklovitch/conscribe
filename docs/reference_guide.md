# Project Reference Manual

This document serves as an information-oriented reference guide for the custom PHP MVC framework base. It is designed to help novice developers understand the structure, core architecture, and execution lifecycle of the project.

---

## 1. Directory Structure

The project follows a standard Model-View-Controller (MVC) directory structure:

```text
├── app/                              # Application source code
│   ├── Controllers/                  # Request controllers (Web & Admin)
│   ├── Core/                         # Core framework classes (Router, Controller, View)
│   ├── Helpers/                      # Helper utilities
│   ├── Models/                       # Database models
│   ├── Modules/                      # Optional plugin modules
│   │   ├── ContactSender/            # Module for handling/mailing contact forms
│   │   ├── LanguageMutations/        # Module for localization and translations
│   │   └── SitemapAutocreation/      # Module for automatically generating sitemaps
│   ├── Services/                     # Application services
│   ├── Views/                        # HTML templates (Web, Admin, Layouts)
│   └── config.example.php            # Example database configuration file
├── docs/                             # Documentation files
├── public/                           # Document root (publicly accessible web root)
│   ├── css/                          # CSS stylesheets
│   ├── js/                           # JavaScript frontend scripts
│   ├── index.php                     # Single entry point (bootstrap)
│   └── .htaccess                     # Apache web server rewrite rules
├── Dockerfile                        # Docker container specification
├── docker-compose.yml                # Docker Compose orchestration
└── composer.json                     # Composer dependencies and autoloading definitions
```

---

## 2. Bootstrapping & Entry Point

### File: [public/index.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/public/index.php)

All client requests are directed to [public/index.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/public/index.php) via rewrite rules in [public/.htaccess](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/public/.htaccess).

#### Key Responsibilities:
1. **HTTPS Detection**: Detects if the request is running over SSL/TLS across various proxy/CDN setups (Cloudflare, load balancers, HTTP headers).
2. **Session Security Configuration**: Restricts session cookies to improve security:
   * `session.use_only_cookies = 1` (Prevents passing session IDs via URLs).
   * `session.use_strict_mode = 1` (Prevents initialization of uninitialized session IDs).
   * `session.cookie_httponly = 1` (Prevents JavaScript `document.cookie` access).
   * `session.cookie_samesite = Lax` (Mitigates Cross-Site Request Forgery).
   * `session.cookie_secure = true/false` (Enforces HTTPS-only cookies based on detection).
3. **Security Headers**:
   * `Strict-Transport-Security` (Enforces HTTPS for future visits).
   * `X-Frame-Options: SAMEORIGIN` (Mitigates Clickjacking).
   * `X-Content-Type-Options: nosniff` (Prevents MIME-sniffing).
   * `Referrer-Policy: strict-origin-when-cross-origin` (Controls referrer info sent with requests).
   * `Permissions-Policy` (Disables browser hardware permissions like camera/geolocation by default).
4. **Autoloading**: Loads standard Composer dependencies via `require __DIR__ . '/../vendor/autoload.php'`.
5. **Core Application Dispatch**: Creates an instance of [App\Core\Router](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Router.php) and executes [Router::run()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Router.php#L6).

---

## 3. Core Engine Classes

The framework's engine resides in the `app/Core/` directory.

### A. Router Class
* **File:** [app/Core/Router.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Router.php)
* **Namespace:** `App\Core`

The [Router](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Router.php) class extracts URL segments and matches them to a specific Controller class and action method.

#### Core Methods:
* **[Router::run()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Router.php#L6) : void**
  * Parses `$_SERVER['REQUEST_URI']`.
  * Blocks direct URL calls ending in asset extensions (`.css`, `.js`, `.png`, etc.) by returning a 404 response.
  * Splits paths into segments (e.g. `/admin/dashboard` becomes `['admin', 'dashboard']`).
  * Maps requests containing `/admin` as the first segment to [App\Controllers\AdminController](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Controllers/AdminController.php).
  * Maps all other requests to [App\Controllers\WebController](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Controllers/WebController.php).
  * Executes the controller method matching the page name, passing remaining URL segments as parameters.
  * Defines the global constant `CURRENT_PAGE` to keep track of the current route.
* **[Router::error()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Router.php#L51) : void**
  * Sets the HTTP response code.
  * Instantiates the 404 View with dynamic error variables and stops execution.

---

### B. Controller Class
* **File:** [app/Core/Controller.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Controller.php)
* **Namespace:** `App\Core`

An abstract class that serves as the base for all Controllers in the application.

#### Core Methods:
* **[Controller::view()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Controller.php#L8) : null**
  * Invokes the [View::render()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/View.php#L5) engine. Accepts the path of the view template (relative to `app/Views/`), an associative array of parameters, and the layout wrapper name (`main` by default).
* **[Controller::redirect()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Controller.php#L13) : void**
  * Sends an HTTP header redirection and terminates execution (`exit;`). Decorated with the `#[NoReturn]` attribute to notify static analysis engines of program termination.

---

### C. View Class
* **File:** [app/Core/View.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/View.php)
* **Namespace:** `App\Core`

The static [View](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/View.php) class handles variable extraction and rendering of PHP files inside layouts.

#### Core Methods:
* **[View::render()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/View.php#L5) : void**
  * **Variable Extraction**: Runs `extract($data)` to convert associative array keys into local variables available within the view scope.
  * **Output Buffering**: Utilizes `ob_start()` and `ob_get_clean()` to capture rendered view content into a `$content` variable.
  * **Layout Composition**: If `$layout === 'main'`, it includes [Views/layout/header.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Views/layout/header.php) before printing `$content`, and [Views/layout/footer.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Views/layout/footer.php) after.

---

## 4. Application Controllers & Layout Views

### A. WebController
* **File:** [app/Controllers/WebController.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Controllers/WebController.php)
* **Actions**:
  * `index()`: Renders the [app/Views/home.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Views/home.php) view.
  * `test()`: Renders the [app/Views/test.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Views/test.php) view.

### B. AdminController
* **File:** [app/Controllers/AdminController.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Controllers/AdminController.php)
* **Actions**:
  * `admin()`: Renders [app/Views/admin/dashboard.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Views/admin/dashboard.php) with an `'admin'` layout setting.

### C. Layout Views
* **Header Template ([app/Views/layout/header.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Views/layout/header.php))**: Sets HTML headers, links assets like `/css/style.css` and `/js/main.js`, and sets the dynamic HTML page title using a match block on the `CURRENT_PAGE` constant.
* **Footer Template ([app/Views/layout/footer.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Views/layout/footer.php))**: Closes layout tags and adds standard footer metadata.

---

## 5. Optional Module: [LanguageMutations](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php)

The `LanguageMutations` module provides translation capabilities, URL slug translation (e.g. Czech to English/Polish), and browser language detection.

### A. Core Class: Language
* **File:** [app/Modules/LanguageMutations/Core/Language.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php)
* **Namespace:** `Modules\LanguageMutations\Core`

#### Core Static API:
* **[Language::setLang()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L24) : void**
  * Sets the active translation language and saves it to a 30-day cookie (`lang_preference`).
* **[Language::getBrowserLang()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L32) : void**
  * Redirects clean URL entries (`/`) to the preferred language based on browser `HTTP_ACCEPT_LANGUAGE` (supports `en` and `pl`). Ignores search engine crawlers and bots.
* **[Language::getInternalPath()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L53) : string**
  * Resolves localization slugs into core routing paths (e.g., translates English `/loose` to internal page name `/sypke`).
* **[Language::getSwitchUrl()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L65) : string**
  * Generates the switch link for language flags. Pointers mapping internally to other language slugs (e.g. English translation of `#onas` to `#about-us`).
* **[Language::load()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L79) : bool**
  * Includes translation array files from `app/Modules/LanguageMutations/Languages/{lang}/*.php`. Automatically merges and falls back to Czech (`cs`) translations if target translation strings are missing.
* **[Language::get()](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L101) : string**
  * Returns the translation value for a key or the key enclosed in brackets `[key]` if it does not exist.

---

### B. Global Helper Functions

These helpers are defined in the global namespace inside [Language.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L115) to simplify translation use in views:

1. **`__($key)`**: Fetches translation using [Language::get($key)](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L101).
2. **`url($path)`**: Standard helper to generate correct internal URLs pre-pended with the active language subdirectory code (e.g., outputs `/en/about-us` instead of `/sypke`). Translates fragment anchors (e.g., `#kontakt` to `#contact-us`).
3. **`urlLang($lang)`**: Generates path switch links for flags using [Language::getSwitchUrl($lang)](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php#L65).

---

### C. Activating the [LanguageMutations](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Modules/LanguageMutations/Core/Language.php) Module

To activate full localized routing and multi-language translation, uncomment the specific sections in these two files:

#### 1. In [public/index.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/public/index.php)
Uncomment the section containing the namespace import, cookie and session bindings, and translation dictionary bootstrapping:
```php
// Lines 54-81
use Modules\LanguageMutations\Core\Language;

Language::getBrowserLang();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$parts = $uri === '/' ? [] : array_values(array_filter(explode('/', trim($uri, '/')), 'strlen'));

$lang = 'cs';
$supportedLanguages = ['cs', 'en', 'pl'];

if (!empty($parts) && in_array($parts[0], $supportedLanguages, true)) {
	$lang = array_shift($parts);
}

$page = $parts[0] ?? 'home';

if (!defined('LANG')) {
	define('LANG', $lang);
}

if (!defined('CURRENT_PAGE')) {
	define('CURRENT_PAGE', $page);
}

Language::setLang($lang);
Language::load($page);
```

#### 2. In [app/Core/Router.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/app/Core/Router.php)
Uncomment the localized URI segment shifting blocks so the routing system strips language codes and parses localized paths:
```php
// Lines 15-22
$lang = 'cs';
$supportedLanguages = ['cs', 'en', 'pl'];

if (!empty($parts) && in_array($parts[0], $supportedLanguages, true)) {
    $lang = array_shift($parts);
}
```
And uncomment the `LANG` define if it hasn't been instantiated already in bootstrapping:
```php
// Lines 27-29
if (!defined('LANG')) {
    define('LANG', $lang);
}
```

---

## 6. Development & Deployment Environment

The project ships configured with container tools to run a local Apache development environment seamlessly.

### A. Docker Configuration
* **[Dockerfile](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/Dockerfile)**:
  * **Base Image**: Built on official PHP 8.2 with Apache (`php:8.2-apache`).
  * **Extensions**: Integrates the standard extension installer script to add core requirements like `pdo_mysql` (for database access), `imagick` & `gd` (image processing), `intl` (internationalization support), `zip`, `bcmath`, `exif`, `opcache`, and `mbstring`.
  * **Apache Rewrite Module**: Commands Apache to enable `mod_rewrite` (`a2enmod rewrite`), allowing `.htaccess` directory rules to map URLs to `/index.php` without visible file suffixes.
* **[docker-compose.yml](file:///home/mbrotys/Web/projects/03_osobní/mvc_zaklad/docker-compose.yml)**:
  * Deploys a service container named `mvc_zaklad`.
  * Maps external port **8050** to Apache's standard container port **80** (accessible at `http://localhost:8050`).
  * Mounts the current repository folder directly inside `/var/www/html` to reflect code changes live without container rebuilds.
