# Roadmap stub: inline images and uploaded assets

**Status:** stub only (2026-05-06). Not scheduled work—captures intent so we do not lose the thread.

## Problem today

- `task_attachments` stores **metadata only** (`file_name`, `file_url`, …). The API registers an **external** HTTPS URL; nothing uploads bytes into Tasks.
- Task bodies, comments, and Docs use markdown (Parsedown). Inline images require stable `![](url)` targets; today there is **no first-party upload → hosted URL** path under the app.

## Goal

Users can attach screenshots and other binaries and reference them **inline** in markdown (tasks, comments, documents) using URLs served by Tasks (or signed URLs), with sane limits and access control aligned to project/list visibility.

## Likely building blocks (when implemented)

1. **Storage:** filesystem under a non-public directory (or future object storage), keyed by org/project/task or asset id; never serve arbitrary paths from user input.
2. **Upload API:** authenticated `multipart/form-data` (and/or admin UI), MIME allowlist (e.g. `image/png`, `image/jpeg`, `image/gif`, `image/webp`), max size, optional virus scanning hook.
3. **Serving:** dedicated endpoint e.g. `/assets/<id>` or `/api/get-asset.php?id=` with session or token checks matching who may see the parent task/document; correct `Content-Type` and caching headers.
4. **Markdown:** after upload, return a canonical URL or markdown snippet for paste; optional composer button “Insert image.” Ensure Parsedown / HTML sanitization stays safe for `<img>` (no javascript: URLs, etc.).
5. **Audit / lifecycle:** optional link from `task_attachments` or new `media_assets` table; delete when parent deleted or GC orphaned uploads.

## Testing and rollout

- PHPUnit / integration tests for upload authz, forbidden cross-org access, and MIME rejection.
- Playwright smoke for “upload → image visible in rendered markdown” when UI exists.

## Prerequisite product change (do before or with this)

**Require every task to belong to a todo list** (`list_id` non-null), the same way directory work is anchored to a **project**. Today `list_id` is optional; that invites orphan tasks and poor board UX. Enforcement implies: create/edit validation (API + admin), default list selection (e.g. first list in project or explicit “Inbox” list), and a **data migration** for existing rows with `list_id IS NULL` (backfill or force assignment per project). Tackle that slice **before** or **in the same release train** as inline assets so new uploads stay scoped to a clear list/project hierarchy.
