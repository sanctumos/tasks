# Roadmap stub: inline images and uploaded assets

**Status:** decisions locked for implementation (updated 2026-05-07).

## Problem today

- `task_attachments` stores **metadata only** (`file_name`, `file_url`, …). The API registers an **external** HTTPS URL; nothing uploads bytes into Tasks.
- Task bodies, comments, and Docs use markdown (Parsedown). Inline images require stable `![](url)` targets; today there is **no first-party upload → hosted URL** path under the app.

## Goal

Users can attach screenshots and other binaries and reference them **inline** in markdown (tasks, comments, documents) using URLs served by Tasks (or signed URLs), with sane limits and access control aligned to project/list visibility.

## Locked implementation decisions (2026-05-07)

1. **Storage:** use local **filesystem** in v1/v2 under a **non-public** directory in the app tree.
2. **Serving/auth:** use a **Tasks-controlled endpoint** (`/api/get-asset.php?id=`) with normal task access checks; no public direct-path serving.
3. **Schema:** extend existing **`task_attachments`** rather than introducing a new `media_assets` table right now.
4. **Lifecycle:** **strict parent delete** (task deletion removes its uploaded files; no orphan retention policy).
5. **Upload UX:** return both canonical URL and ready-to-paste markdown snippet after upload.

## Testing and rollout

- PHPUnit / integration tests for upload authz, forbidden cross-org access, and MIME rejection.
- Playwright smoke for “upload → image visible in rendered markdown” when UI exists.

## Prerequisite product change (status)

`list_id` enforcement is already in place in current Tasks codepaths (create/update validation + backfill migration). Inline assets should stay scoped to task/list/project access rules; no separate prerequisite release is needed.
