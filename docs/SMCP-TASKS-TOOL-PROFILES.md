# Tasks SMCP tool profiles

**Canonical exposure tables for `tasks__*` / `q_vernal_tasks__*` MCP tools.**  
**Governance:** [Doc #310](https://tasks.decisionsciencecorp.com/admin/doc.php?id=310) · **Q chatter table:** [Q-VERNAL-TOOL-PROFILE.md](Q-VERNAL-TOOL-PROFILE.md)

---

## Profiles

| Profile | Commands exposed | Typical harness |
|---------|------------------|-----------------|
| **`chatter`** | 15 daily board verbs | Q Vernal Ask Q, Otto Cursor default |
| **`admin`** | chatter + bulk, attachments, watchers, pins, tags, statuses | Otto power-user / internal ops |
| **`full`** | All API-key routes minus `health` noise | Dev / migration / attach-all rehearsal |

Source of truth in code: `smcp_plugin/tasks/tool_profiles.py` (`PROFILES`).

---

## `chatter` (15)

| Command | API route |
|---------|-----------|
| `create-task` | `POST /api/create-task.php` |
| `update-task` | `POST /api/update-task.php` |
| `get-task` | `GET /api/get-task.php` |
| `search-tasks` | `GET /api/search-tasks.php` |
| `list-tasks` | `GET /api/list-tasks.php` |
| `create-comment` | `POST /api/create-comment.php` |
| `list-comments` | `GET /api/list-comments.php` |
| `get-document` | `GET /api/get-document.php` |
| `list-documents` | `GET /api/list-documents.php` |
| `create-document` | `POST /api/create-document.php` |
| `update-document` | `POST /api/update-document.php` |
| `create-document-comment` | `POST /api/create-document-comment.php` |
| `list-document-comments` | `GET /api/list-document-comments.php` |
| `list-directory-projects` | `GET /api/list-directory-projects.php` |
| `list-todo-lists` | `GET /api/list-todo-lists.php` |

---

## `admin` extras (beyond chatter)

`bulk-create-tasks`, `bulk-update-tasks`, `list-attachments`, `upload-attachment`, `add-attachment`, `watch-task`, `unwatch-task`, `list-watchers`, `list-project-members`, `list-project-pins`, `set-project-pin`, `list-tags`, `list-statuses`, `get-directory-project`, `create-todo-list`, plus **admin-only** IAM/org routes (`create-user`, `list-users`, `create-api-key`, …).

---

## CLI usage

```bash
# Filtered schema for MCP attach / Letta registration
python3 smcp_plugin/tasks/cli.py --describe --describe-profile chatter

# Intent routing (no API key)
python3 smcp_plugin/tasks/cli.py tool-help "save document" --profile chatter
```

---

## SMCP core governor

When `SMCP_ATTACH_PROFILE=chatter` is set at server boot (`sanctumos/smcp`), `tools/list` returns only attached tools plus `sanctum__tools`. Detached calls return structured `tool_not_attached` with attach hint.

See `governor.py` in **sanctumos/smcp** and [otto-smcp-cursor.md](otto-smcp-cursor.md).
