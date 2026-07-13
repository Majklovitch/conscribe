# ConscribePHP

ConscribePHP is a clean, modern, and lightweight PHP MVC (Model-View-Controller) boilerplate framework. It comes pre-configured with a custom router, basic view layout wrapping (header/footer templates), asset cache-busting, session security, multi-language support (routing & translations mapping), database config, built-in tracking code engine, CSRF security, and Docker setup.

---

## Key Features

- **Custom PHP MVC Core**: A clean, modular framework separation using namespaces, base classes, and autoloading logic.
- **Dynamic Front-Routing**: Automatically translates URL paths into Controller actions with support for passing dynamic parameters.
- **CSRF Protection Engine**: Built-in security helpers (`csrf_field()`, `validate_csrf()`, and `check_csrf()`) to defend public endpoints against Cross-Site Request Forgery.
- **Tracking Tags Integration**: Configurable, performance-optimized integration for Google Analytics (GA4/Gtag/AdWords), Seznam Sklik, and Facebook Pixel.
- **Secure Sessions**: Configured with strict session attributes (SameSite, HttpOnly, Secure cookie parameters).
- **HTTP Security Headers**: Enforces strict security headers (e.g., HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
- **Auto Cache-Busting (Assets)**: Features helper utilities that query file modification times (`filemtime`) to force browsers to reload modified CSS/JS.
- **Modular Multi-Language Engine**: Support for localized routing, translation key mapping, URL slug mutation mapping, and automatic browser-language redirection.
- **Inline SVG Wrapper**: Injects raw SVG icons inline while appending class names and ARIA properties dynamically.
- **Reusable View Components**: Supports rendering lightweight, isolated component fragments with their own data scopes inside pages.
- **Dockerized Environment**: Out-of-the-box support for Apache/PHP 8.2 with standard extensions (GD, Imagick, PDO MySQL, Zip, etc.).

---

## Table of Contents
1. [Project Structure](#project-structure)
2. [Getting Started & Installation](#getting-started--installation)
   - [Using Docker (Recommended)](#using-docker-recommended)
   - [Using Local Web Server (Apache/Nginx)](#using-local-web-server-apachenginx)
3. [Routing & Controllers](#routing--controllers)
4. [Basic Data Parsing to Views](#basic-data-parsing-to-views)
5. [Template Utilities (Global Helpers)](#template-utilities-global-helpers)
6. [CSRF Security Protection](#csrf-security-protection)
7. [Tracking Codes (GA4, Gtag, Ads, Sklik, Facebook Pixel)](#tracking-codes-ga4-gtag-ads-sklik-facebook-pixel)
8. [Language Mutations & Translations](#language-mutations--translations)

---

## Project Structure

```text
├── app/
│   ├── Controllers/             # Controller classes (WebController, AdminController, etc.)
│   │   ├── AdminController.php
│   │   └── WebController.php
│   ├── Core/                    # Core MVC framework files
│   │   ├── Controller.php       # Base controller class
│   │   ├── Router.php           # Front-controller Router
│   │   └── View.php             # View rendering engine
│   ├── Helpers/                 # General helper files
│   │   └── TemplateHelper.php   # Global helper functions for layouts & views (Asset, SVG, CSRF, Tracking)
│   ├── Models/                  # Data/Business logic models
│   │   ├── BaseModel.php        # Base DB model
│   │   ├── MenuItem.php
│   │   └── MenuRepository.php
│   ├── Modules/                 # Modular extension components
│   │   └── LanguageMutations/   # Multi-language translation & routing module
│   ├── Views/                   # Template views (.php layout fragments and pages)
│   │   ├── 404.php              # Fallback 404 error page
│   │   ├── home.php             # Home page view
│   │   ├── test.php             # Test page view
│   │   └── layout/              # Layout wraps
│   │       ├── header.php       # Main page layout header (HTML <head>, metadata, tracking scripts)
│   │       └── footer.php       # Main page layout footer
│   ├── config.example.php       # Configuration template (rename/copy to config.php)
│   └── config.php               # Active configuration (ignored in version control)
├── public/                      # Web-accessible root directory
│   ├── css/                     # Static CSS assets
│   ├── js/                      # Static JavaScript assets
│   ├── img/                     # Static images & icon assets
│   ├── index.php                # Front controller entry point
│   ├── .htaccess                # Apache rewrite rules for routing
│   └── sitemap.xml
├── composer.json                # Composer configurations
├── Dockerfile                   # Apache/PHP base Docker image configuration
├── docker-compose.yml           # Core Docker service config
└── docker-compose_db.yml        # Multi-service database Network Docker configuration
```

---

## Getting Started & Installation

### Using Docker (Recommended)
This project is configured with a `Dockerfile` and `docker-compose.yml` exposing Apache and PHP 8.2 with the necessary extensions.

1. **Spin up the container:**
   ```bash
   docker-compose up -d
   ```
2. **Access the application:**
   Open your browser and navigate to `http://localhost:8050`.

3. **Install dependencies (Composer):**
   ```bash
   docker exec -it mvc_zaklad composer install
   ```

### Using Local Web Server (Apache/Nginx)
If you run this project on a local stack (e.g., XAMPP, MAMP, or a native LAMP installation):
1. Ensure `mod_rewrite` is enabled in your Apache configuration (handled automatically by `public/.htaccess`).
2. Point your server's document root to the `public/` directory (not the project root).
3. Copy `app/config.example.php` to `app/config.php` and configure your database credentials and tracking IDs:
   ```bash
   cp app/main.example.php app/config.php
   ```
4. Run composer install:
   ```bash
   composer install
   ```

---

## Routing & Controllers

All incoming requests are directed through `public/index.php`. The `App\Core\Router` parses the request URL:
- A URL path like `/` resolves to `WebController::index()`.
- A URL path like `/test` resolves to `WebController::test()`.
- If the path starts with `admin`, the router resolves to actions within `AdminController`. Otherwise, it resolves to `WebController`.
- Any remaining URL paths (e.g., `/test/param1/param2`) are passed to the controller action as method arguments.

---

## Basic Data Parsing to Views

Data parsing between the Controller and the View is clean and straightforward. In `App\Core\View::render()`, PHP's built-in `extract()` function is used to turn associative array keys into local variables available within the layout and page templates.

### 1. Passing Data from Controller
To send custom variables to your views (such as dynamic titles, meta descriptions, or layout configurations), pass an associative array as the second argument to `$this->view()` inside your controller:

```php
// app/Controllers/WebController.php
namespace App\Controllers;

use App\Core\Controller;

class WebController extends Controller {
    
    public function index(): void {
        $this->view('home', [
            'pageTitle'       => 'Domovská stránka',
            'pageDescription' => 'Vítejte na domovské stránce našeho skvělého MVC projektu.',
            'customData'      => ['novinky', 'galerie', 'kontakty']
        ]);
    }
}
```

### 2. View Extracting Data
Under the hood, the rendering engine extracts the parameters:

```php
// app/Core/View.php
public static function render($viewPath, $data = [], $layout = 'main'): void {
    // Converts ['pageTitle' => 'Home'] into a local variable $pageTitle = 'Home'
    extract($data);
    
    // Renders the view page and buffers it
    ob_start();
    require $viewFile;
    $content = ob_get_clean();

    // Renders header/footer layouts which will inherit the extracted variables
    if ($layout == 'main') {
        require $header; // Has access to $pageTitle, $pageDescription, etc.
    }
    echo $content;
    if ($layout == 'main') {
        require $footer;
    }
}
```

### 3. Displaying and Parsing in Views/Layouts
In the layout header or sub-views, you can display these variables using PHP short echo tags `<?= ?>`. Always write fallbacks for variables that might not be provided by every controller.

```php
<!-- app/Views/layout/header.php -->
<head>
    <!-- Render the dynamic description passed from controller -->
    <meta name="description" content="<?= esc($pageDescription) ?>">
    
    <!-- Render the dynamic title passed from controller -->
    <title><?= esc($pageTitle) ?> | MVC_Projekt</title>
</head>
```

---

## Template Utilities (Global Helpers)

The framework registers layout helper functions in `app/Helpers/TemplateHelper.php`, automatically loaded during bootstrap. They are globally available throughout the views and controllers.

### 1. Escaping Output: `esc()`
Trim and sanitize output strings to prevent XSS (Cross-Site Scripting).
```php
<p>Uživatel: <?= esc($username) ?></p>
```

### 2. Format Dates: `dateFormat()`
Parses and converts a date string into a user-friendly format (defaults to Czech format `d. m. Y`).
```php
<span>Zveřejněno: <?= dateFormat($article['created_at']) ?></span>
<!-- Output: 22. 06. 2026 -->
```

### 3. Text Truncation: `truncate()`
Safely cuts down long strings using multibyte string functions without breaking character encodings, appending trailing ellipsis.
```php
<p><?= truncate($article['content'], 120, '...') ?></p>
```

### 4. Cache-Busted Assets: `asset()`
Appends the file modification timestamp (`?v=17382...`) dynamically to asset URLs to clear browser caches when files change.
```php
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<!-- Output: href="/css/style.css?v=1738294821" -->
```

### 5. Shared Components Rendering: `component()`
Loads isolated view sub-fragments located in `app/components/` and passes an array of variables directly into their local scopes.
```php
<?php component('newsletter', ['buttonText' => 'Odebírat novinky']) ?>
```

### 6. Inline SVG Loader: `svg()`
Retrieves raw file content of an SVG asset (stored in `public/img/icons/`) and embeds it inline. It automatically handles accessibility attributes (`aria-hidden="true"`) and appends custom CSS classes.
```php
<?= svg('check-circle', ['class' => 'icon-success', 'id' => 'first-check']) ?>
```

---

## CSRF Security Protection

The framework provides built-in functions to defend public forms against Cross-Site Request Forgery (CSRF) attacks.

### 1. Rendering the CSRF Input
Insert the hidden token input field into any form using `csrf_field()`:
```html
<form action="/kontakt" method="POST">
    <?= csrf_field() ?>
    
    <label for="message">Zpráva:</label>
    <textarea id="message" name="message" required></textarea>
    
    <button type="submit">Odeslat</button>
</form>
```

### 2. Validating the Submission in Controllers
Invoke `check_csrf()` at the start of your form submission controller method. This function will automatically abort the request and issue a `403 Forbidden` response if the token is invalid or missing:
```php
public function odeslatKontakt(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf(); // Block request if CSRF token is invalid
        
        // Process sanitization and model actions safely...
    }
}
```

### 3. AJAX Requests
For AJAX / Fetch actions, output the token in a meta tag inside [app/Views/layout/header.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_boilerplate/app/Views/layout/header.php):
```html
<meta name="csrf-token" content="<?= csrf_token() ?>">
```
Then pass the token using the `X-CSRF-TOKEN` header:
```javascript
const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

fetch('/api/odeslat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token
    },
    body: JSON.stringify(data)
});
```

---

## Tracking Codes (GA4, Gtag, Ads, Sklik, Facebook Pixel)

The boilerplate includes a central rendering engine for common analytics and tracking platforms, managed entirely via configurations and rendered dynamically in your page header.

### 1. Configuration (`app/config.php` / `app/config.example.php`)
Simply fill in the relevant service IDs. If a service is set to `null` or is empty, it will not be rendered.

```php
'tracking' => [
    'ga4_id'      => 'G-XXXXXXXXXX', // Google Analytics 4 ID
    'gtag_id'     => 'GTM-XXXXXXX',  // Google Tag / Tag Manager ID
    'adwords_id'  => 'AW-XXXXXXXXX', // Google Ads Conversion ID
    'sklik_id'    => '123456',       // Seznam Sklik Retargeting ID
    'fb_pixel_id' => '1234567890',   // Facebook Pixel ID
],
```

### 2. Automatic Header Rendering
The tracking snippets are automatically checked and output in the head section of your layout. The helper combines Google tags (`gtag.js` calls) to run under a single script request for maximum performance, while Sklik and Facebook Pixel load their respective boilerplate templates inline.

It is loaded dynamically in [app/Views/layout/header.php](file:///home/mbrotys/Web/projects/03_osobní/mvc_boilerplate/app/Views/layout/header.php):
```html
<head>
    ...
    <title><?= esc($pageTitle) ?> | Web</title>
    
    <!-- Renders active tracking codes configured in config.php -->
    <?= renderTrackingCodes() ?>
</head>
```

---

## Language Mutations & Translations

The project has multi-language capabilities located in `app/Modules/LanguageMutations`. 

- **Translations:** Under `Languages/{cs,en,pl}/`, you define translation dictionaries as PHP associative arrays.
- **Language Switcher:** In code, the `url()` and `urlLang()` helper functions generate localized routing paths based on slug maps in `Language.php`.
- **Displaying Strings:** Use the translation helper function `__('key.subkey')` directly in your views:
  ```html
  <p><?= __('home.welcome_text') ?></p>
  ```
