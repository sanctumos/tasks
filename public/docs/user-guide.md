# Sanctum Tasks — user guide

This guide matches the current admin experience at a glance. For HTTP endpoints and JSON contracts, see `docs/api.md` and `docs/api-authorization-and-product-notes.md` in this repository.

---

## Overview

**Sanctum Tasks** is a task system with a **browser admin UI** (`/admin/`) and a **JSON API** (`/api/`). Tasks live in **workspace projects** (directory projects). Each task belongs to a **project** and a **to-do list** inside that project. You can work from the **Home** page (projects first, then an all-project board), open a **single project**, or open an individual **task**.

Typical roles: **admin** (users, keys, audit), **member** / **manager** (day-to-day tasks), **api** (automation keys). Exact privileges depend on role and project membership—see **Users organizations and access** below.

---

## Home and cross-project tasks

**Home** (`/admin/`) has two stacked areas:

1. **Your projects** — Cards for every workspace project you can access (same directory as **Projects** in the nav). Click a card to open that project (defaults to **Lists**).

2. **All tasks across projects** — The familiar **Board** and **List** views with filters. This is a **master** view: tasks from all projects you can see, not a single project.

Use **Board** / **List** to switch layout. **New task** still picks a **project** and **to-do list** (tasks must belong to a list). Filters (status, assignee, priority, project name, search) apply to this combined set.

---

## Projects and workspace

**Projects** in the nav (`/admin/workspace-projects.php`) lists directory projects and lets you **create** a project. Each project has a **status** (e.g. active, archived), optional description, and flags such as **client-visible** or **all-access** when your org uses them.

Opening a project (`/admin/project.php?id=…`) gives you tabs:

| Tab | Purpose |
|-----|--------|
| **Lists** | Basecamp-style **to-do lists** with tasks grouped under each list; add lists; add tasks inline; toggle done from the checkbox. |
| **Tasks** | Alternate task views for the project (board/list-style task handling depending on implementation). |
| **Docs** | Long-form **markdown documents** scoped to this project (not the same as this user guide). |
| **Members** | Who belongs to the project. |
| **Settings** | Project settings (if you have permission). |

Your default landing tab for a project is **Lists** so you can work list-by-task quickly.

---

## Lists and to-do lists

**To-do lists** belong to a project. **Every task must have a `list_id`**—there are no permanent “orphan” tasks in normal create flows.

On the **Lists** tab, add tasks with **Add a to-do…**, change status with the **checkbox** (done vs todo), and use **+ Add list** for a new list. Creating a task from the master **Home** flow still requires choosing **project** and **list** in the form.

---

## Task detail page

The task page (`/admin/view.php?id=…`) has:

- **Title** and **description** (markdown).
- **Discussion** (comments, markdown, **@mentions**—see below).
- **Images & attachments** — upload images or copy **markdown snippets** into the description or comments.
- **Right rail**: status, priority, assignee, due date, **project**, **list**, tags, rank, **recurrence** (RRULE builder modal), **watchers**.

**Watch** subscribes you to the task. **Delete** removes the task (and locally stored image files for that task, when applicable).

---

## Documents (per project)

**Docs** (project tab → `docs.php` / document editor) are **markdown documents** attached to a **project**, separate from task bodies. Use them for specs, narratives, or long references. The API can create/update documents under a `project_id`; in the admin UI, open the project’s **Docs** tab to list and edit.

---

## Images and attachments

**Remote links:** Register a URL against a task via the API (`add-attachment`).

**Uploads:** Image files (PNG, JPEG, GIF, WebP) can be stored on the server up to the configured size limit. After upload, use **Copy snippet** or **Paste into description** / **Paste into comment** so the image appears **inline** in markdown.

Images are **not** public hotlinks by default: they are served through a **task-aware** asset URL so only people who may view the task can fetch the file.

---

## Mentions and markdown

Use **`@username`** in descriptions and comments. When you type `@`, a **suggest list** of users appears; pick one to insert. Rendered pages link mentions for visibility.

Markdown (Parsedown, safe mode) applies to task **body**, **comments**, and **documents**. Password policy and other **admin** strings are plain text.

---

## Search and filters

On **Home**, the filter bar searches **titles and bodies**, and narrows by **status**, **priority**, **assignee**, and **project name** (string). **More** exposes sort order. Project-specific screens add their own controls (e.g. project-scoped lists).

---

## Users organizations and access

**Users** (`/admin/users.php`, admin-only): create users, disable/enable, set **organization** and multi-org membership where enabled, **reset password** (with password policy feedback), and scope **non-admin** users to assigned projects when **Limit to assigned projects** applies.

**Organizations** (`/admin/organizations.php`) configure org boundaries when the deployment uses them.

**Clients vs team:** **Person kind** (e.g. client) and **client-visible** projects affect what directory projects and tasks a user may see; details follow your deployment’s rules—see `docs/api-authorization-and-product-notes.md`.

---

## Settings password MFA and API keys

**Settings** (`/admin/settings.php`) tabs:

- **Password** — Change your own password; policy requires minimum length and mixed character classes.
- **MFA** — Optional TOTP for your account.
- **API keys** — (Admin) Create and revoke keys for integrations.
- **Audit** — (Admin) Review security-relevant events.

Session versus API: the web UI uses **cookies**; automation uses **API keys** in headers—see **API SDK and integrations**.

---

## API SDK and integrations

- **API reference:** `docs/api.md` — routes, bodies, auth headers (`X-API-Key` / `Authorization: Bearer`).
- **Auth product notes:** `docs/api-authorization-and-product-notes.md`.
- **Python SDK:** `tasks_sdk/` (repository root).
- **SMCP / MCP:** `smcp_plugin/tasks/` and `docs/integrations.md`.
- **Workflows:** `docs/WORKFLOWS.md` for agent vs human patterns.

Rate limits and error JSON schemas are documented in `docs/api.md`. Use **`/api/health.php`** only as documented for operators—there is no unauthenticated “ping” beyond that contract.

---

## Document history

This file is maintained with the product. If the UI and this guide diverge, prefer the shipped PHP and `docs/api.md` as source of truth for behavior; update this guide in the same change when possible.
