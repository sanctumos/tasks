# Ask Q composer prototype — large paste as attachment

**Standalone UX lab** for Phase 3 (Discord-style large paste). Not wired to prod q-bridge.

## Run locally

```bash
cd tools/ask-q-composer-prototype
python3 -m http.server 8765
```

Open `http://127.0.0.1:8765/` — paste a large block (logs, JSON, markdown) into the composer.

## What this proves

| Behavior | How |
|----------|-----|
| Small paste (&lt; threshold) | Inline in textarea (today’s behavior) |
| Large paste (≥ threshold) | `preventDefault` on paste → pending **text attachment chip** (filename + size), composer stays short |
| Optional caption | Short line in textarea sent with attachment |
| Send payload | Right panel shows JSON **as q-bridge would receive** (`message` + `attachments[]`) |
| Thread display | User bubble shows chip + caption, not 50k chars of wall text |
| Throughput demo | Second tab simulates adaptive poll budget vs fixed 3s / 300h cap |

## Code cribbed from

- [web.dev paste-files](https://web.dev/patterns/clipboard/paste-files) — `paste` + `clipboardData.getData('text/plain')`
- `public/assets/admin.js` — `bindTaskImageUpload` drop-zone + row pattern (simplified to chips)
- `public/q-bridge/widget/assets/css/widget.css` — composer layout family

## After Mark sign-off

Port `composer-paste.js` patterns into `public/q-bridge/widget/assets/js/chat-widget.js` per PRD `docs/ASK-Q-PHASE-3-COMPOSER-UPGRADE-PRD.md`.
