# PHP test benchmark (production surface)

Target for **sanctum-tasks** PHP + browser verification:

| Category | Target | How we measure it |
| -------- | ------ | ----------------- |
| **Unit** | **≥ 90%** line coverage on core `public/includes` domain logic | PHPUnit + **PCOV** (`composer run test:php:coverage`). Excludes: `lib/` (Parsedown), `config.php` (bootstrap), `api_auth.php` (HTTP exit wrappers — Integration), `auth.php` (session redirect/CSRF die — E2E), `skin-lab-env.php`, `doc_guide.php`. Admin UI covered by Playwright. |
| **Integration** | **≥ 90%** of **critical API flows** | Checklist in [`CRITICAL_API_FLOWS.md`](CRITICAL_API_FLOWS.md); enforced by `CriticalApiFlowsChecklistTest`. Behavioral HTTP tests under `tests/php/Integration/`. |
| **End-to-end** | **≥ 90%** of **required major workflows** | Checklist in [`MAJOR_WORKFLOWS.md`](MAJOR_WORKFLOWS.md); enforced by `MajorWorkflowsChecklistTest`. Playwright scripts in `tools/design-smoke/`. |

## Commands

```bash
composer install
composer run test:php:unit
composer run test:php:integration
composer run test:php:e2e
composer run test:php:coverage
```

Coverage (PCOV) — Unit line % on includes:

```bash
php -d pcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text
```

## Measured snapshot (2026-07-23, `dev` branch)

| Category | Result |
| -------- | ------ |
| **Unit** (PCOV, scoped includes) | **90.03%** lines (3583/3980) |
| **Integration** (critical API flows) | **100%** of checklist (10/10) |
| **E2E** (required major workflows) | **100%** of required scripts (13/13); board export Playwright green on `dev.tasks` |

Board export module: **91.7%** unit lines; Integration `BoardExportHttpTest` covers request/list/download/unchanged reuse.

## CI

GitHub Actions runs PHP syntax lint, Composer install, and PHPUnit (unit + integration). Keep **composer.lock** committed so CI and production-like installs stay aligned.
