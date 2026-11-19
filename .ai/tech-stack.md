# ConscribePHP Technology Stack & Environment Details

This document provides a breakdown of the languages, runtimes, dependencies, configuration details, and Docker setups used in ConscribePHP.

---

## 💻 Tech Stack Summary

| Component | Technology | Version / Configuration |
| :--- | :--- | :--- |
| **Language Runtime** | **PHP** | `8.4+` (via Docker `php:8.4-apache`) |
| **Web Server** | **Apache** | `2.4` with `mod_rewrite` enabled |
| **Package Manager** | **Composer** | Native loading, PSR-4 autoload rules |
| **Database RDBMS** | **MySQL / MariaDB** | PDO extension with `pdo_mysql` driver |

---

## 🛠️ PHP Runtime & Security Configurations

All client requests trigger the bootstrapping logic inside [public/index.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/public/index.php), which enforces several runtime configurations:

### 1. PHP Extensions
The Docker environment provides the following pre-compiled extensions:
- Database connectivity: `pdo`, `pdo_mysql`, `mysqli`
- Image and processing: `gd`, `imagick`
- Utility/Standard: `zip`, `mbstring`, `fileinfo`, `curl`, `intl`, `bcmath`, `exif`, `opcache`

### 2. Session Configuration & Hardening
PHP Session configurations are set before initializing `session_start()`:
- `session.use_only_cookies = 1` (Blocks session identifier passing in URL parameters)
- `session.use_strict_mode = 1` (Protects against session fixation)
- `session.use_trans_sid = 0` (Disables transparent session ID support)
- `session.cookie_httponly = 1` (Prevents client-side JS from accessing the session cookie)
- `session.cookie_samesite = 'Lax'` (CSRF cookie mitigation)
- `session.cookie_secure = 1` (Enforces HTTPS cookie transmission, dynamically evaluated based on proxy checks)

### 3. HTTP Security Headers
The framework automatically issues these HTTP security headers:
- `Strict-Transport-Security` (HSTS: configured to `max-age=31536000; includeSubDomains; preload` when HTTPS is active)
- `X-Frame-Options: SAMEORIGIN` (Mitigates clickjacking)
- `X-Content-Type-Options: nosniff` (Prevents MIME sniffing)
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: geolocation=(), camera=(), microphone=()` (Disables access to device sensors)

---

## 📦 Package Manager & PSR-4 Autoloading

The project uses Composer for autoloading and for the packages its features need: `monolog/monolog` (logging), `tracy/tracy` (debugging), `phpmailer/phpmailer` (mail), `scssphp/scssphp` and `matthiasmullie/minify` (asset pipeline), `psr/container` (container interface). Dev tools: PHPUnit, PHPStan, PHP-CS-Fixer.

### `composer.json` Configuration
- Namespace mappings defined in PSR-4 autoload block:
  - `App\` &rarr; [app/](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/)
  - `Modules\` &rarr; [app/Modules/](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Modules/)
- Native PHP extension requirements:
  - `ext-mbstring`, `ext-pdo` and `ext-gd` are required; `ext-imagick` and `ext-exif` are suggested.

---

## 🐳 Environments & Docker Orchestration

ConscribePHP supports two distinct Docker Compose setup patterns:

### 1. Standard Web Container Setup ([docker-compose.yml](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/docker-compose.yml))
- Build Context: `.` (using root [Dockerfile](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/Dockerfile))
- Container Name: `conscribe-php`
- Port Mapping: `8050:80` (Exposes Apache on port 8050 of host)
- Volume: `./:/var/www/html/` (Live sync of the repository with Apache's root)

### 2. Multi-Service Container Network ([docker-compose_db.yml](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/docker-compose_db.yml))
Designed for linking the web service with an external MySQL/MariaDB database:
- Port Mapping: Custom placeholder `"8XXX:80"`
- Networks: Integrates with an external network named `database-network`.

---

## ⚙️ Configuration Setup & Paths

The application relies on a `.php` file that returns an associative configuration array.

### Configuration Template Files
- **Example configuration**: [app/Config/main.example.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Config/main.example.php)
- **Active configuration**: `app/config.php` (git-ignored for security)

> [!IMPORTANT]
> **Configuration Path Warning**:
> There is a slight mismatch in the expected path of the live `config.php` file versus the template file:
> 1. The active config file **must** be created at `app/config.php` (not `app/Config/config.php`).
> 2. [BaseModel](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Models/BaseModel.php#L14) imports `config.php` from `dirname(__DIR__) . '/config.php'`, which resolves to [app/config.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/config.php).
> 3. [TemplateHelper](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Helpers/TemplateHelper.php#L97-L101) attempts to fall back to `app/main.example.php` if `app/config.php` is missing. However, the template file is stored inside the subfolder [app/Config/main.example.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Config/main.example.php).
> **Deployment Action**: Always copy [app/Config/main.example.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Config/main.example.php) directly up to `app/config.php`:
> ```bash
> cp app/Config/main.example.php app/config.php
> ```

### Config Schema Configuration Fields
- **conscribe**: Metadata including framework version (`ver: 1.3.3`), author name, and environment mode (`development`/`production`).
- **contact_form**: Recipient (`to_email`) and sender (`from_email`) parameters.
- **db**: Credentials for Database Singleton initialization (`host`, `dbname`, `user`, `pass`, `charset`).
- **tracking**: Measurement IDs for marketing tags (GA4, Sklik, Facebook Pixel, Google Tag Manager).
