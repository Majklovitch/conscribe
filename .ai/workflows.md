# ConscribePHP Workflows & Coding Standards

This document establishes the coding standards, security requirements, and step-by-step procedures for extending the ConscribePHP framework.

---

## 📏 Coding Standards & Conventions

ConscribePHP adheres strictly to standardized PSR conventions:

### Naming Conventions Summary
- **Namespaces**: PascalCase (e.g. `App\Controllers`, `App\Core`). Defined at the top of PHP files.
- **Classes**: PascalCase (e.g. `WebController`, `BaseModel`). Filename must exactly match class name.
- **Methods & Object variables**: camelCase (e.g. `isLinkActive()`, `$pageTitle`).
- **Global Helper Functions**: snake_case (e.g. `esc()`, `csrf_token()`, `renderTrackingCodes()`). Registered inside [TemplateHelper.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Helpers/TemplateHelper.php).
- **Session Keys**: snake_case (e.g. `csrf_token`, `form_load_time`).
- **Views**: lowercase/snake_case (e.g. `home.php`, `contact_form.php`).
- **Components**: lowercase/snake_case (e.g. `newsletter.php`). Must live under `app/components/`.

---

## 🔒 Security Guidelines (Defense-in-Depth)

All AI agents and developers must maintain the codebase's strong security profile by enforcing these patterns:

### 1. Cross-Site Scripting (XSS) Mitigation
- Always wrap variable values outputted to views in the escaping helper function `esc()`:
  ```php
  <p>Vítejte, <?= esc($username) ?></p>
  ```
- **SVGs**: Never output raw user-supplied SVGs directly. Use the custom `svg()` helper which sanitizes elements by removing dangerous tags like `<script>` and attributes like `onload` or `onerror`.

### 2. Cross-Site Request Forgery (CSRF) Mitigation
- All `POST`, `PUT`, `DELETE` forms **must** contain a CSRF token:
  ```html
  <form action="/submit" method="POST">
      <?= csrf_field() ?>
      ...
  </form>
  ```
- Verify the token in the corresponding controller action before processing any form inputs:
  ```php
  public function submitAction(Request $request): Response {
      check_csrf(); // Immediately aborts and returns 403 Forbidden if invalid
      ...
  }
  ```
- For AJAX requests, read the CSRF token from the HTML head meta tag:
  ```html
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  ```
  Pass this token within the `X-CSRF-TOKEN` header of the fetch/ajax call.

### 3. Form Honeypots & Time Traps
For public contact and submit forms, prevent automated bot spam:
- **Honeypot**: Include a hidden input field styled invisible to humans (e.g. named `communication_type`). Reject submissions if this field is filled out.
- **Time Trap**: Ensure form completion took at least 3 seconds. Check `$current_time - $_SESSION['form_load_time'] >= 3` during verification.

---

## 🛠️ Step-by-Step Developer Workflows

Follow these blueprints to extend the application's features:

### Workflow 1: Registering a Route & Controller Action

1. **Create/Open Controller**: Create a controller class inside [app/Controllers/](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Controllers/) ending in `Controller.php`. Ensure it extends `App\Core\Controller`.
   ```php
   namespace App\Controllers;

   use App\Core\Controller;
   use App\Core\Request;
   use App\Core\Response;

   class ArticleController extends Controller {
       public function show(Request $request, string $slug): Response {
           return $this->render('article/detail', [
               'pageTitle' => 'Článek: ' . esc($slug),
               'slug'      => $slug,
           ]);
       }
   }
   ```
2. **Register Route**: Open [app/Config/routes.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Config/routes.php) and add the path, referencing your controller class and action name:
   ```php
   use App\Controllers\ArticleController;

   $router->get('clanek/{slug}', [ArticleController::class, 'show']);
   ```

---

### Workflow 2: Creating Page Views & Layout Components

1. **Create the Page View**: Place your view under `app/Views/` (e.g. `app/Views/article/detail.php`).
   - The parameters passed to `$this->render()` will be directly available as local variables.
2. **Render Components**: Load shared components inside views using the `component()` helper, passing dynamic scope arrays:
   ```php
   <!-- Inside app/Views/article/detail.php -->
   <h1><?= esc($pageTitle) ?></h1>
   <p>Zde se nachází článek o <?= esc($slug) ?></p>

   <?php component('newsletter', ['buttonText' => 'Přihlásit k odběru']) ?>
   ```
3. **Write Components**: Reusable components reside inside `app/components/` (e.g. `app/components/newsletter.php`):
   ```php
   <div class="newsletter-wrap">
       <button type="submit"><?= esc($buttonText ?? 'Odeslat') ?></button>
   </div>
   ```

---

### Workflow 3: Creating Databases Models & Repositories

1. **Create Database Model**: Add a PHP model class under [app/Models/](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Models/) that extends `App\Models\BaseModel`. Set the protected `$table` property:
   ```php
   namespace App\Models;

   class Article extends BaseModel {
       protected $table = 'articles';

       public function fetchLatest(): array {
           // self::$db is the lazy-loaded PDO instance
           $stmt = self::$db->query("SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT 5");
           return $stmt->fetchAll();
       }
   }
   ```
2. **Implement Repository Layer**: If business logic or caching is required, encapsulate model operations in a Repository:
   ```php
   namespace App\Models\Repositories;

   use App\Models\Article;

   class ArticleRepository {
       private Article $model;

       public function __construct() {
           $this->model = new Article();
       }

       public function getLatestArticles(): array {
           return $this->model->fetchLatest();
       }
   }
   ```

---

### Workflow 4: Localization & Translation Setup

1. **Register Slugs**: Open [Language.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Modules/LanguageMutations/Core/Language.php) and add route mappings under `$slugMap` to translate page paths across languages (e.g., `clanky` &rarr; `articles`).
2. **Update Localized Dictionaries**: Add language string keys to matching keys in language folders:
   - `app/Modules/LanguageMutations/Languages/cs/home.php`
   - `app/Modules/LanguageMutations/Languages/en/home.php`
3. **Use Helpers**:
   - Translate string key: `<?= __('home.welcome_text') ?>`
   - Localized URL anchor: `<a href="<?= url('clanky') ?>">...</a>`
   - Switch active language: `<a href="<?= urlLang('en') ?>">English</a>`

---

### Workflow 5: Managing Assets & Caching

1. **Store Assets**: Save CSS under `public/css/`, Javascript under `public/js/`, and SVG icons under `public/img/icons/`.
2. **Include with Cache-Busting**: Reference files in view layouts using the `asset()` helper function:
   ```html
   <link rel="stylesheet" href="<?= asset('css/styles.css') ?>">
   ```
   *This automatically queries the modified timestamp (`filemtime`) of the file and appends it as `?v=timestamp` to bust client caches.*
3. **Inline Icon SVGs**: Inject inline SVGs dynamically:
   ```php
   <?= svg('check-icon', ['class' => 'icon-success']) ?>
   ```
