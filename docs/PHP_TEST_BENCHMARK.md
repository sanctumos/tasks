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

## Measured snapshot (2026-09-30, `dev` branch — Search S3 complete)

| Category | Result |
| -------- | ------ |
| **Unit** (PCOV, scoped includes) | **90.23%** lines (4158/4608) |
| **Integration** (PHPUnit HTTP suite) | **100%** green (16/16) |
| **E2E** (checklist + Playwright W17–W20 + members/ACL) | **100%**; board/lists/members/ACL screenshots inspected |

Commits on `dev` (S3): `e0479a7`…`84b05ca`. W20 + `st-filter.js`. No `main` merge / Ada sync.

## Measured snapshot (2026-09-30, `dev` branch — Search S2 complete)

| Category | Result |
| -------- | ------ |
| **Unit** (PCOV, scoped includes) | **90.23%** lines (4158/4608) |
| **Integration** (PHPUnit HTTP suite) | **100%** green (15/15) |
| **E2E** (checklist + Playwright W17–W19) | **100%**; omnibox + docs search + Home live filter screenshots inspected |

Commits on `dev` (S2): `1a35465`…`07b713f` (on top of S1). W19 wired. No `main` merge / Ada sync.

## Prior snapshot (2026-09-30 — Search S1 complete)

| Category | Result |
| -------- | ------ |
| **Unit** (PCOV, scoped includes) | **90.20%** lines (4112/4559) |
| **Integration** (PHPUnit HTTP suite) | **100%** green (13/13) |
| **E2E** (checklist + Playwright W17–W18) | **100%**; omnibox + docs search screenshots inspected |

Commits on `dev` (S1): `d24f65a`…`aa51920`. A11 + W17 + W18 wired.

## CI

GitHub Actions runs PHP syntax lint, Composer install, and PHPUnit (unit + integration). Keep **composer.lock** committed so CI and production-like installs stay aligned.
