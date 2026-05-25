# SMCP tool governance phase — foundational spec

**Status:** Draft · Phase 2 after wide plugin ship  
**Board:** Sanctum SMCP Platform (Tasks directory project)  
**Authors:** Mark Hopkins (product), Otto Vernal (engineering)  
**Related:** [Modality omnibus #299](https://tasks.decisionsciencecorp.com/admin/doc.php?id=299) · [Q job_rules #301](https://tasks.decisionsciencecorp.com/admin/doc.php?id=301) · Tasks plugin: `smcp_plugin/tasks/` (35 commands today)

---

## 0. Why this document exists

We shipped **full SDK parity** on the Tasks SMCP plugin (~35 tools) so nothing was blocked during Q Vernal rehearsal. That was the right **Phase 1** trade (#408–#409 in program docs).

**Phase 2** is **tool governance**: how agents *discover*, *attach*, and *use* tools without drowning in schema noise or admin surface area.

This doc captures:

1. The **tool overload** diagnosis (from Otto ↔ Mark discussion, 2026-05-23).
2. Mark’s **bigger idea**: a **Sanctum-wide SMCP convention** — master meta-tools (`tool-help`, attach/detach) on every SMCP server, aligned with how harnesses already work (especially Letta).
3. **Research** on attach/detach / enable-disable across major harnesses (correcting assumptions where needed).

---

## 1. Tool overload — honest diagnosis

### 1.1 What we shipped

| Item | Value |
|------|--------|
| SMCP tools (`tasks__*`) | **35** commands |
| Parameter slots (describe schema) | **~152** across commands |
| Heaviest commands | `update-task` (17 params), `list-tasks` (16), `create-task` (13) |
| Q (lettatest) | Attaches **all** `q_vernal_tasks__*` tools from MCP server |
| Narrowing today | **job_rules** prose only — does not remove tools from model schema |

### 1.2 What is actually “expensive”

- **Disk / RAM:** Not the problem. One Python CLI, one plugin directory.
- **Agent context:** **Yes.** Each MCP tool = name + description + parameters loaded into the harness (Letta agent, Cursor session, Claude Code, etc.) **before** the user speaks.
- **Decision quality:** **Yes.** More tools ⇒ more wrong-tool picks (e.g. admin `create-user` when chatter wanted `create-document`), more browsing, false “saved” claims without tool success.

### 1.3 Buckets (Tasks plugin today)

| Bucket | Examples | Q needs? | Otto needs? |
|--------|----------|----------|-------------|
| **Daily board** | create/update/get-task, search/list-tasks, comments, watchers, documents | Yes | Yes |
| **Bulk** | bulk-create/update-tasks | Rare | Sometimes |
| **Admin / IAM** | users, API keys, audit, orgs, reset-password | **No** | Rare |
| **Noise** | health | No | No |

### 1.4 Consolidation vs allowlist

| Approach | Verdict |
|----------|---------|
| **One mega-tool** (`action=create-task\|…`) | Fewer names, **more** wrong `action` / JSON — different failure mode |
| **Swiss-army by domain** (`tasks__task` + `operation`) | Moderate compression; still easy to botch `operation` |
| **Role-based allowlist** (same CLI, fewer registered tools) | **Best ROI** — keep SDK parity, trim **exposure** |
| **Otto’s `tool-help` idea** | Strong **companion** — intent → which tool(s) to attach/call |

**Conclusion:** Do **not** collapse the CLI for Q. **Do** split **implementation** (full CLI) from **exposure** (chatter profile ~12–15 tools) and add **meta-tools** for discovery/attachment.

---

## 2. Mark’s proposal — Sanctum SMCP meta-tool convention

### 2.1 Core insight

> MCP servers can **expose** many tools; harnesses can **attach** or **enable** only a subset.

- **Letta:** Register MCP server → list tools on server → **attach subset to agent** (`tool_ids`). Clear server vs agent distinction.
- **Cursor:** Connect MCP server → **toggle individual tools** in UI; ~**40 active tools** soft ceiling across all servers; `permissions.json` `mcpAllowlist` for auto-run.
- **Claude Code:** Load MCP servers → **allow / deny / ask** per tool via permission rules (`mcp__server__tool`); **Tool Search** defers tool defs until needed (different anti-overload strategy).

**Opportunity:** Define a **standard meta layer** that every Sanctum SMCP server implements so agents (and humans) speak one vocabulary: *available*, *attached*, *help*, *attach*, *detach* — regardless of harness.

### 2.2 Proposed convention (every Sanctum SMCP server)

Shipped from **`sanctumos/smcp`** core (or `smcp-meta` plugin loaded alongside product plugins):

| Meta tool | Purpose |
|-----------|---------|
| `sanctum__list-available-tools` | Full catalog this server exposes (from `--describe` or registry) |
| `sanctum__list-attached-tools` | What this **agent/session** currently has enabled (harness-specific backend) |
| `sanctum__attach-tool` | Attach one or more tools by name (Letta: API attach; Cursor: document toggles + user step; Claude: allow rule) |
| `sanctum__detach-tool` | Remove from active set |
| `sanctum__tool-help` | Natural-language intent → recommended tools + args sketch + “not attached? run attach” |

Product plugins (e.g. `tasks__create-task`) stay **narrow and verb-shaped**.

### 2.3 `tool-help` (Mark + Otto)

- Input: user goal (“save transcript to AuthLokr”, “what did John say on the call”).
- Output: ranked tool names, required ids from Layer B context, anti-patterns (documents ≠ tasks).
- Does **not** replace product tools — routes to them after attach.

### 2.4 Harness adapter layer

Meta-tools call a small **adapter interface** per harness:

| Harness | “Available” | “Attached” | Attach | Detach |
|---------|-------------|------------|--------|--------|
| **Letta** | `GET /v1/mcp-servers/{id}/tools` | Agent `tools` relation | `PATCH …/tools/attach/{id}` | Detach endpoint |
| **Cursor** | MCP `tools/list` | Settings / internal state | Enable tool in UI (may need user confirm) or config | Disable tool |
| **Claude Code** | MCP list | Effective permissions | `allow` rule / subagent `tools` list | `deny` / remove from allow |
| **Otto (Cursor)** | Same as Cursor | Same | Same | Same |

Where harness cannot programmatically attach (Cursor may require UI), meta-tool returns **actionable instructions** — still one vocabulary.

---

## 3. Harness research — attach / detach / subset (2026-05)

### 3.1 Letta — **yes, first-class attach/detach**

- Register MCP server (`stdio`, `streamable_http`, etc.).
- List tools on **server** (`/v1/mcp-servers/{id}/tools`).
- Create agent with **`tool_ids`** subset — not all server tools.
- Attach/detach per agent via API (Otto uses `PATCH /v1/agents/{id}/tools/attach/{tool_id}` for Q).

**Implication:** Letta is the **reference model** for Sanctum’s meta-tool semantics.

### 3.2 Cursor — **partial; UI-first, not agent API**

Sources: [Cursor MCP docs](https://cursor.com/docs/mcp), [permissions.json](https://cursor.com/docs/reference/permissions).

- **Server-level:** Enable/disable entire MCP server (toggle in Settings → Tools & MCP).
- **Tool-level:** Enable/disable **individual tools** from the same settings UI (“Available Tools”).
- **Limits:** Third-party guides cite ~**40 active tools** combined across servers before quality degrades; Cursor warns and may hide tools.
- **Auto-run:** `~/.cursor/permissions.json` → `mcpAllowlist` (tool names that may run without approval) — **not** the same as Letta attach, but a form of **subset selection**.
- **No public HTTP API** (as of this research) for Otto to attach tools to Mark’s Cursor session programmatically — meta-tool may return **human steps** or project `mcp.json` + settings guidance.

**Implication:** Same *concept* (subset of exposed tools), different *mechanism* (IDE UI + permissions file).

### 3.3 Claude Code — **permissions + tool search, not Letta-style attach**

Sources: [Claude Code MCP](https://code.claude.com/docs/en/mcp), [Permissions](https://code.claude.com/docs/en/permissions), [Tool search](https://code.claude.com/docs/en/agent-sdk/tool-search).

- MCP servers registered in settings; can enable/disable servers (`/mcp`, @mention — per changelog 2.0.10).
- **Subset control:** `permissions.allow` / `permissions.deny` / `permissions.ask` with rules like `mcp__puppeteer__puppeteer_navigate`.
- **Tool Search (default on):** Defers loading tool **definitions** until needed — attacks **context bloat** without shrinking server catalog.
- **`alwaysLoad`** on a server (config) — pin small toolsets always visible.
- Subagents: explicit `tools` list can include MCP tools (per-tool names, not server shorthand).

**Implication:** Claude optimizes overload via **deferred discovery + deny lists**, not Letta attach API. Sanctum meta-tools should map `attach` → **allow rule** / subagent tool list.

### 3.4 Cross-harness summary

| Capability | Letta | Cursor | Claude Code |
|------------|-------|--------|-------------|
| Server exposes N tools | Yes | Yes | Yes |
| Agent uses subset | Yes (`tool_ids`) | Yes (UI toggles) | Yes (allow/deny / tool search) |
| Programmatic attach API | **Yes** | **Limited / UI** | **Via permissions config** |
| Context overload mitigation | Attach subset | Toggle + ~40 cap | Tool Search + deny |
| Sanctum meta-tool value | Native mapping | Unified vocabulary + instructions | Unified vocabulary + config snippets |

### 3.5 Competitive advantage (honest framing)

**If** Sanctum ships **documented, tested meta-tools on every SMCP server**:

- **Letta agents** (Q, Ada, Athena paths) get consistent attach/detach/help **in-band** in conversation.
- **Cursor / Claude** users get the same **words** even when the harness needs a UI or config file step — less “this stack is different.”
- **Product plugins** stay thin (verbs, not god-tools).

**Caveat:** This is an advantage in **Sanctum’s ecosystem coherence**, not “other harnesses lack subsetting.” They subset differently. Our win is **standardization + tool-help + optional chatter profiles** across Tasks, Invoicing, CRM, etc.

**Risk:** Meta-tools themselves add 4–5 tools to every server — must keep meta schema tiny and cache-friendly.

---

## 4. Recommended phased delivery

### Phase 2a — Quick win (Tasks only)

1. **`describe-profile`: `chatter` | `admin` | `full`** in `smcp_plugin/tasks/cli.py` — filter `--describe` output.
2. **Q lettatest attach** only `chatter` profile (~12–15 tools).
3. **Otto Cursor MCP** — `full` or `admin` profile as needed.
4. **`tasks__tool-help`** (or `sanctum__tool-help` in core) — static routing table + link to job_rules.

### Phase 2b — SMCP core convention

1. Implement meta-tools in **`sanctumos/smcp`** (not duplicated per plugin).
2. **Harness adapters** (Letta client first; Cursor/Claude “instruction mode”).
3. Docs + modality doc § update.

### Phase 2c — Other product servers

- Invoicing, CRM, etc. — same meta layer, product-specific `tool-help` tables.

---

## 5. Open questions for Mark

1. **New directory project name** — “Sanctum SMCP Platform” OK for all SMCP work?
2. **Meta-tool prefix** — `sanctum__*` vs `smcp__*`?
3. **Cursor** — Accept “attach returns UI instructions” for v1, or invest in config-file writer (`permissions.json` / project MCP)?
4. **Q default profile** — confirm chatter tool list (§1.3 buckets).

---

## 6. References

- `sanctum-tasks/docs/MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md` (v1.1)
- `sanctum-tasks/docs/otto-smcp-cursor.md`
- `sanctum-tasks/tools/lettatest_attach_q_smcp.py` (attaches all tools today)
- Letta: [MCP tools guide](https://docs.letta.com/guides/core-concepts/tools/mcp-tools/)
- Cursor: [MCP](https://cursor.com/docs/mcp), [permissions.json](https://cursor.com/docs/reference/permissions)
- Claude Code: [MCP](https://code.claude.com/docs/en/mcp), [Tool search](https://code.claude.com/docs/en/agent-sdk/tool-search)

---

*End of foundational spec — update this doc as Phase 2 ships.*
