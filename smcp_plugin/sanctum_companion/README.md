# sanctum_companion SMCP plugin (Track B)

Tool: `sanctum_companion__open_canvas`

## Files

| File | Role |
|------|------|
| `cli.py` | SMCP `--describe` / `open_canvas` execute |
| `context.py` | Read/validate `sanctum.broca.active-turn` v1 |
| `bridge_client.py` | `POST ?action=ui_event` with poll bearer (never log Authorization) |

## Environment

| Variable | Purpose |
|----------|---------|
| `BROCA_ACTIVE_TURN_FILE` | Path to active-turn JSON (default `/opt/broca-q/run/active_turn.json`) |
| `SANCTUM_COMPANION_BRIDGE_URL` or `TASKS_Q_BRIDGE_API_URL` | Bridge base URL |
| `SANCTUM_COMPANION_POLL_API_KEY` or `TASKS_Q_BRIDGE_POLL_API_KEY` | Poll bearer |
| `SANCTUM_COMPANION_ALLOWED_PLATFORMS` | Optional comma list (default `q_vernal_webchat`) |

## Packaging into Tasks / Q SMCP

Deploy later places this plugin in Q’s SMCP plugin directory. Options:

1. **Copy** this directory to `sanctum-tasks/smcp_plugin/sanctum_companion/` (or the live SMCP plugins root) as a snapshot.
2. **Symlink** from the Tasks/SMCP plugins tree to this repo path during lab/dev only.

Do not expose bridge URL, poll key, session id, user id, or event id in the model-visible tool schema.

## Smoke

```bash
python3 smcp/sanctum_companion/cli.py --describe
BROCA_ACTIVE_TURN_FILE=fixtures/active-turn/valid.json \
  SANCTUM_COMPANION_BRIDGE_URL=http://127.0.0.1:8765 \
  SANCTUM_COMPANION_POLL_API_KEY=lab-poll-key \
  python3 smcp/sanctum_companion/cli.py open_canvas --title "Working canvas"
```
