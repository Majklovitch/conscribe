# Conscribe-PHP Framework Audit (1.x – archived)

> **Archived 2026-11-19.** This audit covers the 1.x code (`BaseModel`, `ContactSender`,
> `LanguageMutations`, `MediaService::downloadFile`), most of which was rewritten or removed
> in 2.x. Every finding has been re-checked; the status and the remaining open items are in
> [docs/ROADMAP.md](../ROADMAP.md#already-done). File links below point to code that no
> longer exists.

## Summary

Overall the framework is in decent shape — security fundamentals (CSRF, XSS escaping, allowlist routing, session hardening) are correctly implemented. However there are real vulnerabilities and a number of bugs / improvements worth addressing.

---

## 🔴 Vulnerabilities

### 1. Email Header Injection via `Reply-To` — `MailerModel.php`
**File:** [`MailerModel.php:23`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Modules/ContactSender/Models/MailerModel.php#L22-L23)

The `$subject` is sanitized for newlines, but `$sender_email` is placed raw into the `Reply-To` header. Even though `filter_input` with `FILTER_VALIDATE_EMAIL` is used upstream, the RFC allows emails like `user@host\r\nBcc: victim@evil.com` on some platforms. Best practice: also strip `\r`, `\n`, `\0` from the validated email before building headers.

```diff
-$headers .= "Reply-To: " . $sender_email . "\r\n";
+$senderEmailSafe = str_replace(["\r", "\n", "\0"], '', $sender_email);
+$headers .= "Reply-To: " . $senderEmailSafe . "\r\n";
```

---

### 2. `$table` Not Quoted in `BaseModel` — SQL Injection Risk
**File:** [`BaseModel.php:25,31,34`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Models/BaseModel.php#L24-L35)

`$this->table` is interpolated directly into queries (`SELECT * FROM {$this->table}`). If a subclass ever sets `$table` from user input, this is a direct SQL injection. Even with static values it's bad practice. Backtick-quote the table name:

```diff
-$stmt = self::$db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
+$stmt = self::$db->prepare("SELECT * FROM `{$this->table}` WHERE id = ?");

-return self::$db->query("SELECT * FROM {$this->table}")->fetchAll();
+return self::$db->query("SELECT * FROM `{$this->table}`")->fetchAll();

-return self::$db->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
+return self::$db->query("SELECT COUNT(*) FROM `{$this->table}`")->fetchColumn();
```

---

### 3. `MediaService::downloadFile` — SSRF + No URL Validation
**File:** [`MediaService.php:137-167`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Services/MediaService.php#L137-L167)

`curl_init($url)` is called with a URL from `$mediaItems` which comes from external input. There is no validation that:
- The URL uses HTTP/HTTPS (could be `file://`, `ftp://`, `gopher://`, `dict://`)
- It doesn't point to internal services (SSRF to `http://169.254.169.254/` or `http://localhost/`)

**Fix:**
```php
private function isUrlAllowed(string $url): bool {
    $parsed = parse_url($url);
    if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) return false;
    $host = $parsed['host'] ?? '';
    // Block private/loopback ranges
    $ip = gethostbyname($host);
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
```
And call it before `curl_init`.

Also add `CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS` to restrict cURL protocols regardless.

---

### 4. `MediaService::guessExtension` — Unrestricted File Extension
**File:** [`MediaService.php:169-183`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Services/MediaService.php#L169-L183)

Any extension up to 8 characters from the URL path is trusted. An attacker could supply a URL like `http://cdn.example.com/malicious.php` and write a `.php` file into the storage directory. While public access depends on server config, it's a path traversal/webshell risk.

**Fix:** Allowlist extensions per type:

```php
private const ALLOWED_EXTENSIONS = [
    'IMAGE'    => ['jpg','jpeg','png','gif','webp','avif'],
    'VIDEO'    => ['mp4','webm','mov','avi'],
    'DOCUMENT' => ['pdf','doc','docx','xls','xlsx'],
];
```

---

### 5. `Language::setLang` — Cookie Set Without `httponly` / `secure` / `samesite`
**File:** [`Language.php:29`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Modules/LanguageMutations/Core/Language.php#L28-L30)

`setcookie('lang_preference', $lang, ...)` and similar calls use the old positional API with no `httponly`, `secure`, or `samesite` flags, making the cookie accessible to JavaScript.

```diff
-setcookie('lang_preference', $lang, time() + (86400 * 30), "/");
+setcookie('lang_preference', $lang, ['expires' => time() + 86400 * 30, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
```

---

### 6. `Language::getBrowserLang` — Open Redirect via Unvalidated `HTTP_ACCEPT_LANGUAGE`
**File:** [`Language.php:41-47`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Modules/LanguageMutations/Core/Language.php#L41-L47)

`substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'cs', 0, 2)` is used for a redirect. While `in_array($lang, $supported)` guards the redirect target, the input is unescaped in the header. The `in_array` check with `true` (strict) is correct and does prevent injection here, but the `$supported` array (`['en', 'pl']`) does **not include 'cs'** — if someone sends `Accept-Language: cs`, `cs` falls through as the default and no redirect happens, which is correct. No actual vulnerability here but worth noting the code is tightly coupled to hardcoded language lists in two places.

---

## 🟡 Bugs

### 7. `header.php` — `asset()` Called with Wrong Path for Cache Busting
**File:** [`header.php:14`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Views/layout/header.php#L14)

```html
<link ... href="/css/style.css?v=<?= asset('/js/main.js') ?>">
```

The `asset()` function is called with `/js/main.js` to generate the version for **CSS**. This means CSS is cache-busted by JS file timestamps — unrelated. Should be:

```html
<link ... href="<?= asset('public/css/style.css') ?>">
```

> Note: The `asset()` function uses `file_exists($path)` with a relative path — it won't resolve correctly unless the working directory is the project root. Consider passing the absolute path or using `__DIR__` inside `asset()`.

---

### 8. `View::render` — `$layout` Parameter Typed as `mixed` / No Type
**File:** [`View.php:5`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Core/View.php#L5)

```php
public static function render($viewPath, $data = [], $layout = 'main'): void
```

`$viewPath` and `$layout` have no type hints, while the method signature returns `null` in the parent but `void` here. The `Controller::render()` return type is `null` while `View::render()` is `void`. Add proper type hints:

```php
public static function render(string $viewPath, array $data = [], string $layout = 'main'): void
```

---

### 9. `View::render` — Falls Back to 404 View Silently
**File:** [`View.php:10-12`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Core/View.php#L10-L12)

If a view file doesn't exist, it silently loads the 404 view **without setting HTTP 404 status**. A missing view used in a `200 OK` response that renders the 404 template will confuse crawlers and monitoring tools. Set the status code:

```php
if (!file_exists($viewFile)) {
    http_response_code(404);
    $viewFile = __DIR__ . '/../Views/404.php';
}
```

---

### 10. `Controller::redirect()` — No URL Validation
**File:** [`Controller.php:13-15`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Core/Controller.php#L13-L15)

`header("Location: " . $url)` is called with no validation. If `$url` ever comes from user input (e.g., `?redirect=...`) this is an open redirect. Use a validation wrapper:

```php
protected function redirect(string $url): void {
    // Only allow relative paths or same-host URLs
    if (!str_starts_with($url, '/') && !str_starts_with($url, 'https://' . ($_SERVER['HTTP_HOST'] ?? ''))) {
        $url = '/';
    }
    header("Location: " . $url, true, 302);
    exit;
}
```

---

### 11. `ContactController` — `die()` Used for User-Facing Errors
**File:** [`ContactController.php:28`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Modules/ContactSender/Controllers/ContactController.php#L27-L28)

```php
die("Jste příliš rychlý! (Robot?)");
```

`die()` outputs a raw string without any layout, headers, or CSRF regeneration. Use the session flash pattern instead (like the rest of the controller does) and redirect:

```php
$_SESSION['mail_result'] = "⏱ Jste příliš rychlý! Zkuste to za chvíli.";
header("Location: " . $redirect_url);
exit;
```

---

### 12. `BaseModel` — PDO Connection Error Not Caught
**File:** [`BaseModel.php:17-20`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Models/BaseModel.php#L12-L21)

If the DB connection fails, a raw `PDOException` propagates to the user, potentially leaking DSN/credentials in development. Wrap in a try/catch:

```php
try {
    self::$db = new PDO($dsn, $c['user'], $c['pass'], [...]);
} catch (\PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Service temporarily unavailable.');
}
```

---

### 13. `home.php` — `javascript:alert()` in Data Attribute
**File:** [`home.php:3`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Views/home.php#L3)

```html
data-button-href="javascript:alert('clicked')"
```

This is leftover development/test code with a `javascript:` protocol. Should be removed or replaced with a proper URL before shipping.

---

### 14. `LanguageMutations` — Module Not Registered in Composer Autoload
**File:** [`composer.json`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/composer.json)

The `Modules\\` namespace is not in `composer.json`'s PSR-4 map — only `App\\` is registered. The `Language.php` file is `require_once`'d manually in `index.php`. This is fragile; add:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Modules\\": "app/Modules/"
    }
}
```

Then run `composer dump-autoload`.

---

## 🟢 Improvement Suggestions

### 15. `asset()` Function — Path Resolution
**File:** [`TemplateHelper.php:23-26`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Helpers/TemplateHelper.php#L23-L26)

`file_exists($path)` relies on the CWD being the project root. Pass an absolute path instead or accept the public root as parameter.

---

### 16. `BaseModel::$db` — Static Singleton Across Tests
**File:** [`BaseModel.php:9`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Models/BaseModel.php#L9)

`protected static $db = null` is a static singleton that makes unit testing hard (can't swap the connection). Consider using dependency injection or a `Database` service class passed via constructor.

---

### 17. `Router` — No HTTP Method Awareness
**File:** [`Router.php`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Core/Router.php)

The router only dispatches on URL segment, not HTTP method. A POST to `/home` runs `index()` the same as a GET. Add method-based dispatch for proper REST semantics and to protect POST-only endpoints.

---

### 18. `MediaService` — `@fopen` Error Suppression
**File:** [`MediaService.php:139`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Services/MediaService.php#L139)

`@fopen($destination, 'wb')` silently suppresses errors. Log the failure instead:

```php
$fp = fopen($destination, 'wb');
if (!$fp) {
    error_log("MediaService: Cannot open destination: $destination");
    return false;
}
```

---

### 19. `SitemapService` — Hardcoded Site URL
**File:** [`SitemapService.php:7`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Modules/SitemapAutocreation/Services/SitemapService.php#L7)

`private string $siteUrl = 'https://www.mvcprojekt.cz'` is hardcoded. This should come from `config.php` or a `$_SERVER['HTTP_HOST']`-derived value.

---

### 20. `CSP Header` — Missing Entirely
**File:** [`index.php`](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/public/index.php)

The security headers block in `index.php` is solid (HSTS, X-Frame-Options, nosniff, Referrer-Policy) but there is **no `Content-Security-Policy`** header. Adding even a basic CSP (e.g., `default-src 'self'`) significantly reduces XSS impact.

---

## Priority Summary

| # | Severity | File | Issue |
|---|----------|------|-------|
| 1 | 🔴 High | MailerModel.php | Reply-To header injection |
| 2 | 🔴 High | MediaService.php | SSRF via unvalidated URLs |
| 3 | 🔴 High | MediaService.php | Unrestricted file extension write |
| 4 | 🟡 Medium | BaseModel.php | Unquoted table name in SQL |
| 5 | 🟡 Medium | Language.php | Cookie missing security flags |
| 6 | 🟡 Medium | Controller.php | Redirect not validated |
| 7 | 🟡 Medium | BaseModel.php | PDO exception leaks credentials |
| 8 | 🟡 Medium | index.php | Missing CSP header |
| 9 | 🟡 Medium | composer.json | Modules namespace not autoloaded |
| 10 | 🟢 Low | View.php | Missing 404 status on missing view |
| 11 | 🟢 Low | header.php | Wrong file passed to asset() |
| 12 | 🟢 Low | ContactController.php | die() for user errors |
| 13 | 🟢 Low | home.php | javascript: in data attribute |
| 14 | 🟢 Low | SitemapService.php | Hardcoded site URL |
| 15 | 🟢 Low | MediaService.php | @fopen suppresses errors |
