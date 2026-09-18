# Operator rollback — Track B companion (Tasks host)

One-way import: edit **`decisionsciencecorp/sanctum-companion-shell`**, then re-vendor into **`sanctumos/tasks`**. Do not hand-edit vendored assets under Tasks except host glue (`tasks-bootstrap.js`, boot PHP).

## Tasks sync tip SHA

Recorded on Tasks at `public/q-bridge/companion/SHELL_PIN.txt`:

| Field | Value (as of 2026-09-18 import) |
|-------|----------------------------------|
| Shell tip | `e9ae923f7a2e3bc8f76a3411531f18149944a366` (aria-hidden; coverage tip `d49138d` is repo/CI only) |
| Tasks import commit | `5a1e90bc328cf3b00c001f6915d28702596b43a1` |
| Pin meta commit | `d17d050c34b882932de4c84a98a156ddf359feb0` |

Update `SHELL_PIN.txt` whenever you re-import from this repo.

## Companion route files (Tasks)

| Path | Role |
|------|------|
| `public/admin/companion.php` | Named URL page (`/admin/companion.php`) |
| `public/admin/companion-boot.php` | Session boot JSON (`apiBase` / CSRF / theme / …) |
| `public/q-bridge/companion/assets/**` | Vendored shell assets |
| `public/q-bridge/companion/assets/js/tasks-bootstrap.js` | Host-only bootstrap (editable) |
| `public/q-bridge/companion/php/*.php` | Vendored PHP allowlists |
| `public/q-bridge/companion/SHELL_PIN.txt` | Tip SHA + import note |
| `public/q-bridge/api/v1/index.php` | Bridge: `responses` + `ui_events` |
| `public/q-bridge/config/database.php` | `web_chat_ui_events` DDL |
| `public/admin/_ask_q.php` | Floating Ask Q widget CSRF boot |

## `ui_events` table — idempotent

`CREATE TABLE IF NOT EXISTS web_chat_ui_events` (+ index) is safe to re-run. Inserts key on `event_id`; duplicate event ids resolve to the existing row (no double-fire). Leaving the table in place after a code rollback is fine; dropping it is optional and not required to restore Ask Q.

## CSRF widget change

Session POSTs (Ask Q bubble + companion) send `X-CSRF-Token` from the host boot payload. Rolling back the companion host without also reverting the CSRF lines in `chat-widget.js` / `_ask_q.php` can leave the bubble broken (403). Roll CSRF + companion routes together, or keep CSRF and only remove the fullscreen route.

## Broca active-turn — NOT deployed until Mark OK

`sanctum.broca.active-turn` (runtime sidecar + SMCP `context.py`) stays **lab / fixtures only** until Mark explicitly clears a Broca deploy. Do not sync active-turn writers to moya Q Broca as part of a Tasks companion rollback or re-import.

## Roll Tasks back to a previous commit (via Ada sync)

1. On the **Tasks** repo, identify the last-good commit (pre-companion tip is typically `4c88a3c` / parent of `825f736`, or whatever SHA Mark names).
2. Reset or revert on GitHub `main` to that commit (Mark/Otto owns git; do not force-push unless Mark orders it).
3. Ask **Ada** (Broca) to **`sync.sh tasks.decisionsciencecorp.com`** (and deploy if needed) so multihost matches the tip.
4. Smoke: Ask Q bubble still opens; `/admin/companion.php` 404s or matches the rolled tip; CSRF POSTs succeed.
5. Optional: leave `web_chat_ui_events` in SQLite (idempotent / unused by old code) or leave as-is.

Do **not** SSH-mutate multihost docroot as a substitute for Ada sync unless Mark explicitly bypasses Ada for that action.
