# Conscribe PHP – agent context

Conscribe PHP is a small PHP 8.4+ MVC framework (version in `App\Bootstrap::VERSION`).
Client sites (shopette, supportgroup) are built on it. The core stays small and
readable, but it is not dependency-free: a feature may pull in a focused Composer package
(Monolog, Tracy, PHPMailer, scssphp, minify, psr/container today).

| Document | What it covers |
|---|---|
| [structure.md](structure.md) | directory layout, request lifecycle, core classes |
| [workflows.md](workflows.md) | coding conventions, security rules, how to add routes / views / repositories / modules / commands |
| [tech-stack.md](tech-stack.md) | runtime, dependencies, Docker, configuration files |
| [../docs/ROADMAP.md](../docs/ROADMAP.md) | versioning rules, status, every open and finished framework task |
| [../CHANGELOG.md](../CHANGELOG.md) | what each release changed |
| [../README.md](../README.md) | full English reference; [../docs/NAVOD.md](../docs/NAVOD.md) is the Czech guide |

## Rules for agents

- **Branches:** work goes to `dev`; `master` holds released versions only (merged `--no-ff`
  and tagged `X.Y.Z`). Do not push or tag without the maintainer's approval.
- **Roadmap:** when a roadmap task is finished, tick it in `docs/ROADMAP.md` and
  `docs/tasks.html`, and add an entry under `## Unreleased` in `CHANGELOG.md`.
- **Backward compatibility:** read *Versioning rules* in the roadmap before changing anything
  public (classes in `app/Core`, `app/Services`, modules, helpers, config keys, `tty`
  commands). Nothing public is removed outside a major; deprecate first.
- **Checks:** `composer check` (lint, PHPStan, PHPUnit) must pass; `composer fix:check` for
  style. Every change ships with tests.
- **Views:** escape every value with `esc()`; never echo raw input.
- **Links:** refer to files with repo-relative paths (`app/Core/Http/Router.php`), never
  absolute machine paths.
- Keep existing comments and docblocks unless the change makes them wrong.
- Client-site work (not framework work) follows
  [../docs/website-checklist.md](../docs/website-checklist.md).
