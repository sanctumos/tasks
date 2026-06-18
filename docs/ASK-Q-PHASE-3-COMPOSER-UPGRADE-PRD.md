# Ask Q Phase 3 — Composer upgrade PRD

**Product:** Sanctum Tasks · Ask Q Vernal webchat (`public/q-bridge/`)  
**Program:** [Sanctum Tasks — platform upgrade](https://tasks.decisionsciencecorp.com/admin/project.php?id=10) · list **151** (phase 3)  
**Status:** Draft for Mark review · **2026-06-06**  
**Author:** Otto  
**Prototype (test first):** `tools/ask-q-composer-prototype/` — `python3 -m http.server 8765`

**Related:** [Modality omnibus #299](https://tasks.decisionsciencecorp.com/admin/doc.php?id=299) · [Q job_rules #301](https://tasks.decisionsciencecorp.com/admin/doc.php?id=301) · Phase 2 tracker [#671](https://tasks.decisionsciencecorp.com/admin/view.php?id=671) · Rate policy [#678](https://tasks.decisionsciencecorp.com/admin/view.php?id=678) (shipped; needs revision)

---

## 1. Executive summary

**Problem:** Mark cannot paste large blocks (logs, JSON, transcripts, task exports) into Ask Q. The composer treats everything as inline textarea text — slow, unreadable, and blocked by practical limits. Separately, **response polling rate limits** cause 429 errors ~15 minutes after opening the chat panel, which feels like “Q stopped working.”

**Solution (two tracks, one release):**

| Track | Outcome |
|-------|---------|
| **A — Composer UX** | Discord-style **large paste → text attachment chip** above composer. UI stays clean; **full text still ships in the message payload** to Broca/Letta. |
| **B — Throughput** | Fix poll budget mismatch, adaptive polling, 429 recovery — so long sessions with big payloads remain usable. |

**Delivery principle:** Ship **standalone prototype** → Mark sign-off → port to `chat-widget.js` + PHP bridge + Broca.

---

## 2. Problem statement (user voice)

> “I need to copy+paste large sections of data into the chat widget. This is preventing me. … Q can’t be useful without it.”

### 2.1 Symptoms today

1. **Paste a long excerpt** → entire blob lands in the 120px-tall textarea; browser/UI chokes; hard to add a short question above it.
2. **Send** → may hit `MAX_MESSAGE_LENGTH` (10,000 chars) with opaque “Invalid message” (`validate_message` failure).
3. **Thread** → user bubble renders megabytes of markdown; scroll/layout breaks.
4. **Keep panel open** → after ~15 min, polling hits **300 responses/hour** cap (widget polls every **3s** = ~1200/h) → “Ask Q is temporarily busy (rate limit).”

### 2.2 Non-goals (phase 3)

- Binary file upload (PDF/images) in Ask Q — defer; task attachments API is task-scoped, not chat-scoped.
- Sub-second streaming replies — still poll-based per Modality #299.
- Letta DB surgery — API-only agent updates.

---

## 3. Research summary

### 3.1 In-repo inventory (nothing Discord-like exists yet)

| Asset | Reuse for |
|-------|-----------|
| `public/q-bridge/widget/assets/js/chat-widget.js` | Integration target; textarea + send only today |
| `public/assets/admin.js` `bindTaskImageUpload()` | Drop zone + `FormData` upload pattern |
| `public/assets/admin.css` `.attachment-list__*` | Chip/row visual language |
| `broca/plugins/telegram_bot/inbound_coalesce.py` | Server-side merge of rapid chunks (reference, not UI) |
| `broca/runtime/core/image_handling.py` | `[Image Attachment: url]` text protocol precedent |
| `broca-web-client/docs/port-docs/embeddable-widget-plan.md` | Phase 3 “file attachments” listed but **never built** |

### 3.2 External patterns (crib, don’t reinvent)

| Source | Pattern |
|--------|---------|
| [web.dev — paste files](https://web.dev/patterns/clipboard/paste-files) | `paste` event + `preventDefault` + `clipboardData.getData('text/plain')` |
| [W3C Clipboard — presentation styles](https://www.w3.org/TR/clipboard-apis/) | Distinguish **inline** vs **attachment** presentation |
| Discord (product behavior) | Large paste / dropped `.txt` → **file chip** in composer; message shows chip; full content available on click |
| CKEditor 5 clipboard | Intercept paste, transform before insert (same hook point as our chip UX) |

### 3.3 Throughput root cause (verified in code)

```
Widget poll interval:     3s  →  ~1,200 GET /api/responses per hour (panel open)
Default rate cap:       300/hour on /api/responses (per Tasks user)
Time to 429:            ~15 minutes continuous open chat
On 429:                 poll loop stops (no backoff) — user must close/reopen
```

Files: `chat-widget.js` `pollForResponses()`, `rate_limit_config.php`, `auth.php` `apply_rate_limiting()`.

**Historical note:** `#678` approved 300/h responses cap before the 3s poll math was reconciled. `user_session` was later raised 30→600 in code because `persistSession` on every admin page load blocked users; admin Settings UI still shows stale **30** fallback.

---

## 4. Product requirements — Track A (composer)

### 4.1 Large-paste detection

| ID | Requirement |
|----|-------------|
| A1 | On `paste` into composer: if `text/plain` length **≥ threshold**, **do not** insert into textarea. |
| A2 | Default threshold: **800 characters** (tunable; prototype exposes control). |
| A3 | Below threshold: current inline paste behavior unchanged. |
| A4 | Also accept **drag-drop** of `.txt` / `text/*` files onto composer zone (phase 3.1 — same chip UX). |

### 4.2 Attachment chip (composer pending state)

| ID | Requirement |
|----|-------------|
| A5 | Show chip: icon + `pasted-YYYYMMDD-HHMMSS.txt` + human size. |
| A6 | Remove (×) before send. |
| A7 | Max **3** text attachments per message (prototype default; confirm with Mark). |
| A8 | Max **256 KB** per text attachment (prototype); prod target **512 KB–1 MB** after load test. |
| A9 | Textarea holds **caption only** (short instruction: “summarize this log”, etc.). |

### 4.3 Sent message display (thread)

| ID | Requirement |
|----|-------------|
| A10 | User bubble: caption (if any) + chip row(s), **not** full pasted wall. |
| A11 | Chip **Preview** opens modal / drawer with full text (read-only). |
| A12 | Bot bubbles: unchanged markdown rendering. |

### 4.4 Wire protocol (still in-text for Broca/Letta)

**Principle:** Q’s brain still receives **one user message string** — no separate download step for v1. Attachments are a **composer/UI construct** that serializes into `message` (+ optional structured metadata).

**Proposed POST `/api/messages` body (backward compatible):**

```json
{
  "session_id": "session_tasks_1",
  "message": "Please find errors in the attached log.\n\n[Attached text 1: pasted-20260606-120000.txt (42.3 KB)]\n…full text…",
  "caption": "Please find errors in the attached log.",
  "attachments": [
    {
      "id": "att-x7k2",
      "kind": "text",
      "filename": "pasted-20260606-120000.txt",
      "mime_type": "text/plain",
      "size_bytes": 43210,
      "text": "…full text…"
    }
  ],
  "timestamp": "…",
  "page_context": { }
}
```

| ID | Requirement |
|----|-------------|
| A13 | `message` MUST contain full attachment text for Broca inbox (today’s plugin reads `message` only). |
| A14 | `attachments[]` duplicated in `web_chat_messages.metadata` JSON for UI rehydrate + analytics. |
| A15 | Raise `MAX_MESSAGE_LENGTH` to **1,000,000** (align with document body cap) OR enforce **sum(caption + attachments) ≤ cap** with clear 413 error. |
| A16 | Stop `htmlspecialchars` on inbound chat before SQLite storage (store raw UTF-8; escape on output only). |

### 4.5 Broca / Letta

| ID | Requirement |
|----|-------------|
| A17 | `q_vernal_webchat` continues to forward assembled `message` to Letta. |
| A18 | Optional: prepend compact header listing attachment filenames/sizes before body (job_rules tweak). |
| A19 | Remove or align dead `sanitize_message()` 4k truncator in Broca (unused today; hazard if wired later). |

---

## 5. Product requirements — Track B (throughput)

### 5.1 Rate limits (revise #678 policy)

| Endpoint | Current | Proposed | Rationale |
|----------|---------|----------|-----------|
| `/api/responses` | 300/h | **3,600/h** or **exempt while `waiting_for_reply`** | 3s poll × 3600s |
| `/api/messages` | 60/h | **120/h** | Large paste sends are rarer but heavier |
| `/api/history` | 120/h | 120/h | OK |
| `/api/user_session` | 600/h (code) | 600/h | Fix admin UI fallback 30→600 |
| `user_max_requests` | 1200/h | **5,000/h** | Headroom for poll + sends |

Admin Settings → Ask Q remains source of truth (`app_settings.q_bridge.rate_limits`).

### 5.2 Adaptive polling (widget)

| ID | Requirement |
|----|-------------|
| B1 | **Active wait** (after send, until reply): poll every **3s**. |
| B2 | **Idle** (panel open, no pending reply): backoff **3s → 10s → 30s** max. |
| B3 | **Tab hidden** (`document.visibilityState`): pause or **60s** heartbeat. |
| B4 | On **429**: exponential backoff + user toast; **do not** kill loop permanently. |
| B5 | Promote `3000` to config constant shared with docs. |

### 5.3 Optional phase 3.2 (if 3.1 still tight)

- Long-poll `GET responses?wait=25` single flight replaces rapid poll while waiting.
- Server-Sent Events on same origin (Modality allows minutes-scale push, not sub-second).

---

## 6. Standalone prototype

**Path:** `tools/ask-q-composer-prototype/`

```bash
cd tools/ask-q-composer-prototype && python3 -m http.server 8765
# → http://127.0.0.1:8765/
```

**Includes:**

- `composer-paste.js` — `ComposerPasteManager` (paste hook, chips, payload builder)
- `index.html` — mini thread + payload inspector + poll budget simulator
- `sample-large.txt` — copy/paste fodder

**Sign-off gate:** Mark approves UX on prototype **before** `chat-widget.js` merge.

---

## 7. Implementation phases

| Phase | Scope | Ship criteria |
|-------|-------|---------------|
| **3.0** | PRD + prototype + Tasks program | This doc filed; prototype runnable |
| **3.1** | Throughput fixes only | 1h open panel, no 429; tests |
| **3.2** | Composer port + PHP metadata + cap raise | Playwright paste scenario; PHPUnit message limits |
| **3.3** | Broca job_rules + load test with 256KB paste | Q acknowledges attachment in reply |
| **3.4** | Drag-drop `.txt`, polish, docs | Modality #299 appendix updated |

---

## 8. Test plan

| Layer | Test |
|-------|------|
| Prototype | Manual paste &gt; threshold → chip; preview; payload JSON |
| PHPUnit | `validate_message` at 500k; metadata round-trip; no htmlspecialchars regression |
| Playwright | `tools/design-smoke/ask_q_large_paste_verify.py` — paste sample, send, chip visible, no textarea wall |
| Integration | Bridge seed message with 100k body; Broca inbox receives full text |
| Throughput | Simulated 1h poll counter test in PHP or JS unit |

---

## 9. Risks & mitigations

| Risk | Mitigation |
|------|------------|
| 1MB messages blow Letta context | Caption + “summarize attachment”; future: store blob server-side, send summary + `get-document` style tool |
| SQLite row bloat | `web_chat_messages.message` TEXT OK; monitor `q_bridge_webchat.db` size |
| XSS in preview modal | `textContent` only in preview; markdown-lite unchanged for bot |
| Rate limit whack-a-mole | Separate counters for `poll` vs `send` in rate_limit_config |

---

## 10. Open questions for Mark

1. **Threshold:** 800 chars default OK, or higher (e.g. 2000)?
2. **Per-attachment cap:** 256 KB / 512 KB / 1 MB?
3. **Attachments per message:** 3 enough?
4. **Drag-drop** in v3.1 or defer to 3.4?
5. **Approve revised rate limits** in §5.1 (supersedes #678 numbers for responses)?

---

## 11. Task index (phase 3 list 151)

Created on program board — see epic **#TBD** on [project 10](https://tasks.decisionsciencecorp.com/admin/project.php?id=10).

| ID | Title |
|----|-------|
| Epic | [#969](https://tasks.decisionsciencecorp.com/admin/view.php?id=969) — program tracker |
| P3.0.1 | [#970](https://tasks.decisionsciencecorp.com/admin/view.php?id=970) PRD + prototype (done) |
| P3.0.2 | [#971](https://tasks.decisionsciencecorp.com/admin/view.php?id=971) Mark UX sign-off |
| P3.1.1 | [#972](https://tasks.decisionsciencecorp.com/admin/view.php?id=972) Adaptive polling + 429 recovery |
| P3.1.2 | [#973](https://tasks.decisionsciencecorp.com/admin/view.php?id=973) Revise rate limit defaults |
| P3.2.1 | [#974](https://tasks.decisionsciencecorp.com/admin/view.php?id=974) Port composer → chat-widget |
| P3.2.2 | [#975](https://tasks.decisionsciencecorp.com/admin/view.php?id=975) PHP metadata + 1M cap |
| P3.2.3 | [#976](https://tasks.decisionsciencecorp.com/admin/view.php?id=976) Playwright + PHPUnit |
| P3.3.1 | [#977](https://tasks.decisionsciencecorp.com/admin/view.php?id=977) Broca/job_rules |
| P3.3.2 | [#978](https://tasks.decisionsciencecorp.com/admin/view.php?id=978) Load test + prod smoke |

**Tasks doc:** [#480](https://tasks.decisionsciencecorp.com/admin/doc.php?id=480)

---

## 12. References

- Repo: `docs/MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md`
- Ops: `docs/Q-BRIDGE-OPS-RUNBOOK.md`
- Prototype: `tools/ask-q-composer-prototype/README.md`
- Widget: `public/q-bridge/widget/assets/js/chat-widget.js`
- Rate config: `public/q-bridge/includes/rate_limit_config.php`
