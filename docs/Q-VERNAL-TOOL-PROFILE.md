# Q Vernal — chatter SMCP tool profile (Phase 2)

**Status:** Locked for Phase 2 attach · **Replaces:** waived v1 #408 (attach-all)  
**Governance spec:** [Doc #310](https://tasks.decisionsciencecorp.com/admin/doc.php?id=310) §1.3 buckets  
**Plugin:** `smcp_plugin/q_vernal_tasks/` (prefix `q_vernal_tasks__*` on MCP wire)

---

## Plain-language summary

Q gets **16 board tools** — enough to read and write tasks, comments, and documents for projects the chatter can already see, plus **username lookup** when assigning work. Q does **not** get admin tools (user provisioning, API keys, audit), bulk mutators, or org provisioning. Full SDK stays in the CLI; this table is **exposure** for Letta attach and future `describe-profile chatter`.

---

## Profile: `chatter` (16 tools)

| # | MCP tool | Bucket | Why Q needs it |
|---|----------|--------|----------------|
| 1 | `q_vernal_tasks__create-task` | Daily board | Create work on visible projects |
| 2 | `q_vernal_tasks__update-task` | Daily board | Status, assignee, body, tags |
| 3 | `q_vernal_tasks__get-task` | Daily board | Read task + relations |
| 4 | `q_vernal_tasks__search-tasks` | Daily board | Find by title/body |
| 5 | `q_vernal_tasks__list-tasks` | Daily board | Board views, filters |
| 6 | `q_vernal_tasks__create-comment` | Daily board | Thread updates |
| 7 | `q_vernal_tasks__list-comments` | Daily board | Read discussion |
| 8 | `q_vernal_tasks__get-document` | Daily board | Long-form docs |
| 9 | `q_vernal_tasks__list-documents` | Daily board | Project library browse |
| 10 | `q_vernal_tasks__create-document` | Daily board | File new doc |
| 11 | `q_vernal_tasks__update-document` | Daily board | Edit doc body/title |
| 12 | `q_vernal_tasks__create-document-comment` | Daily board | Doc threads |
| 13 | `q_vernal_tasks__list-document-comments` | Daily board | Read doc threads |
| 14 | `q_vernal_tasks__list-directory-projects` | Daily board | Resolve `project_id` / names |
| 15 | `q_vernal_tasks__list-todo-lists` | Daily board | Required `list_id` for creates |
| 16 | `q_vernal_tasks__search-users` | Daily board | Resolve assignee username → `user_id` (prefix search; not full user admin) |

**API key:** Resolved server-side per chatter (`resolve_key.py`); model must never pass `--api-key`.

---

## Explicitly excluded from `chatter`

| Category | Tools (representative) | Reason |
|----------|------------------------|--------|
| **Admin / IAM** | `create-user`, `disable-user`, `reset-user-password`, `create-api-key`, `revoke-api-key`, `list-api-keys`, `list-users`, `list-audit-logs` | Chatter lane; escalation = human admin (`search-users` is **in** chatter for assignee lookup only) |
| **Bulk** | `bulk-create-tasks`, `bulk-update-tasks` | Blast radius; Otto/admin only |
| **Org / project admin** | `create-directory-project`, `update-directory-project`, `add-project-member`, `remove-project-member`, `list-project-members`, `list-organizations`, `create-status` | Directory provisioning |
| **Noise** | `health` | No user value in chat |
| **Attachments (v1 chatter)** | `upload-attachment`, `add-attachment`, `list-attachments` | Defer to Phase 2c or `attach-profile power` |
| **Watchers (optional)** | `watch-task`, `unwatch-task`, `list-watchers` | Add via `power` profile if needed |

Full catalog today: **49** commands (`tasks/cli.py --describe`). Chatter = **16** (~67% reduction in schema surface).

---

## Other profiles (reference — not Q default)

| Profile | Tool count (approx) | Use |
|---------|---------------------|-----|
| `full` | 49 | Otto Cursor / break-glass |
| `admin` | `full` minus `health` | Staff with IAM needs |
| `power` | `chatter` + attachments + watchers | Heavy collaborators |

Phase 2a delivers `describe-profile` filtering in `tasks/cli.py`; Q moya re-attach uses **`chatter`** only ([#681](https://tasks.decisionsciencecorp.com/admin/view.php?id=681)).

---

## Letta attach checklist (moya)

1. MCP server: `q_vernal_tasks` (stdio or HTTP per Broca plugin).
2. Attach **only** the 16 tools above (+ future `sanctum__tools` governor when Phase 2b ships).
3. Verify with `GET /v1/agents/{q_id}/tools` — no `create-user`, `list-audit-logs`, etc.
4. Smoke: chatter asks to create a task on a **member-visible** project → succeeds; admin-only project → ACL error.

---

## Cross-links

- Plan: [Doc #296](https://tasks.decisionsciencecorp.com/admin/doc.php?id=296)
- UX audit (reload gate): [Doc #297](https://tasks.decisionsciencecorp.com/admin/doc.php?id=297)
- Phase 2 tracker: [Task #671](https://tasks.decisionsciencecorp.com/admin/view.php?id=671)
- SMCP Phase 2: [Task #472](https://tasks.decisionsciencecorp.com/admin/view.php?id=472)
