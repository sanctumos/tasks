# Software modality: embedded agent chat inside a Sanctum web app

**Document type:** Omnibus pattern template (not a visual design spec)  
**Canonical title:** Embedded agent chat — pull bridge, Tasks-user identity, Ask Q modality  
**Version:** 1.0 · 2026-05-22  
**First full implementation:** **Q Vernal** on **Sanctum Tasks** (`tasks.decisionsciencecorp.com`)  
**Authors:** Mark Hopkins (product), Otto Vernal (engineering), Athena Vernal (persona source)

**Related Tasks documents (upgrade program, project 10):**

| Doc | ID | Role |
|-----|-----|------|
| Q persona (canonical) | [#295](https://tasks.decisionsciencecorp.com/admin/document.php?id=295) | Who Q is |
| Architecture / execution plan | [#296](https://tasks.decisionsciencecorp.com/admin/document.php?id=296) | Original phased plan + Mark decisions |
| UX audit (lettatest) | [#297](https://tasks.decisionsciencecorp.com/admin/document.php?id=297) | Widget quality bar |
| Upgrade planning hub | [#294](https://tasks.decisionsciencecorp.com/admin/document.php?id=294) | Program context |

**This file in git:** `sanctum-tasks/docs/MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md`  
**Private-doc mirror:** `private-documentation/MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md`  
**Tasks Document Library copy:** project **6**, directory `sanctum-modality/` · document **[#299](https://tasks.decisionsciencecorp.com/admin/document.php?id=299)**  
**Upgrade project pointer:** project **10** stub **[#300](https://tasks.decisionsciencecorp.com/admin/document.php?id=300)**

---

## 0. Read this first — what this document is for

You are looking at a **software modality template**: a repeatable way to bolt a **conversational AI agent** into an existing PHP Sanctum property (Tasks today; invoicing, CRM, presale, etc. tomorrow) **without** turning the host app into a chat product and **without** exposing Letta/Broca to the browser.

**Plain-English promise:** A logged-in user sees a **floating “Ask …” bubble** on every admin page. They type; an agent answers and can **take actions in the host app on their behalf**, using **their** permissions—not the agent’s superpowers.

**Technical promise:** The browser only talks to **your PHP origin**. A **Broca plugin** on an agent host **polls** inbox/outbox endpoints. **Letta** runs the persona. **SMCP** tools hit the host app’s REST API with a **server-injected per-user API key**.

**What this is not:**

- Not a Figma / CSS design system (though UX rules are included).
- Not “install ChatGPT in an iframe.”
- Not a reason to patch Broca core for one customer feature (use a **plugin**).

---

## 1. Executive summary — the whole machine in one page

### 1.1 The user-visible story

1. User logs into **Sanctum Tasks** (or another host app).
2. A **chat bubble** sits in the corner on **all `/admin/*` pages** after login.
3. Tap bubble → **chat panel** opens (desktop and phone). **Bubble hides** while panel is open; **X** closes panel and brings bubble back.
4. User messages **Q** (or another Vernal). Replies appear in-thread with **markdown** formatting.
5. Same user on **phone and desktop** sees the **same conversation** (identity is the **Tasks user**, not the browser session id).
6. Q can **create tasks, comment, search the board**, etc., as if the user did it—because tool calls use **that user’s hidden API key**.

### 1.2 The systems story (five boxes)

```
┌─────────────────────────────────────────────────────────────────┐
│  HOST APP (e.g. sanctum-tasks on multihost)                      │
│  • Admin UI + floating widget (JS/CSS under /q-bridge/widget/)   │
│  • PHP bridge /q-bridge/api/v1/ (inbox, outbox, messages, …)   │
│  • SQLite q_bridge_webchat.db (beside tasks.db, NOT in web root) │
│  • Hidden per-user API keys (key_kind = q_bridge) in tasks.db    │
└───────────────────────────┬─────────────────────────────────────┘
                            │ HTTPS poll + POST (Bearer poll key)
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  AGENT HOST (rehearsal: lettatest · prod target: moya)           │
│  • broca-q: Python Broca runtime + q_vernal_webchat plugin       │
│  • Letta: Q_Vernal agent (persona blocks, job_rules, tools)      │
│  • SMCP: q_vernal_tasks CLI → Tasks /api/*.php                   │
└───────────────────────────┬─────────────────────────────────────┘
                            │ tool calls with injected X-API-Key
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  HOST APP API again (tasks.decisionsciencecorp.com/api/)         │
│  ACL = logged-in chatter’s key only                               │
└─────────────────────────────────────────────────────────────────┘
```

**Critical invariant:** The **browser never receives** Letta passwords, Broca queue access, or the chatter’s API key plaintext for tool use. The widget may use **session cookie auth** to the PHP bridge only.

### 1.3 What we shipped on the Q Vernal leg (2026-05-21 → 2026-05-22)

| Layer | Status (rehearsal) | Notes |
|-------|-------------------|-------|
| Persona in Tasks doc | Done · #295 | Copied from Athena Letta block; Athena deleted duplicate |
| Execution plan | Done · #296 | Phases #385–#432; Mark decisions in §13 |
| Hidden `q_bridge` API keys + backfill | Done · #403–#404 | `getQBridgeDefaultApiKeyPlaintextForUser()` |
| PHP `public/q-bridge/` in sanctum-tasks | Done · #405 | Forked from `broca-web-client` PHP bridge |
| Ask Q embed `public/admin/_ask_q.php` | Done · #407 | `TASKS_Q_BRIDGE_ENABLED` gate |
| `q_vernal_webchat` Broca plugin | Done · `sanctumos/broca` | No Broca core queue injection |
| Letta **Q_Vernal** on **lettatest** | Done | `agent-4afbed9b-a6c0-403f-8499-4fb75b83c095`, port **18283** |
| broca-q on lettatest `/opt/broca-q` | Ops | Must stay running: `.venv/bin/python main.py` |
| SMCP `q_vernal_tasks` | Done | Per-chatter key via `resolve_user_key` + `--api-key` strip |
| E2E prod bridge | Done | Message → Q reply on prod Tasks DB |
| Identity = Tasks user | Done | `tasks:{id}`, `session_tasks_{id}`, Letta `tasks_user_{id}` |
| UX: bubble anchor, markdown, cross-device, keyboard | Done | widget.css v5, chat-widget.js v8 |
| **moya prod** | **Blocked · #414** | Until Mark signs off lettatest demo |

---

## 2. When to use this modality (and when not to)

### 2.1 Good fit

- You already have a **Sanctum PHP app** on multihost (`public/`, app-level auth, `/api/*.php`).
- You want **in-app help + action** for logged-in users (not anonymous marketing chat).
- You already run or can run **Broca + Letta** on moya (or a rehearsal VPS like lettatest).
- Actions should respect **existing API authorization** (no parallel permission system).

### 2.2 Poor fit

- Anonymous public website chat with no user account → still possible but you lose per-user ACL injection unless you invent guest keys.
- Sub-second streaming UX requirements → this architecture is **poll-based** (seconds, not milliseconds).
- You need the browser to talk directly to Letta for latency → violates our security model; build a different modality.

### 2.3 The “Sanctum inside Sanctum” insight

**Tasks** is the first host, but the pattern is deliberately **host-agnostic**:

| Host app | Bridge path (convention) | DB file (convention) |
|----------|--------------------------|----------------------|
| `tasks.decisionsciencecorp.com` | `/q-bridge/` | `db/q_bridge_webchat.db` |
| `invoicing.*` (future) | `/q-bridge/` or `/agent-bridge/` | `db/<name>_webchat.db` |
| Any LEMP property | Same PHP poll contract | SQLite beside main app DB |

Copy the **folder layout** and **API action names**; rename persona and Letta agent per product.

---

## 3. Architecture deep dive — pull model and why we chose it

### 3.1 Pull vs push

**Chosen: pull.** Broca plugin wakes up every N seconds, calls `GET …?action=inbox`, processes rows, later `POST …?action=outbox`.

**Why:**

- Host app stays on **multihost**; agent stays on **moya/lettatest**. No inbound firewall holes to Broca.
- Same pattern as upstream `sanctumos/broca-web-client` — battle-tested, documented.
- Ops can restart broca-q without redeploying Tasks.

**Tradeoff:** Latency is poll_interval (typically 3–5s) plus Letta think time. UX covers this with **typing indicator** and honest “Q is thinking” copy.

### 3.2 Browser → PHP only

Mark locked this in Tasks discussion **#421–#432** (summarized in doc #296 §13):

- Widget `fetch()` targets **`/q-bridge/api/v1/`** on the **same hostname** as Tasks.
- `useSessionAuth: true` → PHP session cookie identifies chatter; no API key in JS for tool ACL.
- Optional `Authorization: Bearer` only for **machine** callers (Broca poll key), not for human chat in production embed.

### 3.3 Three authentication layers (do not mix them up)

| Layer | Credential | Proves |
|-------|------------|--------|
| **Human → PHP bridge** | PHP session (`$_SESSION['user_id']`) | Which Tasks user is typing |
| **Broca → PHP bridge** | `TASKS_Q_BRIDGE_POLL_API_KEY` (env or `db/q_bridge_poll_api_key.txt`) | Poll inbox/outbox allowed |
| **SMCP → Tasks API** | Hidden per-user key (`key_kind = q_bridge`) | Tool calls act as that user |

Q the Letta agent **does not own** layer 3. The plugin resolves layer 3 **per message** from `tasks_user_id` in inbox metadata.

---

## 4. Identity model — Tasks user, not browser session

This was the highest-impact correction on the Q leg.

### 4.1 Wrong model (what we fixed)

Early webchat treated each browser `uid` / `session_id` as a person:

- Broca `platform_user_id = random hex`
- Letta `identifier_key = broca_user_<uuid>`
- Username `web_user_15056f41` in UI

Result: **new person every device**, duplicate Letta identities, Q “forgets” Mark on phone vs desktop.

### 4.2 Correct model (canonical)

| Concept | Value |
|---------|--------|
| **Chatter primary key** | `tasks_user_id` (integer, FK to `users.id`) |
| **Broca platform_user_id** | `tasks:{tasks_user_id}` e.g. `tasks:1` |
| **Letta identity_key** | `tasks_user_{id}` e.g. `tasks_user_1` |
| **Letta display name** | Tasks `username` e.g. `admin`, `rizzn` |
| **Bridge session id (canonical)** | `session_tasks_{tasks_user_id}` |
| **Widget routing session** | May still create ephemeral ids for transport, but **history + inbox** key off Tasks user |

**Rules enforced in code:**

- PHP inbox rows **require** `tasks_user_id`, `tasks_username` in session metadata.
- Broca plugin **rejects** inbox messages without `tasks_user_id`.
- One `platform_profile` per `(platform=q_vernal_webchat, platform_user_id=tasks:N)`.

**Cleanup script:** `broca/tools/q_vernal_identity_cleanup.py` (lettatest + multihost DB repair).

### 4.3 First-contact system prefix

On the chatter’s **first ever** message (count across all sessions), Broca prepends a **system prefix** to Letta:

- Greets by **Tasks username**
- Notes human block is tied to this Tasks user

Implemented in `message_handler.py` + `q_bridge_is_first_contact_for_inbox_row()`.

### 4.4 Human block sync

Broca updates Letta **human** block with structured JSON:

- Tasks username, user id, `Broca platform_user_id: tasks:N`
- Channel: Sanctum Tasks — Ask Q webchat

So Q’s memory stays aligned even if chat UI changes.

---

## 5. Host application integration (sanctum-tasks reference)

### 5.1 Feature flag

`public/includes/config.php`:

```php
define('TASKS_Q_BRIDGE_ENABLED', envBool('TASKS_Q_BRIDGE_ENABLED', true));
```

Set `TASKS_Q_BRIDGE_ENABLED=0` in env to hide Ask Q entirely.

### 5.2 Hidden per-user API keys

**Purpose:** Let SMCP tools call `/api/*.php` **as the chatter** without showing a integration key in Settings UI.

**Mechanism:**

- `key_kind = 'q_bridge'` in `api_keys` table.
- Unique index: one active q_bridge key per user.
- Plaintext derived deterministically: `stq_` + HMAC-SHA256 over user id + server secret (`TASKS_Q_BRIDGE_KEY_SECRET` or `db/q_bridge_key_secret.txt`).
- `getQBridgeDefaultApiKeyPlaintextForUser($userId)` in `functions.php`.
- **Not listed** in `list-api-keys` for normal UI.

**Backfill:** `php tools/backfill_q_bridge_api_keys.php` (idempotent).

**New users:** mint on create (migration / user-create path — task #403).

### 5.3 Embed snippet (admin chrome)

`public/admin/_ask_q.php` included from admin layout when logged in:

```html
<link rel="stylesheet" href="/q-bridge/widget/assets/css/widget.css?v=5">
<script src="/q-bridge/widget/assets/js/markdown-lite.js?v=1"></script>
<script src="/q-bridge/widget/assets/js/chat-widget.js?v=8"></script>
<script>
SanctumChat.init({
  apiBase: '/q-bridge/api/v1/',
  useSessionAuth: true,
  apiKey: 'session',
  position: 'bottom-right',
  title: 'Q. Vernal',
  chatterUsername: '<from $_SESSION username>',
  persistSession: true,
  historyLimit: 6
});
</script>
```

**Bump `?v=`** on CSS/JS when shipping widget changes (multihost deploy is file-sync; no bundler).

### 5.4 Database layout on multihost

| Path | Contents |
|------|----------|
| `/var/www/tasks.decisionsciencecorp.com/html/` | `public/` docroot (includes `q-bridge/`) |
| `/var/www/tasks.decisionsciencecorp.com/db/tasks.db` | Main app |
| `/var/www/tasks.decisionsciencecorp.com/db/q_bridge_webchat.db` | Chat sessions/messages/responses |
| `/var/www/tasks.decisionsciencecorp.com/db/q_bridge_poll_api_key.txt` | Optional poll secret file |
| `/var/www/tasks.decisionsciencecorp.com/db/q_bridge_key_secret.txt` | HMAC secret for derived user keys |

**Never** place `*.db` under `html/` (LEMP serves `public/` only — DB parent is outside web root).

---

## 6. PHP bridge specification (`public/q-bridge/`)

Forked from **`sanctumos/broca-web-client`** PHP tree; lives inside **sanctum-tasks** repo.

### 6.1 Directory map

```
public/q-bridge/
  api/v1/index.php      # Single router (?action=)
  config/
    database.php        # SQLite schema init
    settings.php        # Poll key, CORS, intervals
  includes/
    auth.php            # Bearer + session auth helpers
    chatter.php         # Tasks-user identity + canonical session
    tasks_session.php   # require_tasks_logged_in_user_id()
    utils.php           # Sessions, uid, cleanup
    api_response.php
  widget/
    assets/css/widget.css
    assets/js/chat-widget.js
    assets/js/markdown-lite.js
    embed.php, demo.php, …
```

### 6.2 SQLite schema (webchat)

**`web_chat_sessions`**

- `id` VARCHAR(64) PK — use `session_tasks_{userId}` for canonical row
- `uid` — legacy transport id; canonical rows use `tasks:{id}`
- `metadata` JSON — **must** include `tasks_user_id`, `tasks_username`

**`web_chat_messages`**

- `session_id`, `message`, `processed` (0 until Broca picks up), `broca_message_id` optional

**`web_chat_responses`**

- Agent replies for widget poll (`action=responses`)

### 6.3 API actions (contract)

| action | Method | Auth | Purpose |
|--------|--------|------|---------|
| `messages` | POST | Session (human) or Bearer (legacy) | User sends chat line |
| `responses` | GET | Session | Widget polls Q replies |
| `inbox` | GET | Bearer poll key | Broca fetches unprocessed messages |
| `outbox` | POST | Bearer poll key | Broca posts Q reply |
| `sessions` | * | Mixed | Session CRUD |
| `resolve_user_key` | POST | Bearer poll key | Broca fetches plaintext API key for `tasks_user_id` |
| `user_session` | GET | Session | Returns canonical `session_tasks_{id}` |
| `history` | GET | Session | Last N turns **across all sessions** for this Tasks user |
| `config` | GET | Public-ish | Widget config |

**Human send path (`messages`):**

1. `require_tasks_logged_in_user_id()`
2. `q_bridge_ensure_user_session($tasksUserId)`
3. Merge session metadata with chatter context
4. Insert `web_chat_messages`, `processed=0`
5. Inbox enrichment: `tasks_user_id`, `tasks_username`, `is_first_contact`

**Broca poll path (`inbox`):**

- Returns array of messages with `processed=0`
- After fetch, marks processed (or Broca acknowledges — match upstream behavior in `index.php`)

### 6.4 Rate limiting & CORS

Bridge has lightweight rate limit helpers per endpoint. CORS headers allow widget from same origin only in production (Tasks admin).

---

## 7. Broca plugin (`q_vernal_webchat`)

**Repo:** `sanctumos/broca` → `plugins/q_vernal_webchat/`  
**Deliberate:** No changes to Broca **core** queue injection; all Q-specific logic in plugin.

### 7.1 Files

| File | Role |
|------|------|
| `plugin.py` | Plugin entry, poll loop |
| `message_handler.py` | Inbox → Letta queue, identity, human block |
| `api_client.py` | HTTP to Tasks PHP bridge |
| `tasks_tool_bridge.py` | Calls `resolve_user_key`, writes `current_tasks_user_id.txt` for SMCP |
| `settings.py` | Env: `WEB_CHAT_API_URL`, poll interval, etc. |

### 7.2 Message flow

1. Poll `inbox` from Tasks (Bearer `TASKS_Q_BRIDGE_POLL_API_KEY`).
2. For each message, read `tasks_user_id`, `tasks_username`, `session_id`, `is_first_contact`.
3. **Reject** if `tasks_user_id` missing.
4. `get_or_create_platform_profile(platform_user_id=f"tasks:{id}", username=Tasks username)`.
5. Publish `run/current_tasks_user_id.txt` for SMCP subprocess.
6. Insert into Broca `messages` + `queue` → Letta agent **Q_Vernal**.
7. On response, `POST outbox` with `{session_id, response}`.

### 7.3 lettatest runtime layout

| Path | Note |
|------|------|
| `/opt/broca-q/` | Broca install, `.env`, `sanctum.db` |
| `/opt/broca-q/.venv/bin/python main.py` | **Correct** start command |
| `LETTA / AGENT_ENDPOINT` | `http://127.0.0.1:18283` |
| `Q_Vernal` agent id | `agent-4afbed9b-a6c0-403f-8499-4fb75b83c095` |
| `/opt/sanctum-q/smcp/` | SMCP entry + `env.smcp` |
| Plugin copy | `/opt/broca-q/plugins/q_vernal_webchat/` |

**Ops lesson:** If broca-q dies, widget still “works” but Q never answers — always check process before blaming PHP.

### 7.4 moya target layout (not deployed without #414)

Per plan #296:

- `~/sanctum/agents/q/broca/`
- screen `broca-q`
- Otto HTTP bridge port **8874** (reserved)
- Letta **8284** on moya

---

## 8. Letta agent + SMCP tools

### 8.1 Agent bootstrap

1. Create agent **Q_Vernal** on Letta (rehearsal lettatest, prod moya).
2. Attach persona from doc **#295** (personality, voice, name structure).
3. Add **job_rules** memory (operator rules: use tools for board actions, never invent API keys).
4. Attach SMCP tool definitions — production set: **`q_vernal_tasks__*`** tools (~31), not legacy `tasks__*` on wrong endpoint.

**Install helpers (sanctum-tasks repo):**

- `tools/lettatest-install-q-smcp.sh`
- `tools/lettatest_attach_q_smcp.py`

### 8.2 SMCP package `q_vernal_tasks`

**Path:** `sanctum-tasks/smcp_plugin/q_vernal_tasks/`

- CLI wraps Tasks operations (list tasks, create, comment, …).
- **`resolve_key.py`** — given `tasks_user_id`, calls bridge `resolve_user_key` (or reads cached file).
- **Security:** Strip `--api-key` from argv before subprocess so model can't override injection.

**Env on agent host:**

- `PYTHONPATH` must include `sanctum-tasks` and `smcp_plugin`.
- `TASKS_Q_BRIDGE_POLL_API_KEY` must match PHP bridge poll key.
- `TASKS_DSC_BASE_URL` → `https://tasks.decisionsciencecorp.com/api/`

### 8.3 Tool surface policy

Mark decision: ship **broad** SMCP surface initially; tighten via **job_rules** and allowlist later (#408–#409). ACL still enforced by **injected user key** at API layer.

---

## 9. Widget UX modality (Ask Q)

These rules apply to **any** host app using this bridge — not only Tasks colors.

### 9.1 Layout

- **Closed:** floating bubble bottom-right (configurable corner).
- **Open:** panel anchored to same corner; **bubble hidden** (class `is-open` on widget root); close via **X** only.
- **Not allowed:** navigate to full-page `chat.php` for embedded help (fine for demos).

### 9.2 Persistence

| Mechanism | What it stores |
|-----------|----------------|
| `session_tasks_{userId}` | Canonical server session |
| `GET user_session` | Widget learns canonical id after login |
| `GET history` | Last N turns across devices |
| `localStorage` | Optional cache of session id + UX prefs |
| Broca/Letta | Long-term identity + human block |

### 9.3 Presentation

- **markdown-lite.js** — safe subset (bold, lists, code, links).
- User bubbles right, agent left (Telegram-like).
- Header shows agent title + `You: {username}`.
- Typing indicator while polling for `responses`.

### 9.4 Mobile keyboard

**Problem:** `100vh` on mobile includes area behind keyboard; panel gets clipped.

**Fix:** `visualViewport` API in `chat-widget.js`:

- Listen `resize` / `scroll` on `window.visualViewport`
- Set CSS variable `--sanctum-vvh` to visible height
- Raise widget `bottom` by keyboard inset
- Cap panel `max-height` to visible viewport

**Verify on real devices** after deploy; Playwright emulates viewport but not always true keyboard.

### 9.5 Visual regression

`tools/design-smoke/ask_q_verify.py` — desktop 1440×900, mobile 390×844, asserts:

- Bubble bottom-anchored when closed
- Bubble hidden when open
- Panel bottom-anchored when open
- Chatter label present

Run in venv: `tools/design-smoke/.venv/bin/python ask_q_verify.py`

---

## 10. Deployment topology

| Stage | Host | What runs |
|-------|------|-----------|
| **Tasks UI + PHP** | multihost `64.95.10.156` | `sanctum-tasks` git sync → `sites/tasks.decisionsciencecorp.com.env` |
| **Rehearsal agent** | lettatest `64.95.12.16` | broca-q, Letta 18283, SMCP |
| **Prod agent (future)** | moya via `sanctum.zero1.network:7837` | broca-q, Letta 8284 — **blocked #414** |

**Network path prod:** Tasks multihost → HTTPS → lettatest (rehearsal) or moya (future). Broca polls **public Tasks URL** for inbox/outbox — ensure poll key and firewall allow outbound from agent host.

**Deploy rule:** Otto pushes `sanctum-tasks` to GitHub; **Ada** runs `/root/sync.sh tasks.decisionsciencecorp.com` on multihost (unless Mark orders immediate sync). Agent host files are **ops rsync** from workspace (`broca` plugin, lettatest scripts).

---

## 11. Operations runbook (condensed)

### 11.1 “Q not answering”

1. Is **broca-q** running? (`pgrep -f broca-q.*main.py`)
2. Last lines of `/opt/broca-q/run/broca-q.log`
3. Curl Tasks `inbox` with poll key — any `processed=0` stuck?
4. Letta health `GET /v1/health/` on agent port
5. SMCP: `PYTHONPATH`, poll API key not empty
6. Wrong tools attached? Agent should have `q_vernal_tasks__*` not orphan `demo_math__*`

### 11.2 Restart broca-q (lettatest)

```bash
cd /opt/broca-q
pkill -f '/opt/broca-q/.venv/bin/python main.py' || true
nohup .venv/bin/python main.py >> run/broca-q.log 2>&1 &
```

### 11.3 Identity cleanup

```bash
TASKS_USER_ID=1 TASKS_USERNAME=admin AGENT_API_KEY=… \
  python3 /opt/broca-q/tools/q_vernal_identity_cleanup.py
```

Run separately on multihost for `Q_BRIDGE_DB=…/q_bridge_webchat.db` (broca DB optional).

### 11.4 E2E seed (prod)

`php tools/e2e_q_bridge_seed_message.php` on multihost (sets env `TASKS_Q_BRIDGE_DB_PATH`).

---

## 12. Security checklist

- [ ] Poll API key rotation procedure documented
- [ ] `q_bridge` HMAC secret not in git
- [ ] `resolve_user_key` only with Bearer poll auth
- [ ] Browser never receives tool API key
- [ ] SMCP strips client-supplied `--api-key`
- [ ] PHP bridge rejects messages without `tasks_user_id`
- [ ] No `.db` under `public/`
- [ ] Rate limits tuned before public launch (deferred Mark decision)

---

## 13. Replication recipe — new Sanctum app in 12 steps

Use this when adding the modality to **invoicing**, **CRM**, **presale**, etc.

1. **Copy** `public/q-bridge/` from sanctum-tasks into host repo `public/q-bridge/` (or symlink pattern if monorepo).
2. **Add** `HOST_*_BRIDGE_ENABLED` flag + embed partial in admin layout after login.
3. **Implement** `require_*_logged_in_user_id()` using host session (mirror `tasks_session.php`).
4. **Add** hidden per-user API keys (`key_kind = q_bridge` or app-specific kind) + HMAC or DB-stored secret.
5. **Wire** `get*BridgeDefaultApiKeyPlaintextForUser()` into host `functions.php`.
6. **Create** SQLite `q_bridge_webchat.db` beside host DB; deploy outside web root.
7. **Set** poll key file/env on multihost; configure Broca plugin `WEB_CHAT_API_URL` to `https://{host}/q-bridge/api/v1/`.
8. **Fork** or reuse `q_vernal_webchat` plugin → rename (`invoicing_vernal_webchat`) if behavior diverges; else reuse with env `PLATFORM_NAME`.
9. **Create** Letta agent + persona doc in Tasks Document Library.
10. **Ship** SMCP package pointing at host `/api/` (copy `smcp_plugin/q_vernal_tasks` pattern).
11. **Run** Playwright smoke from `tools/design-smoke/` adapted to host login URL.
12. **Document** persona + plan in Tasks docs; link epic in program project.

**Time savers:** Keep **API action names** identical across hosts so one Broca plugin binary can serve multiple agents with different `.env` URLs.

---

## 14. Pitfalls & lessons (Q Vernal leg)

| Pitfall | Symptom | Fix |
|---------|---------|-----|
| Session-based identity | Q forgets user on 2nd device | `tasks:{id}` only |
| Double `get_or_create_letta_user` | Orphan Letta identities | Profile path only |
| broca-q not in venv | Silent no replies | `.venv/bin/python main.py` |
| Empty SMCP poll key | Tools auth fail | Read from `broca-q/.env` |
| Wrong Letta tools | “Backend hiccup” on task search | Attach `q_vernal_tasks__*` |
| `100vh` mobile CSS | Keyboard covers half chat | `visualViewport` sync |
| Bubble visible under panel | Clutter / mis-tap | `.is-open .sanctum-chat-bubble { display: none }` |
| Otto ran `sync.sh` without Ada | Process violation | Push git; Ada or cron deploys |
| Confusing lettatest vs moya | Wrong Letta port | 18283 vs 8284 |

---

## 15. File inventory (canonical repos)

### sanctum-tasks (host)

- `public/q-bridge/**`
- `public/admin/_ask_q.php`
- `public/includes/config.php` (keys, flags)
- `public/includes/functions.php` (q_bridge keys)
- `smcp_plugin/q_vernal_tasks/**`
- `tools/backfill_q_bridge_api_keys.php`
- `tools/e2e_q_bridge_seed_message.php`
- `tools/design-smoke/ask_q_verify.py`
- `tools/lettatest-install-q-smcp.sh`
- `tools/lettatest_attach_q_smcp.py`
- `docs/MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md` (this file)

### broca (agent plugin)

- `plugins/q_vernal_webchat/**`
- `tools/q_vernal_identity_cleanup.py`
- `database/operations/users.py` (`identity_key`, `channel_label`)

### private-documentation

- `MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md` (mirror)

---

## 16. Program links & tasks (Q Vernal track)

- **Epic:** [#313](https://tasks.decisionsciencecorp.com/admin/view.php?id=313)
- **Program tracker:** [#342](https://tasks.decisionsciencecorp.com/admin/view.php?id=342)
- **Build list:** project 10, list_id **64**, tasks **#385–#420** (build), **#421–#432** (Mark decisions)
- **Prod gate:** [#414](https://tasks.decisionsciencecorp.com/admin/view.php?id=414)

---

## 17. Glossary

| Term | Meaning |
|------|---------|
| **Modality** | Repeatable software pattern (this doc), not a mockup |
| **Host app** | PHP Sanctum product embedding the widget (Tasks, etc.) |
| **Bridge** | `q-bridge` PHP + SQLite poll hub |
| **Chatter** | Logged-in human using Ask Q |
| **Poll key** | Bearer secret Broca uses for inbox/outbox |
| **q_bridge key** | Hidden per-user Tasks API key for tools |
| **Canonical session** | `session_tasks_{userId}` |
| **Pull model** | Broca fetches work; server does not push to Broca |

---

## 18. Document maintenance

When you extend this modality:

1. Update **this file** in `sanctum-tasks/docs/`.
2. Mirror to **`private-documentation/`**.
3. Re-upload to Tasks Document Library (**project 6**, `sanctum-modality/`).
4. Add a one-paragraph “what changed” comment on program epic **#313**.
5. Bump widget `?v=` cache bust on embed partial.

**End of omnibus template v1.0.**
