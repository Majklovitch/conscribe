# Workflows and conventions

## Conventions

- PSR-4: `App\` → `app/`, `Modules\` → `app/Modules/`, `Tests\` → `tests/`.
- Classes PascalCase, methods and variables camelCase, global helpers snake_case
  (`csrf_field()`; `renderTrackingCodes()` and `dateFormat()` are older exceptions).
- House style: PSR-12 with opening braces on the same line – `composer fix` applies it
  ([.php-cs-fixer.dist.php](../.php-cs-fixer.dist.php)).
- Comments explain *why*. Tests use `#[TestDox]` names that read as sentences.
- Views are `.php` files under `app/Views/` (or a module's `Views/`), lower-case names;
  components live in `Views/components/`.

## Security rules

- **Escape output:** `<?= esc($value) ?>` for every value in a view. JSON for inline scripts
  goes through `json_encode` with the `JSON_HEX_*` flags.
- **CSRF:** every form that changes state contains `<?= csrf_field() ?>` and the action calls
  `check_csrf($request)` (419 on an expired token). AJAX sends the token in `X-CSRF-TOKEN`.
- **Redirects:** `Response::redirect()` only allows relative or own-host URLs; use
  `redirectExternal()` for a trusted `https://` target.
- **SQL:** go through `Repository` or bound parameters; never concatenate input into SQL.
- **Tracking / third-party scripts:** nothing may load before cookie consent – see
  `renderTrackingCodes()` and the *Tracking* section of [assets/js/main.js](../assets/js/main.js).
- **Secrets:** mark password parameters `#[\SensitiveParameter]`; never log credentials.

## Add a page

1. Route in [app/Config/routes/public.php](../app/Config/routes/public.php) (lands in the
   sitemap) or `routes/admin.php` (does not):
   ```php
   $router->get('clanek/{slug}', [ArticleController::class, 'show']);
   ```
2. Controller in `app/Controllers/`, extending `App\Core\Http\Controller`. Dependencies come
   through the constructor (autowired); route params by name:
   ```php
   final class ArticleController extends Controller {
       public function __construct(private ArticleRepository $articles) {}

       public function show(string $slug): Response {
           return $this->render('article/show', [
               'article' => $this->articles->first(['slug' => $slug]),
           ]);
       }
   }
   ```
3. View `app/Views/article/show.php` – variables from the array are in scope; the header and
   footer layout wrap it automatically. Shared fragments: `<?php component('card', [...]) ?>`.

## Add a repository

```php
final class ArticleRepository extends Repository {
    protected string $table = 'articles';
    protected string $model = Article::class;   // extends App\Core\Database\Model; omit for arrays
}
```

## Add a migration

`php tty make:migration create_articles_table` creates a timestamped file in
`database/migrations/` with `up(PDO $db)` / `down(PDO $db)`. Run with `php tty migrate`,
undo with `migrate:rollback`, inspect with `migrate:status`.

## Add a module

1. `app/Modules/Blog/Module.php` with `final class Module extends \App\Core\Module\Module`.
   Override only what the module has: `registerRoutes()`, `commands()`, `boot()`.
   `migrations/`, `Views/` and `helpers.php` next to `Module.php` are picked up automatically.
2. Add `'Blog'` to the `modules` list in `app/Config/main.php`.
3. Views render as `blog::posts/show`; components as `component('blog::teaser')`.

## Add a console command

Extend `App\Core\Console\Command` (set `$name`, `$description`, implement
`run(Input $input, Output $output): int`) and register it in `Bootstrap::kernel()` (core) or
the module's `commands()`.

## Finish a task

1. `composer check` (lint + PHPStan + PHPUnit) and `composer fix:check` pass.
2. CHANGELOG entry under `## Unreleased` (Keep a Changelog sections).
3. Tick the task in [docs/ROADMAP.md](../docs/ROADMAP.md) and [docs/tasks.html](../docs/tasks.html).
4. Update README / NAVOD when behaviour or configuration changed.
5. Commit to `dev`.
