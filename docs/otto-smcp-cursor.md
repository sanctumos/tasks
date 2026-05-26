# Otto local SMCP (stdio) for DSC Tasks

**Purpose:** Cursor (and other MCP clients) call **Sanctum Tasks** through the same **SMCP** stack Q uses on lettatest — without re-reading `docs/api.md` every turn.

This is **SMCP** ([sanctumos/smcp](https://github.com/sanctumos/smcp)), not a separate MCP product. Tools surface as `tasks__create-task`, `tasks__list-documents`, etc.

**Phase 2b (tool governance):** After attach registry ships, run the **Cursor verification checklist** in [`SMCP-TOOL-GOVERNANCE-PHASE.md` §5](SMCP-TOOL-GOVERNANCE-PHASE.md) (Tasks doc [#310](https://tasks.decisionsciencecorp.com/admin/doc.php?id=310)) before changing Otto’s default profile from `full` → `chatter`.

## Layout (what lives where)

| Path | Role |
|------|------|
| **`~/projects/smcp`** | Git clone of **sanctumos/smcp** (server + `smcp_stdio.py`) — **not** inside `sanctum-tasks` |
| **`~/projects/sanctum-tasks/smcp_plugin/tasks`** | Tasks plugin CLI (API parity with `tasks_sdk`) — stays in the Tasks repo |
| **`~/.otto-local/smcp-runtime`** | Venv, `plugins/tasks` → symlink to plugin, `run-otto-smcp-stdio.sh` |

Secrets: **`~/.ssh/tasks-dsc-ottovernal.pass`** loaded **at launch** only (`TASKS_SMCP_API_KEY`). Nothing committed.

## One-time install

```bash
bash ~/projects/sanctum-tasks/tools/install-otto-smcp-runtime.sh
```

Clones/updates `projects/smcp`, creates `~/.otto-local/smcp-runtime/.venv`, symlinks the Tasks plugin.

## Cursor MCP config

Add to **`~/.cursor/mcp.json`** (user-level):

```json
{
  "mcpServers": {
    "sanctum-tasks": {
      "command": "/root/.otto-local/smcp-runtime/run-otto-smcp-stdio.sh",
      "args": []
    }
  }
}
```

Adjust the path if your home directory differs. **Reload Cursor** after saving.

## Manual test

```bash
# Plugin schema (should list create-task, get-document, …)
set -a && . ~/.ssh/tasks-dsc-ottovernal.pass && set +a
export TASKS_SMCP_API_KEY="$TASKS_DSC_OTTOVERNAL_API_KEY"
export TASKS_API_BASE_URL="${TASKS_DSC_BASE_URL%/}"
export PYTHONPATH=~/projects/sanctum-tasks:~/projects/sanctum-tasks/smcp_plugin
~/projects/sanctum-tasks/smcp_plugin/tasks/cli.py health

# Stdio server (blocks — normal; Cursor attaches as client)
~/.otto-local/smcp-runtime/run-otto-smcp-stdio.sh
```

## Q Vernal vs Otto

| | **Otto (this doc)** | **Q on lettatest** |
|--|---------------------|-------------------|
| Plugin | `tasks` | `q_vernal_tasks` (wraps tasks + per-chatter key) |
| API key | Otto pass file → `TASKS_SMCP_API_KEY` | Bridge `resolve_user_key` for chatter |
| Transport | Cursor MCP stdio | Letta + broca-q poll |

## Workspace rules still apply

MCP does not replace **`sanctum-tasks-dsc.mdc`**: assignees, `project_id` / `list_id`, comment length, discussion-task hygiene, API-first prod behavior.

## References

- Tasks integration overview: `docs/integrations.md`
- Embedded agent modality (Q / bridge): `docs/MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md`
- SMCP upstream: `~/projects/smcp/README.md`
- Pass file vars: `.cursor/rules/credential-pass-files.mdc` → `tasks-dsc-ottovernal.pass`

## Portable bundle (optional)

To sync runtime paths across machines, add `env.paths` + wrapper script to **otto-portable**; keep pass files out of the bundle.
