# ConscribePHP AI Agent Workspace Index

Welcome! This directory serves as the **Single Source of Truth (SSoT)** for all AI agents operating within this repository. ConscribePHP is a clean, modern, and lightweight PHP MVC boilerplate framework for PHP 8.4+. It uses Composer packages where a feature needs them (Monolog, Tracy, PHPMailer, scssphp, minify, psr/container).

---

## 🗺️ Context Directory Sitemap

Before executing any tasks or modifying the codebase, familiarize yourself with the following references:

| Document | Purpose | Core Content |
| :--- | :--- | :--- |
| **[index.md](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/.ai/index.md)** | Master Entry Point | Architecture philosophy, general agent instructions, and context sitemap. |
| **[tech-stack.md](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/.ai/tech-stack.md)** | Tech Stack & Environment | PHP 8.4 configuration, Docker settings, Apache rewrites, and dependencies. |
| **[structure.md](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/.ai/structure.md)** | Directory Topology & MVC | File system layout, routing mechanics, and detailed request-response lifecycle. |
| **[workflows.md](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/.ai/workflows.md)** | Workflows & Standards | Coding standards (PSR), security checklists, and step-by-step extension guides. |

---

## 🏛️ Architecture Philosophy

ConscribePHP is constructed around several core engineering principles:
1. **Lean, not dependency-free**: Keep the core small and readable. Add a Composer package when a feature genuinely needs one, and prefer well-maintained, focused packages over large frameworks.
2. **Front-Controller Routing**: Maintain a clean separation between the entry point ([public/index.php](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/public/index.php)) and application logic via the custom object-oriented [Router](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Core/Router.php).
3. **Secure-by-Default (Defense-in-Depth)**: Ensure that every view, layout, and endpoint utilizes the built-in protection engines (CSRF validation, XSS escaping, secure cookies, and security headers).
4. **Modular Architecture**: Features that extend standard MVC pages (such as contact forms, language mutation engines, and sitemaps) are isolated inside self-contained modules under `app/Modules/`.

---

## 🤖 Directives for AI Agents

When implementing features, refactoring, or fixing bugs in this repository, you **must** adhere to these constraints:

### 1. Code Preservation & Conventions
- Preserve all existing code comments, docstrings, and helper functions unless they are directly related to the modification.
- Always use the `esc()` helper function when rendering user or database data in views.
- Adhere to the established PSR-4 naming rules (PascalCase for classes, camelCase for methods/variables, snake_case for global helper functions).

### 2. File Referencing
- In your explanations and thoughts, you **must** link to codebase files and classes using absolute paths with the `file://` scheme, for example: `[Router](file:///home/mbrotys/Web/projects/03_osobní/conscribe-php/app/Core/Router.php)`.

### 3. Roadmap and Verification Checklist
Framework tasks, versioning rules and status live in [docs/ROADMAP.md](../docs/ROADMAP.md). When you finish a roadmap task, tick it there and in [docs/tasks.html](../docs/tasks.html), and add a CHANGELOG entry.

Before submitting a change or completing a task, check off the relevant development checkpoints in [docs/website-checklist.md](../docs/website-checklist.md), specifically:
- **SEO**: Descriptives titles, meta-descriptions, and correct heading structures.
- **Security**: Strict input sanitization, CSRF tokens verified on forms, and correct cookies setup.
- **Performance**: High score targets on PageSpeed Insights and optimization of assets.
