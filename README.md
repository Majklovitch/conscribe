# PHP MVC Project Base (mvc_zaklad)

A clean, modern, and lightweight PHP MVC (Model-View-Controller) boilerplate framework. It comes pre-configured with a custom router, basic view layout wrapping (header/footer templates), asset cache-busting, session security, multi-language support (routing & translations mapping), database config, and Docker setup.

---

## Key Features

- **Custom PHP MVC Core**: A clean, modular framework separation using namespaces, base classes, and autoloading logic.
- **Dynamic Front-Routing**: Automatically translates URL paths into Controller actions with support for passing dynamic parameters.
- **Secure Sessions**: Configured with strict session attributes (SameSite, HttpOnly, Secure cookie parameters).
- **HTTP Security Headers**: Enforces strict security headers (e.g., Strict-Transport-Security, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
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
4. [Basic Data Parsing to Views (`pageTitle`, `pageDescription`, etc.)](#basic-data-parsing-to-views-pagetitle-pagedescription-etc)
   - [1. Passing Data from Controller](#1-passing-data-from-controller)
   - [2. View Extracting Data](#2-view-extracting-data)
   - [3. Displaying and Parsing in Views/Layouts](#3-displaying-and-parsing-in-viewslayouts)
5. [Template Utilities (`TemplateHelper`)](#template-utilities-templatehelper)
6. [Language Mutations & Translations](#language-mutations--translations)
7. [Asset Caching](#asset-caching)

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
│   │   └── TemplateHelper.php   # Utility methods for layout & views
│   ├── Models/                  # Data/Business logic models
│   ├── Modules/                 # Modular extension components
│   │   └── LanguageMutations/   # Multi-language translation & routing module
│   ├── Views/                   # Template views (.php layout fragments and pages)
│   │   ├── 404.php              # Fallback 404 error page
│   │   ├── home.php             # Home page view
│   │   ├── test.php             # Test page view
│   │   └── layout/              # Layout wraps
│   │       ├── header.php       # Main page layout header (contains HTML <head>, metadata)
│   │       └── footer.php       # Main page layout footer
│   └── config.example.php       # Configuration template (rename to config.php)
├── public/                      # Web-accessible root directory
│   ├── css/                     # Static CSS assets
│   ├── js/                      # Static JavaScript assets
│   ├── img/                     # Static images
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
3. Copy `app/config.example.php` to `app/config.php` and fill in your database credentials:
   ```bash
   cp app/config.example.php app/config.php
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
- If the path starts with `admin`, the router uses `AdminController`. Otherwise, it uses `WebController`.
- Any remaining URL paths (e.g., `/test/param1/param2`) are passed to the controller action as method arguments.

---

## Basic Data Parsing to Views (`pageTitle`, `pageDescription`, etc.)

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

    public function test(): void {
        $this->view('test', [
            'pageTitle'       => 'Testovací stránka',
            'pageDescription' => 'Tato stránka slouží k otestování funkčnosti našeho MVC frameworku.'
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

#### Layout Header (Metadata & Page Title)
```php
<!-- app/Views/layout/header.php -->
<?php
    // Fallback/Default values for page title and description
    $pageTitle = $pageTitle ?? match (CURRENT_PAGE) {
        'test' => 'Testovací stránka',
        'home', '' => 'Domovská stránka',
        default => 'Stránka',
    };
    if(http_response_code() === 404) {
        $pageTitle = $pageTitle ?? 'Stránka nenalezena';
    }
    $pageDescription = $pageDescription ?? 'Základní webový MVC projekt v PHP.';
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Render the dynamic description passed from controller -->
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    
    <!-- Render the dynamic title passed from controller -->
    <title><?= htmlspecialchars($pageTitle) ?> | MVC_Projekt</title>
    
    <!-- ... links & scripts ... -->
</head>
```

#### Page Sub-View Templates
Extracted variables are also directly accessible in the specific page templates. For example:
```php
<!-- app/Views/home.php -->
<h1><?= htmlspecialchars($pageTitle) ?></h1>
<p><?= htmlspecialchars($pageDescription) ?></p>

<h3>Test galerie</h3>
<a class="spotlight" href="/img/thumb1.jpg" data-title="Název obrázku" data-description="<?= htmlspecialchars($pageDescription) ?>">
    <img src="/img/thumb1.jpg" alt="Náhled">
</a>
```

---

## Template Utilities (`TemplateHelper`)

The framework registers a layout helper class, `App\Helpers\TemplateHelper`, automatically loaded during view rendering. It offers several helper methods to render parts cleanly and format dynamic outputs safely.

### 1. Escaping output: `TemplateHelper::esc()`
Utility to trim and sanitize output fields (e.g., from databases or form fields) to prevent XSS.
```php
// Inside a view file:
<p>User: <?= \App\Helpers\TemplateHelper::esc($username) ?></p>
```

### 2. Format Dates: `TemplateHelper::date()`
Parses and converts a date string into a user-friendly format (defaults to Czech format `d. m. Y`).
```php
// Inside a view file:
<span>Zveřejněno: <?= \App\Helpers\TemplateHelper::date($article['created_at']) ?></span>
<!-- Output format: 22. 06. 2026 -->
```

### 3. Text Truncation: `TemplateHelper::truncate()`
Safely cuts down long strings using multibyte string functions (`mb_substr`) without breaking characters, appending trailing dots.
```php
// Inside a view file:
<p><?= \App\Helpers\TemplateHelper::truncate($article['content'], 120, '...') ?></p>
```

### 4. Cache-Busted Assets: `TemplateHelper::asset()`
Builds query parameters with the file modification timestamp (`?v=17382...`) dynamically for caching.
```php
<!-- Inside header.php: -->
<link rel="stylesheet" href="<?= \App\Helpers\TemplateHelper::asset('css/style.css') ?>">
<!-- Outputs: href="/css/style.css?v=1738294821" -->
```

### 5. Shared Components Rendering: `TemplateHelper::component()`
Loads isolated view sub-fragments located in `app/components/` and passes an associated array of data directly into the component scope.
```php
// Inside app/Views/home.php:
<?php \App\Helpers\TemplateHelper::component('newsletter', ['buttonText' => 'Odebírat novinky']) ?>

// Inside app/components/newsletter.php:
<div class="newsletter-block">
    <input type="email" placeholder="Email...">
    <button><?= htmlspecialchars($buttonText) ?></button>
</div>
```

### 6. Inline SVG Loader: `TemplateHelper::svg()`
Retrieves raw file content of an SVG asset (stored in `public/img/icons/`) and embeds it inline within the HTML response. It automatically handles accessibility tags (`aria-hidden`) and custom CSS classes.
```php
// Inside a view file:
<?= \App\Helpers\TemplateHelper::svg('check-circle', ['class' => 'icon-success', 'id' => 'first-check']) ?>
<!-- Outputs full inline <svg class="icon-success" id="first-check" aria-hidden="true"> ... </svg> -->
```

---

## Language Mutations & Translations

The project has multi-language capabilities located in `app/Modules/LanguageMutations`. 

- **Translations:** Under `Languages/{cs,en,pl}/`, you define files returning associative arrays of key-value translation strings.
- **Language Switcher:** In code, the `url()` and `urlLang()` helper functions generate localized routing paths based on slug maps in `Language.php`.
- **Displaying Strings:** Use the translation helper function `__('key.subkey')` directly in your views:
  ```html
  <p><?= __('home.welcome_text') ?></p>
  ```

---

## Asset Caching
To prevent browser caching issues during style/script updates, layout headers or static calls query file timestamps:
```html
<link type="text/css" rel="stylesheet" href="<?= \App\Helpers\TemplateHelper::asset('css/style.css') ?>">
```
This forces browsers to download the fresh script or style sheet whenever changes are deployed.
