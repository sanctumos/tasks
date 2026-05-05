# Task authorization, operations, and product notes

Companion to [`api.md`](api.md). Describes **why** API responses look the way they do when roles differ, and records **design choices** (not bugs) for product discussion.

---

## Task visibility (`userCanAccessTask` / `userCanAccessTaskForViewer`)

Implemented in `public/includes/functions.php`. The API uses the same rules as the PHP UI for single-task reads and mutating endpoints that check access.

### Who sees everything in an org (directory “staff”)

Users with **unrestricted directory access** (`userHasUnrestrictedOrgDirectoryAccess`): typically **admin**, and **manager** when **not** subject to “limit to assigned projects.” They can see tasks linked to any directory project they can access, plus tasks they created, are assigned to, or watch (redundant but consistent).

### Everyone else (members, limited managers, clients, API keys tied to those users)

A user may read a task if **any** of the following hold:

1. **Linked directory project** — `task.project_id` points to a project and `userCanAccessDirectoryProject(viewer, project)` is true (org membership, `all_access`, `project_members`, client visibility rules, etc.).
2. **Creator** — `task.created_by_user_id` equals the viewer’s user id.
3. **Assignee** — `task.assigned_to_user_id` equals the viewer’s user id.
4. **Watcher** — a row exists in `task_watchers` for `(task_id, viewer_user_id)`.

If none apply, the user **must not** see the task in listings and **must not** fetch it by id.

### HTTP semantics: 404 for “not allowed”

Many endpoints return **`404`** with a generic “not found” style message when the task exists but **the caller’s key is not allowed** to see it — same as a missing id. This avoids leaking whether a task id exists to unauthorized callers.

**Implication:** Integrations should not assume “404 means bad id”; it can mean **forbidden for this API identity**.

---

## Operational pattern: service accounts, Otto keys, and “tasks for the client”

If automation (e.g. an agent API key) **creates** tasks “for the client” but:

- does **not** set **`assigned_to_user_id`** to the human who should own the work, and  
- does **not** link the task to a **`project_id`** the human can access under directory rules, and  
- the human is **not** creator, assignee, or watcher,

then **that human’s** `list-tasks` / `get-task` view **will not** match the automation’s view. Roles (admin/manager vs member) widen or narrow what directory-linked tasks include.

**Practical patterns to align views:**

| Goal | Pattern |
| ---- | ------- |
| Human should track work in their queue | Set **`assigned_to_user_id`** to them, or add them as **watcher**. |
| Work belongs to a client-facing project | Set **`project_id`** (and org/project ACL) so their user passes `userCanAccessDirectoryProject`. |
| Dedicated machine user | Create a named **`api`** or **`member`** user whose key is used only for that pipeline; accept that only that identity sees tasks unless you assign/link as above. |

Agreeing one pattern per integration avoids “Otto sees it, Mark doesn’t” surprises.

---

## Product-level behavior (design, not defects)

### 1. Client visibility / “portal”

There is **no** separate anonymous or public read-token mode in the reviewed surface. Visibility is **always** tied to an authenticated **user** (session or API key) and the ACL above.

**Open product question:** Should “clients watch work as it goes” mean:

- **Per-client login** (recommended today): `person_kind=client`, directory **`client_visible`**, project membership — all already modeled; or  
- **Shared credentials** (same login for a team — operational risk); or  
- **Future:** read-only portal tokens, magic links, etc.

Today’s answer in code: **give them a user** (or use assignee/project/watchers so their existing user sees tasks).

### 2. Audit log API

`GET /api/list-audit-logs.php` is **admin-only** and supports **pagination** (`limit` / `offset` as implemented). There are **no** filters for actor, task id, or action type in the reference implementation.

Fine at low volume; for heavy forensic use you would extend the endpoint or export from the DB offline.

### 3. Attachments

The API stores **metadata** (`file_url`, name, optional mime/size) — **not** multipart uploads to Sanctum Tasks. Suitable when files live in **Git, CMS, or another blob store** and Tasks holds the link. Not a generic document vault.

---

## Python SDK (`tasks_sdk`) notes

### Full JSON envelope vs normalized objects

Methods such as `create_user()` return whatever `_request()` parses — the **full** API JSON (`success`, `data`, `user`, top-level mirrors). They do **not** return only the inner `user` dict.

**Typical unwrap:**

```python
raw = client.create_user("alice", "…", role="member")
user = raw.get("data", {}).get("user") or raw.get("user")
api_key = raw.get("data", {}).get("api_key") or raw.get("api_key")
```

Other methods sometimes pull a single field (e.g. `list_users` returns `users` only); check each method or the API doc.

### `disable_user(user_id, is_active=False)`

The name suggests “disable only,” but the HTTP API implements **toggle**: `is_active=False` disables, **`is_active=True` re-enables**. The client passes through to `disable-user.php` as documented in [`api.md`](api.md).

Prefer reading the parameter as **“desired active state”** rather than “disable flag.”

---

## Related code

- `userCanAccessTaskForViewer` / `userCanAccessTask` — `public/includes/functions.php`
- `listTasks(..., $apiUser)` — scopes listings for the authenticated API user
- `GET /api/get-task.php`, task mutations — deny with 404 when access fails
