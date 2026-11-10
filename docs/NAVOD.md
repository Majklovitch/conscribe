# Conscribe PHP – praktický návod „jak přidat / jak udělat“

Kuchařka k verzi **2.1.1**. Každá kapitola je samostatný recept: *kam co dát, jak to vypadá, na co si dát pozor*.
Podrobný popis API je v [README.md](../README.md), tady jde o postupy.

## Obsah

1. [Jak aplikace funguje (1 minuta)](#1-jak-aplikace-funguje)
2. [Rozjetí projektu](#2-rozjetí-projektu)
3. [Nová stránka (route → controller → view)](#3-nová-stránka)
4. [Stránka s parametrem v URL](#4-stránka-s-parametrem-v-url)
5. [Položka v menu](#5-položka-v-menu)
6. [Formulář (CSRF, validace, flash, old input)](#6-formulář)
7. [Databázová tabulka (migrace → model → repository)](#7-databázová-tabulka)
8. [CRUD nad tabulkou](#8-crud-nad-tabulkou)
9. [JSON API endpoint](#9-json-api-endpoint)
10. [Služba (service) a dependency injection](#10-služba-a-dependency-injection)
11. [Middleware](#11-middleware)
12. [Přihlašování (modul Auth)](#12-přihlašování-modul-auth)
13. [Konzolový příkaz](#13-konzolový-příkaz)
14. [Vlastní modul](#14-vlastní-modul)
15. [Komponenta a vlastní helper do šablon](#15-komponenta-a-vlastní-helper)
16. [Styly a JavaScript](#16-styly-a-javascript)
17. [Obrázky a SVG ikony](#17-obrázky-a-svg-ikony)
18. [Konfigurace – nový klíč](#18-konfigurace)
19. [Chybové stránky](#19-chybové-stránky)
20. [Logování a debug](#20-logování-a-debug)
21. [Sitemap](#21-sitemap)
22. [Testy a kontrola kvality](#22-testy-a-kontrola-kvality)
23. [Nasazení](#23-nasazení)
24. [Kde co leží – tahák](#24-tahák)

---

## 1. Jak aplikace funguje

```
public/index.php
  └─ App\Bootstrap::web()
       ├─ error handling (Tracy v dev, ErrorPage v produkci)
       ├─ v dev automatický build SCSS/JS
       ├─ bezpečnostní hlavičky (+ CSP, pokud je nastavená)
       ├─ Request, Session, Container, Router
       ├─ moduly: boot() → registerRoutes()
       └─ app/Config/routes.php
  └─ ->run(): middleware → CSRF → controller akce → Response
```

- **Route** (`app/Config/routes/*.php`) mapuje URL na `[Controller::class, 'metoda']`.
- **Controller** (`app/Controllers/`) vrací `Response` – `render()`, `json()` nebo `redirect()`.
- **View** (`app/Views/*.php`) je čisté PHP, automaticky obalené `layout/header.php` + `layout/footer.php`.
- **Repository + Model** (`app/Models/`) je tenká vrstva nad PDO, SQL píšete ručně.
- **Container** vytváří controllery, commandy i middleware a sám do nich doplní závislosti z konstruktoru.
- **Konzole**: `php tty` (build, migrace, lint, vlastní příkazy).

---

## 2. Rozjetí projektu

```bash
cp app/Config/main.example.php app/Config/main.php
```

```bash
composer install
```

Pak v `app/Config/main.php` nastavte `db.*`, `conscribe.base_url` a `conscribe.environment` (`development` lokálně).

Docker varianta:

```bash
docker compose up -d
```

```bash
docker exec -it conscribe-php composer install
```

Web běží na `http://localhost:8050`. Tabulky vytvoříte přes:

```bash
php tty migrate
```

> ⚠️ `main.php` je v `.gitignore`. Nový konfigurační klíč přidávejte **i do `main.example.php`**, jinak ho ostatní nebudou mít.

Produkční konfigurace může ležet vedle jako `app/Config/main.prod.php` (také v `.gitignore`). Vybírá ji `APP_ENV=production`, `APP_ENV=development`/`local` vybírá `main.php`. Bez `APP_ENV` web použije `main.prod.php`, pokud host není lokální (`localhost`, IP, `*.local`, `*.test`); konzole vždy `main.php`, takže produkční cron musí nastavit `APP_ENV=production`. Když zvolený soubor chybí, použije se druhý.

---

## 3. Nová stránka

Příklad: stránka **/kontakt**.

### a) Route – `app/Config/routes/public.php`

```php
use App\Controllers\WebController;

$router->get('kontakt', [WebController::class, 'contact']);
```

- Veřejné stránky → `routes/public.php` (automaticky jdou do sitemap.xml).
- Administrace, formulářové cíle, cokoliv za loginem → `routes/admin.php` (do sitemapy nejdou).
- URL se píše **bez úvodního lomítka**, homepage je `''`.

### b) Akce v controlleru – `app/Controllers/WebController.php`

```php
public function contact() {
    return $this->render('contact', [
        'pageTitle'       => 'Kontakt',
        'pageDescription' => 'Jak nás kontaktovat.',
        'menuItems'       => $this->menuItems(),
        'appVersion'      => Bootstrap::VERSION,
    ]);
}
```

`pageTitle`, `pageDescription` a `menuItems` čte hlavička layoutu – posílejte je vždy.

Pro větší celky si založte vlastní controller (`app/Controllers/ContactController.php`), ideálně s vlastní bázovou třídou nebo zděděním `WebController`, ať nemusíte kopírovat `menuItems()`:

```php
namespace App\Controllers;

class ContactController extends WebController {
    public function show() {
        return $this->render('contact', [
            'pageTitle' => 'Kontakt',
            'menuItems' => $this->menuItems(),
        ]);
    }
}
```

> Router zavolá jen **public** metodu deklarovanou **přímo** na dané třídě. Zděděné a protected metody z URL dosažitelné nejsou – to je záměr.

### c) Šablona – `app/Views/contact.php`

```php
<?php
/** @var string $pageTitle */
?>
<section class="section">
    <div class="container">
        <h1><?= esc($pageTitle) ?></h1>
        <p>Napište nám.</p>
    </div>
</section>
```

- **Vždy** vypisujte proměnné přes `esc()`.
- Podsložky jsou v pořádku: `render('blog/detail', …)` → `app/Views/blog/detail.php`.
- Bez hlavičky/patičky (AJAX fragment): `render('partials/list', $data, layout: 'none')` – cokoliv jiného než `'main'`.
- Jiný HTTP status: `render('contact', $data, 'main', 201)`.

### d) Styly stránky (volitelně)

Vytvořte `assets/scss/pages/_contact.scss` a přidejte `@import 'pages/contact';` do `assets/scss/main.scss`. Viz [kapitola 16](#16-styly-a-javascript).

---

## 4. Stránka s parametrem v URL

```php
// routes/public.php
$router->get('blog/{slug}', [BlogController::class, 'show']);
$router->get('blog/strana/{page}', [BlogController::class, 'index']);
```

```php
public function show(string $slug) { … }   // {slug} se předá podle NÁZVU parametru
public function index(int $page = 1) { … } // int: jen číslice, jinak 404
```

- Typ parametru řídí přetypování: `int`, `float`, `bool`; `string`/bez typu zůstane string (`007` ≠ `7`).
- Nesedí typ → **404**, ne TypeError.
- Nenalezený záznam vraťte sami jako 404 – nejjednodušší je chybová stránka přes `Response`:

```php
$article = $this->articles->findBySlug($slug);
if ($article === null) {
    return $this->render('errors/error', ['pageTitle' => 'Nenalezeno', 'menuItems' => $this->menuItems()], 'main', 404);
}
```

Do akce si můžete nechat „vstříknout“ i `Request`, `Session` nebo libovolnou službu:

```php
public function show(string $slug, Request $request, ArticleRepository $articles) { … }
```

---

## 5. Položka v menu

Menu se skládá v `app/Models/Menu/MenuRepository.php`, v konstruktoru:

```php
$this->add('Home', '/');
$this->add('Služby', '/sluzby');
$this->addSubmenu('Služby', 'Konzultace', '/sluzby/konzultace');
$this->add('Kontakt', '/kontakt');
```

Aktivní položka se určí automaticky podle URL (aktivní dítě zvýrazní i rodiče). Externí odkazy a kotvy (`#…`) aktivní nikdy nejsou.

---

## 6. Formulář

### a) Routy

```php
// routes/public.php – zobrazení
$router->get('kontakt', [ContactController::class, 'show']);

// routes/admin.php – cíl formuláře (do sitemapy nepatří)
$router->post('kontakt', [ContactController::class, 'send']);
```

### b) Šablona

```php
<?php if (has_flash('success')): ?>
    <p class="alert"><?= esc(flash('success')) ?></p>
<?php endif ?>

<form action="/kontakt" method="POST">
    <?= csrf_field() ?>

    <label for="email">E-mail</label>
    <input id="email" name="email" value="<?= esc(old('email')) ?>">
    <?php if (has_error('email')): ?>
        <p class="error"><?= esc(error('email')) ?></p>
    <?php endif ?>

    <label for="message">Zpráva</label>
    <textarea id="message" name="message"><?= esc(old('message')) ?></textarea>
    <?php if (has_error('message')): ?>
        <p class="error"><?= esc(error('message')) ?></p>
    <?php endif ?>

    <button type="submit">Odeslat</button>
</form>
```

### c) Controller

```php
public function send(): Response {
    $data = $this->validate([
        'email'   => 'required|email|max:255',
        'message' => 'required|min:10',
    ], [
        'email.required' => 'Vyplňte e-mail.',
        'min'            => 'Pole je příliš krátké.',
    ]);

    // $data obsahuje JEN validovaná pole – lze rovnou uložit
    $this->messages->insert($data + ['created_at' => date('Y-m-d H:i:s')]);

    $this->session()->flash('success', 'Děkujeme, zpráva odeslána.');

    return $this->redirect('/kontakt');
}
```

Co se děje samo:

- **CSRF** kontroluje router u každého POST/PUT/PATCH/DELETE. Bez `csrf_field()` dostanete **419**.
- **Neúspěšná validace**: prohlížeč je přesměrován zpět (303) s chybami a starým vstupem; JSON klient dostane **422** `application/problem+json`.

Pravidla: `required`, `nullable`, `sometimes`, `string`, `int`, `numeric`, `bool`, `array`, `email`, `url`, `date`, `alpha`, `alpha_num`, `alpha_dash`, `min`, `max`, `between`, `in`, `not_in`, `regex`, `same`, `different`, `confirmed`, `accepted`.

```php
'code'  => ['required', 'regex:/^[A-Z]{2}:\d+$/'],                 // pole, když argument obsahuje |
'slug'  => ['required', fn ($v) => $repo->free($v) ?: 'Slug je obsazený.'],
'end'   => [fn ($v, array $data) => $v > $data['start']],
```

Validace mimo controller: `Validator::make($data, $rules)->fails()` / `->errors()` / `->validated()`.

Posílání e-mailu: v projektu je `phpmailer/phpmailer` a v configu `contact_form.to_email` / `from_email` – obalte PHPMailer do vlastní služby (kapitola 10).

---

## 7. Databázová tabulka

Příklad: tabulka `articles`.

### a) Migrace

```bash
php tty make:migration create_articles_table
```

Vznikne `database/migrations/RRRR_MM_DD_HHMMSS_create_articles_table.php`:

```php
<?php

use App\Core\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(PDO $db): void {
        $db->exec("CREATE TABLE articles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )");
        $db->exec('CREATE UNIQUE INDEX articles_slug_unique ON articles (slug)');
    }

    public function down(PDO $db): void {
        $db->exec('DROP TABLE articles');
    }
};
```

```bash
php tty migrate
```

| Příkaz | Co dělá |
| --- | --- |
| `php tty migrate` | Spustí nové migrace (jedna dávka) |
| `php tty migrate:status` | Stav, včetně „missing“ (soubor zmizel z větve) |
| `php tty migrate:rollback` | Vrátí poslední dávku |

- SQL se píše ručně, žádný schema builder.
- Nevratná migrace: v `down()` vyhoďte `IrreversibleMigration`.
- Chcete-li, aby šla i v SQLite testech, řešte auto-increment podle driveru (viz `app/Modules/Auth/migrations/…create_users_table.php`).
- **Už nasazenou migraci neupravujte** – vytvořte novou (`add_perex_to_articles`).

### b) Model – `app/Models/Article/Article.php`

```php
namespace App\Models\Article;

use App\Core\Database\Model;

class Article extends Model {
    public int $id = 0;
    public string $title = '';
    public string $slug = '';
    public string $body = '';
    public bool $published = false;
    public string $created_at = '';
}
```

Veřejné vlastnosti se naplní z řádku a přetypují (`'1'` → `true`). Citlivé věci (hash hesla) držte **private** – pak nejsou v `toArray()` ani JSON (vzor: `Modules\Auth\User`).

### c) Repository – `app/Models/Article/ArticleRepository.php`

```php
namespace App\Models\Article;

use App\Core\Database\Repository;

class ArticleRepository extends Repository {
    protected string $table = 'articles';
    protected string $model = Article::class;

    public function findBySlug(string $slug): ?Article {
        $article = $this->first(['slug' => $slug, 'published' => 1]);

        return $article instanceof Article ? $article : null;
    }

    /** @return Article[] */
    public function latest(int $limit = 10): array {
        return $this->where(['published' => 1], ['created_at' => 'DESC'], $limit);
    }

    /** Vlastní SQL – vždy s bindováním parametrů */
    public function search(string $q): array {
        $rows = $this->run(
            'SELECT * FROM ' . $this->table() . ' WHERE title LIKE :q',
            ['q' => '%' . $q . '%']
        )->fetchAll();

        return $this->hydrateAll($rows);
    }
}
```

Hotové metody: `find`, `findRaw`, `all`, `allRaw`, `where`, `first`, `count`, `paginate`, `insert`, `update`, `delete`, `transaction`.

---

## 8. CRUD nad tabulkou

```php
// routes/admin.php
use App\Controllers\Admin\ArticleController;
use App\Core\Http\Router;
use Modules\Auth\Middleware\RequireLogin;

$router->group(['middleware' => [RequireLogin::class]], function (Router $router): void {
    $router->get('admin/articles', [ArticleController::class, 'index']);
    $router->get('admin/articles/new', [ArticleController::class, 'create']);
    $router->post('admin/articles', [ArticleController::class, 'store']);
    $router->get('admin/articles/{id}/edit', [ArticleController::class, 'edit']);
    $router->post('admin/articles/{id}', [ArticleController::class, 'update']);
    $router->post('admin/articles/{id}/delete', [ArticleController::class, 'destroy']);
});
```

```php
namespace App\Controllers\Admin;

use App\Controllers\WebController;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Models\Article\ArticleRepository;

class ArticleController extends WebController {
    public function __construct(private ArticleRepository $articles) {
    }

    public function index(Request $request): Response {
        $page = max(1, (int) $request->query('page', 1));

        return $this->render('admin/articles/index', [
            'pageTitle' => 'Články',
            'menuItems' => $this->menuItems(),
            'result'    => $this->articles->paginate($page, 20, [], ['created_at' => 'DESC']),
            // ['data' => [...], 'page', 'perPage', 'total', 'pages']
        ]);
    }

    public function store(): Response {
        $data = $this->validate([
            'title' => 'required|max:255',
            'slug'  => 'required|alpha_dash|max:255',
            'body'  => 'required',
        ]);

        $this->articles->insert($data + ['created_at' => date('Y-m-d H:i:s')]);
        $this->session()->flash('success', 'Článek uložen.');

        return $this->redirect('/admin/articles');
    }

    public function update(int $id): Response {
        $data = $this->validate(['title' => 'required|max:255', 'body' => 'required']);
        $this->articles->update($id, $data);

        return $this->redirect('/admin/articles');
    }

    public function destroy(int $id): Response {
        $this->articles->delete($id);

        return $this->redirect('/admin/articles');
    }
}
```

- Mazání přes **POST** (s `csrf_field()`), nikdy přes GET odkaz.
- Víc zápisů najednou: `Connection::transaction(fn () => …)` – vnořené transakce jsou savepointy.

---

## 9. JSON API endpoint

```php
// routes/admin.php (API do sitemapy nepatří)
use Modules\Auth\Middleware\RequireToken;

$router->group(['middleware' => [RequireToken::class], 'csrf' => false], function (Router $router): void {
    $router->get('api/articles', [ArticleApi::class, 'index']);
    $router->get('api/articles/{id}', [ArticleApi::class, 'show']);
    $router->patch('api/articles/{id}', [ArticleApi::class, 'update']);
    $router->delete('api/articles/{id}', [ArticleApi::class, 'destroy']);
});
```

```php
final class ArticleApi extends Controller {
    public function __construct(private ArticleRepository $articles) {
    }

    public function show(int $id): Response {
        $article = $this->articles->find($id);

        return $article
            ? $this->json($article)
            : Response::problem(404, 'Article not found.');
    }

    public function update(int $id): Response {
        $data = $this->validate(['title' => 'sometimes|required|max:255']); // sometimes = PATCH
        $this->articles->update($id, $data);

        return Response::noContent();
    }
}
```

- JSON tělo (`Content-Type: application/json`) se automaticky rozbalí do `$request->post()` / validace.
- `csrf => false` **jen** u rout autentizovaných jinak než cookie (token, podpis webhooku).
- API volané z prohlížeče ze stejné domény přes session nechte s CSRF a posílejte hlavičku `X-CSRF-Token` (`<meta name="csrf-token" content="<?= csrf_token() ?>">`).
- Jiná doména → CORS, první v seznamu middleware: `new Cors(['https://app.example.cz'], credentials: true)`.
- Chyby (404, 405, 419, 422, 500) dostane JSON klient automaticky jako `application/problem+json`.

---

## 10. Služba a dependency injection

Business logiku nedávejte do controlleru – založte třídu v `app/Services/`:

```php
namespace App\Services\Contact;

use App\Core\Config;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

final class ContactMailer {
    public function __construct(private LoggerInterface $log) {
    }

    public function send(string $from, string $message): void {
        $mail = new PHPMailer(true);
        $mail->setFrom((string) Config::get('contact_form.from_email'));
        $mail->addAddress((string) Config::get('contact_form.to_email'));
        $mail->addReplyTo($from);
        $mail->Subject = 'Zpráva z webu';
        $mail->Body = $message;
        $mail->send();

        $this->log->info('Contact message sent from {from}', ['from' => $from]);
    }
}
```

A použijte ji – **nic se neregistruje**, container ji postaví sám:

```php
public function __construct(private ContactMailer $mailer) {
}
// nebo jen pro jednu akci:
public function send(ContactMailer $mailer): Response { … }
```

Kdy je potřeba registrace (v `Bootstrap::buildContainer()` v `app/Bootstrap.php`):

```php
// rozhraní → implementace
$container->alias(PaymentGateway::class, StripeGateway::class);

// konstruktor potřebuje hodnotu, kterou container nezná (string z configu)
$container->bind(StripeGateway::class, static fn () => new StripeGateway((string) Config::get('stripe.key')));
```

- Container sdílí instance (`get()` = singleton v rámci requestu); nová instance: `$container->make(Report::class, ['month' => '2026-09'])`.
- Už registrované: `Request`, `Session`, `PDO`, `LoggerInterface`, `Container`, `ContainerInterface`, `Migrator`.

---

## 11. Middleware

`app/Http/Middleware/…` (nebo v modulu `Modules/X/Middleware/`):

```php
namespace App\Http\Middleware;

use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class Maintenance implements MiddlewareInterface {
    public function process(Request $request, callable $next): Response {
        if (is_file(__DIR__ . '/../../../storage/maintenance')) {
            return Response::html('Probíhá údržba.', 503);    // short-circuit
        }

        return $next($request)->setHeader('X-App', 'conscribe'); // úprava odpovědi
    }
}
```

Registrace:

```php
// globálně (vidí i 404/405) – např. na začátek app/Config/routes.php
$router->middleware(Maintenance::class);

// skupina
$router->group(['middleware' => [RequireLogin::class]], function (Router $router): void { … });

// jedna routa (připojí se ZA middleware skupiny)
$router->post('admin/save', [AdminController::class, 'save'], ['middleware' => [AuditLog::class]]);
```

Pořadí: globální → skupina → routa → akce. Middleware může mít závislosti v konstruktoru (container). Přepsání cesty: `$next($request->withPath('nova/cesta'))`.

---

## 12. Přihlašování (modul Auth)

1. Ověřte, že `'Auth'` je v `modules` v `app/Config/main.php`.
2. Tabulky a první uživatel:

```bash
php tty migrate
```

```bash
php tty user:create jan@example.cz
```

```bash
php tty token:create jan@example.cz "mobile"
```

3. Login stránku si napíšete sami (modul ji záměrně nemá):

```php
// routes/admin.php
$router->group(['middleware' => [Guest::class]], function (Router $router): void {
    $router->get('login', [LoginController::class, 'form']);
    $router->post('login', [LoginController::class, 'login']);
});
$router->group(['middleware' => [RequireLogin::class]], function (Router $router): void {
    $router->post('logout', [LoginController::class, 'logout']);
});
```

```php
final class LoginController extends WebController {
    public function __construct(private Auth $auth) {
    }

    public function form(): Response {
        return $this->render('auth/login', ['pageTitle' => 'Přihlášení', 'menuItems' => $this->menuItems()]);
    }

    public function login(Request $request): Response {
        $data = $this->validate(['email' => 'required|email', 'password' => 'required']);

        return match ($this->auth->attempt($data['email'], $data['password'], (string) $request->getClientIp())) {
            LoginResult::Success   => $this->redirect($this->auth->intended('/admin/articles')),
            LoginResult::Throttled => $this->redirect('/login?error=throttled'),
            LoginResult::Failed    => $this->redirect('/login?error=credentials'),
        };
    }

    public function logout(): Response {
        $this->auth->logout();

        return $this->redirect('/');
    }
}
```

- Aktuální uživatel: `$this->auth->user()`, přihlášen?: `$this->auth->check()`.
- Jiná login URL: `'auth' => ['login_url' => '/prihlaseni']` v configu.
- Chybí: reset hesla, ověření e-mailu, role, „remember me“ – řešte vlastním modulem/službou.

---

## 13. Konzolový příkaz

`app/Console/Commands/ImportArticlesCommand.php`:

```php
namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use App\Models\Article\ArticleRepository;

final class ImportArticlesCommand extends Command {
    protected string $name = 'articles:import';
    protected string $description = 'Importuje články z CSV.';
    protected string $help = 'Usage: php tty articles:import [soubor.csv] [--dry-run]';

    public function __construct(private ArticleRepository $articles) {
    }

    public function run(Input $input, Output $output): int {
        $file = $input->argument(0, 'articles.csv');

        if (!is_file($file)) {
            $output->error("Soubor {$file} neexistuje.");

            return self::FAILURE;
        }

        $dryRun = $input->hasOption('dry-run');
        // … import …
        $output->success('Hotovo.');

        return self::SUCCESS;
    }
}
```

Registrace – přidejte třídu do seznamu v `Bootstrap::kernel()` (`app/Bootstrap.php`), nebo ji vraťte z `commands()` modulu.

```bash
php tty articles:import data.csv --dry-run
```

`Input`: `argument(0)`, `option('env')`, `hasOption('watch')`. `Output`: `line`, `title`, `success`, `warning`, `comment`, `error` (stderr), `columns`.

Na cron: `php /cesta/k/projektu/tty articles:import`.

---

## 14. Vlastní modul

Modul = samostatná složka, kterou lze přidat/smazat bez zásahu do jádra. Hodí se pro funkci, kterou chcete přenášet mezi projekty (blog, newsletter, jazykové mutace).

```
app/Modules/Newsletter/
├── Module.php              # povinné – třída Modules\Newsletter\Module
├── helpers.php             # volitelné – globální funkce, načtou se samy
├── migrations/             # volitelné – spustí je `php tty migrate`
├── Views/                  # volitelné – šablony modulu, `newsletter::…`
├── Controllers/
├── Commands/
├── Middleware/
└── SubscriberRepository.php
```

```php
namespace Modules\Newsletter;

use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Module\Module as BaseModule;

final class Module extends BaseModule {
    // před routováním; může upravit request (např. odříznout /en)
    public function boot(Request $request): Request {
        return $request;
    }

    public function registerRoutes(Router $router): void {
        $router->post('newsletter', [Controllers\SubscribeController::class, 'store'], ['sitemap' => false]);
    }

    public function commands(): array {
        return [Commands\SendNewsletterCommand::class];
    }
}
```

Zapnutí: přidejte `'Newsletter'` do `'modules' => [...]` v `app/Config/main.php`. **Když modul v seznamu chybí, je tiše vypnutý** (routy, příkazy i migrace). Namespace `Modules\` je v composer autoloadu, nic dalšího netřeba.

Šablony modulu dejte do jeho `Views/` a volejte je s prefixem – malými písmeny název modulu a `::`: `$this->render('newsletter::form')` vykreslí `app/Modules/Newsletter/Views/form.php`, `component('newsletter::signup')` čte `Views/components/signup.php`. Layout (hlavička, patička) je vždy ten z `app/Views/layout/`. Bez prefixu se hledá v `app/Views/` jako dřív.

Přepsání šablony modulu bez zásahu do modulu: `ViewPaths::register('newsletter', $slozka, prepend: true)` – co v té složce je, má přednost, zbytek se bere z modulu.

---

## 15. Komponenta a vlastní helper

### Komponenta (znovupoužitelný kus HTML)

`app/Views/components/article-card.php` (složku založte):

```php
<?php /** @var \App\Models\Article\Article $article */ ?>
<article class="card">
    <h3><a href="/blog/<?= esc($article->slug) ?>"><?= esc($article->title) ?></a></h3>
    <p><?= esc(truncate(strip_tags($article->body), 140)) ?></p>
</article>
```

Použití v šabloně: `<?php component('article-card', ['article' => $article]) ?>` – vlastní scope proměnných.

### Globální helper

- Obecný → přidejte funkci do `app/Helpers/TemplateHelper.php` (obalte `if (!function_exists('x'))`).
- Patří k modulu → `app/Modules/X/helpers.php`.
- Vlastní soubor → přidejte ho do `autoload.files` v `composer.json` a spusťte `composer dump-autoload`.

```php
if (!function_exists('price')) {
    function price(float $amount): string {
        return number_format($amount, 0, ',', ' ') . ' Kč';
    }
}
```

Existující: `esc`, `dateFormat`, `truncate`, `component`, `asset`, `image`, `image_tag`, `svg`, `csp_nonce`, `csp_nonce_attr`, `renderTrackingCodes`, `flash`, `has_flash`, `errors`, `has_error`, `error`, `old`, `csrf_token`, `csrf_field`, `validate_csrf`, `check_csrf`.

---

## 16. Styly a JavaScript

**Needitujte `public/css/style.css` ani `public/js/main.js`** – jsou generované.

| Chci přidat | Kam |
| --- | --- |
| Styl stránky | `assets/scss/pages/_nazev.scss` + `@import 'pages/nazev';` v `main.scss` |
| Komponentu (tlačítko, karta) | `assets/scss/components/_nazev.scss` + import |
| Barvu / design token | CSS custom property v `assets/scss/base/_tokens.scss` (i dark mode blok) |
| Breakpoint | `assets/scss/abstracts/_variables.scss`, v kódu `@include mq(md) { … }` |
| JS | `assets/js/main.js`, nebo nový soubor + přidat do `assets.js.sources` v configu |

- Používejte `@import`, ne `@use` (scssphp neumí moduly).
- Responzivní úpravy pište k dané komponentě, ne do jednoho bloku na konci.
- JS pište v **ES5** (minifikátor nezná `?.`, `??`, template literály). Potřebujete moderní syntaxi → `assets.js.minify => false`.
- V dev se kompiluje samo při requestu. Ručně / před commitem:

```bash
composer build
```

```bash
composer build:watch
```

Externí knihovna: soubor do `public/js/` nebo `public/css/` a vložte v `app/Views/layout/header.php` / `footer.php` přes `asset('js/knihovna.js')` (cache-busting). Inline `<script>` při zapnuté CSP potřebuje `<?= csp_nonce_attr() ?>`.

---

## 17. Obrázky a SVG ikony

```php
<img src="<?= image('img/photo.jpg', 1200, 500) ?>" alt="">
<?= image_tag('img/photo.jpg', 'card', 0, ['alt' => 'Náhled', 'class' => 'card__img']) ?>
```

- Zdroj v `public/…`, výstup se cachuje v `public/media/cache/` (WebP, crop na střed).
- Nový rozměr: `media.presets` v configu, např. `'hero' => [1920, 800]`.
- Po změně `quality`/`format` vyčistěte cache: `MediaService::cache()?->purge();`.
- `srcset`: `MediaService::cache()?->srcset('img/photo.jpg', [400, 800, 1200], 16 / 9)`.

SVG ikona: soubor do `public/img/icons/nazev.svg`, v šabloně `<?= svg('nazev', ['class' => 'icon']) ?>` (sanitizuje se).

---

## 18. Konfigurace

1. Přidejte klíč do `app/Config/main.example.php` **i** `app/Config/main.php`:

```php
'shop' => [
    'currency' => 'CZK',
    'vat'      => 21,
],
```

2. Čtení kdekoliv: `Config::get('shop.vat', 21)`, `Config::has('shop.currency')`.

Vždy dávejte rozumný default v `get()`, ať starší nasazený config nerozbije web.
Tajné hodnoty (API klíče) jen do `main.php`, nikdy do example.

---

## 19. Chybové stránky

`app/Views/errors/error.php` je výchozí. Vlastní šablona podle kódu: `errors/404.php`, případně rodina `errors/4xx.php`. Dostávají `menuItems` a údaje o chybě (podívejte se do `error.php`, jaké proměnné používá). JSON klienti dostanou místo stránky problem+json automaticky.

V produkci se u 5xx zobrazí obecná věta, detail je jen v logu.

---

## 20. Logování a debug

```php
public function __construct(private LoggerInterface $log) {}
$this->log->info('Objednávka {id} zaplacena', ['id' => $id]);
$this->log->error('Platba selhala', ['exception' => $e]);
// nebo staticky: Log::get()->warning('…');
```

- Logy: `storage/logs/app-RRRR-MM-DD.log`; HTML výpisy výjimek: `storage/exceptions/`.
- V `development` běží Tracy: bluescreen a debug bar, `bdump($var)` pošle proměnnou do baru, `dump($var)` vypíše.
- `log.stderr => true` → logy i v `docker logs`.

---

## 21. Sitemap

`/sitemap.xml` se generuje z rout, nic nekonfigurujete:

- routa v `routes/public.php` → v sitemapě,
- routa v `routes/admin.php` nebo s `['sitemap' => false]` → není,
- vlastní priorita: `['sitemap' => ['changefreq' => 'daily', 'priority' => 1.0]]`,
- dynamické URL (`blog/{slug}`) potřebují provider:

```php
$router->get('blog/{slug}', [BlogController::class, 'show'], [
    'sitemap' => ['provider' => fn () => array_map(
        fn ($a) => ['slug' => $a->slug],
        (new ArticleRepository())->where(['published' => 1])
    )],
]);
```

Provider se volá až při požadavku na sitemap.xml. Pro `lastmod` vracejte `App\Services\Sitemap\SitemapUrl`.

---

## 22. Testy a kontrola kvality

Testy jsou v `tests/` (PHPUnit), strukturou kopírují `app/`. Pomocníci: `Tests\Support\RequestFactory`, `FakeSession`, `ArraySession`.

Test routy bez serveru:

```php
$router = new Router(new FakeSession());
$router->get('blog/{slug}', static fn (string $slug): Response => Response::json(['slug' => $slug]));

$response = $router->handle(RequestFactory::…); // viz tests/Core/Http/RouterTest.php
self::assertSame(200, $response->getStatusCode());
```

Repository testujte proti SQLite v paměti: `new ArticleRepository(new PDO('sqlite::memory:'))` (vzor `tests/Core/Database/RepositoryTest.php`).

| Příkaz | Co |
| --- | --- |
| `composer test` | PHPUnit |
| `composer analyse` | PHPStan |
| `composer lint` | syntaxe PHP |
| `composer fix` | coding style (PSR-12 + závorky na stejném řádku) |
| `composer check` | lint + analyse + test – spusťte před commitem |

Když měníte verzi, `VersionTest` hlídá shodu `Bootstrap::VERSION` s README a CHANGELOG.

---

## 23. Nasazení

1. Lokálně `composer build` a commitnout zkompilované `public/css` a `public/js`.
2. Na serveru:

```bash
composer install --no-dev --optimize-autoloader
```

```bash
php tty migrate
```

3. `app/Config/main.php`: `environment => 'production'`, správná `base_url`, DB, případně `trusted_proxies` (za Cloudflare/nginx proxy) a `security.csp`.
4. Document root = `public/`, zapisovatelné `storage/logs`, `storage/exceptions`, `public/media/cache`.

---

## 24. Tahák

| Chci… | Soubor / příkaz |
| --- | --- |
| novou URL | `app/Config/routes/public.php` nebo `admin.php` |
| logiku stránky | `app/Controllers/*Controller.php` |
| HTML | `app/Views/*.php` |
| hlavičku / patičku | `app/Views/layout/header.php`, `footer.php` |
| opakovaný kus HTML | `app/Views/components/*.php` + `component()` |
| položku menu | `app/Models/Menu/MenuRepository.php` |
| tabulku | `php tty make:migration …` → `database/migrations/` |
| práci s tabulkou | `app/Models/X/XRepository.php` + `X.php` |
| business logiku | `app/Services/…` (autowiring) |
| binding rozhraní | `Bootstrap::buildContainer()` |
| ochranu rout | middleware ve `group(['middleware' => [...]])` |
| CLI příkaz | třída `extends Command` + `Bootstrap::kernel()` |
| přenositelnou funkci | `app/Modules/Nazev/Module.php` + `modules` v configu |
| helper do šablon | `app/Helpers/TemplateHelper.php` |
| styly | `assets/scss/…` + import v `main.scss` |
| JS | `assets/js/main.js` (ES5) |
| config | `main.example.php` + `main.php`, `Config::get()` |
| chybovou stránku | `app/Views/errors/404.php` |
| zkontrolovat vše | `composer check` |
