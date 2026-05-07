# Sanctum Tasks

![Sanctum Tasks logo](docs/logo.png)

Sanctum Tasks is an API-first task management system with a Bootstrap admin UI, API keys, role-labeled user management, and automation-friendly SDK/plugin integrations.

This repository is a fork of a prior task management application. The codebase was a full tasks app before the fork; this repo continues that application under the Sanctum project.

## Licensing

- **All code** in this repository is licensed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**. See [LICENSE](LICENSE) for the full text.
- **All other content** (documentation, images, and any non-code material) is licensed under **Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0)**. See [LICENSE-DOCS](LICENSE-DOCS) for details and links to the full license.

## What the app does (user-facing)

### Core workflows

1. **Admin login**
   - Sign in at `/admin/login.php`
   - Supports optional MFA (TOTP) and account lockout after repeated failures
   - Users can be required to rotate password on first login/reset

2. **Task operations**
   - Create, update, view, and delete tasks from `/admin/`
   - Filter/search/sort by status, assignee, priority, project, due date, and free-text query
   - Task model includes richer metadata (see below)

3. **User and access management**
   - `/admin/users.php` supports create/disable/reset-password
   - `/admin/api-keys.php` supports API key lifecycle for integrations
   - `/admin/mfa.php` supports per-user TOTP MFA setup/disable

4. **Automation + integrations**
   - HTTP JSON API under `/api/*`
   - Python SDK under `tasks_sdk/`
   - SMCP plugin under `smcp_plugin/tasks/`

## Help & documentation

- **In-app user guide:** After signing in, open **Help** in the admin chrome or go to `/admin/documentation.php`. The source is `public/docs/user-guide.md` (product behavior and UI concepts; lives under `public/` so deployments that only ship the web tree still have it).
- **API & operators:** See `docs/api.md` and related files under `docs/`.

## Role model

| Role | Purpose | Typical permissions (current implementation) |
|---|---|---|
| `admin` | Full system administration | Manage users, keys, statuses, audits |
| `manager` | Operational management | Treated as admin-equivalent for most admin checks |
| `member` | Standard user | Authenticated task/API usage; not strict task ownership isolation |
| `api` | API-oriented service identity | API use via key, subject to endpoint-level auth checks |

Audit events are captured in `audit_logs` for security-relevant/admin operations.

Current authorization boundaries are coarse-grained: admin-sensitive routes enforce admin/manager checks, but there is not yet a comprehensive task-level ownership/ABAC policy across all task endpoints. Refer to [docs/security.md](docs/security.md) and [docs/api.md](docs/api.md) for endpoint behavior details.

## Task model

Task records support:

- Identity & ownership: `id`, `created_by_user_id`, `assigned_to_user_id`
- Content: `title`, `body`
- Workflow: `status` (customizable via `task_statuses`)
- Scheduling: `due_at`, `recurrence_rule`
- Prioritization: `priority`, `rank`
- Organization: `project`, `tags`
- Collaboration:
  - comments (`task_comments`)
  - attachments metadata (`task_attachments`)
  - watchers/subscribers (`task_watchers`)
- Timestamps: `created_at`, `updated_at`

## API overview

- Auth: `X-API-Key: <token>` or `Authorization: Bearer <token>`
- Rate limits: response headers (`X-RateLimit-*`) + `429` on limit exceeded
- Error schema:
  - legacy key: `error`
  - stable object: `error_object = { code, message, details }`
- Pagination metadata/links included on list/search endpoints

Full endpoint reference: [docs/api.md](docs/api.md)

## Repository structure

- `public/` - web-accessible PHP/UI/API code
- `db/` - SQLite database files and runtime secrets (ignored in git)
- `docs/` - architecture, API, integration, and operational docs
- `tasks_sdk/` - Python SDK (HTTP client for this API)
- `smcp_plugin/tasks/` - SMCP CLI plugin

## FastAPI mirror (Python API)

The **FastAPI** service that mirrors this PHP API (`/api/*.php` contract + shared SQLite semantics) ships in its own repo for faster iteration:

- **`https://github.com/sanctumos/py-tasks`** — `api_python/` + pytest. Use the same env vars (`TASKS_DB_PATH`, bootstrap keys, etc.) and swap only the HTTP base URL in clients.

## Local development quick start

```bash
# 1) Install PHP and Python dependencies as needed (system package manager)
# 2) Copy optional secret template (recommended)
cp public/includes/secrets.php.example public/includes/secrets.php

# 3) Start local PHP server from repo root
php -S 127.0.0.1:8080 -t public

# 4) Open admin UI
# http://127.0.0.1:8080/admin/login.php
```

Bootstrap credentials/key are created at first run:

- Admin username defaults to `admin` unless overridden by env
- Admin password is either:
  - `TASKS_BOOTSTRAP_ADMIN_PASSWORD`, or
  - generated and stored in `db/bootstrap_admin_password.txt`
- Bootstrap API key is either:
  - `TASKS_BOOTSTRAP_API_KEY`, or
  - generated and stored in `db/api_key.txt`
- Optional canonical app origin for absolute pagination links:
  - `TASKS_APP_BASE_URL` (for example `https://tasks.example.com`)

## Documentation index

- Basecamp-shaped roadmap (domain-first, post–UI experiment rollback): [docs/BASECAMP3_DOMAIN_PLAN.md](docs/BASECAMP3_DOMAIN_PLAN.md)
- Earlier overlay + gap analysis (still useful for surface inventory): [docs/BASECAMP3_UX_OVERLAY_PLAN.md](docs/BASECAMP3_UX_OVERLAY_PLAN.md)
- API: [docs/api.md](docs/api.md)
- Development, schema/migrations, backups, CI/testing: [docs/development.md](docs/development.md)
- Integration walkthrough (admin -> API key -> SDK/plugin): [docs/integrations.md](docs/integrations.md)
- Workflows (agent-only, hybrid, human-only): [docs/WORKFLOWS.md](docs/WORKFLOWS.md)
- Heartbeat (open-claw pattern using Tasks): [docs/HEARTBEAT.md](docs/HEARTBEAT.md)
- Heartbeat setup wizard (context and spec): [docs/HEARTBEAT_WIZARD_CONTEXT.md](docs/HEARTBEAT_WIZARD_CONTEXT.md) — run `./scripts/setup_heartbeat.sh` for an interactive bash wizard that writes a runner and optional cron.
- Deployment structure: [docs/github-repository-setup-guide.md](docs/github-repository-setup-guide.md)
