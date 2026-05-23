# Q Vernal webchat bridge (PHP)

Fork of `sanctumos/broca-web-client` PHP poll bridge, embedded in Tasks.

**Full modality template (embed this pattern in other Sanctum apps):**  
`docs/MODALITY-EMBEDDED-AGENT-CHAT-IN-SANCTUM-APP.md` · Tasks doc [#299](https://tasks.decisionsciencecorp.com/admin/document.php?id=299)

- **API:** `/q-bridge/api/v1/index.php?action=…` (`messages`, `inbox`, `outbox`, `responses`, `sessions`, `config`)
- **Widget:** `/q-bridge/widget/` (bubble UI — embed from Tasks admin)
- **DB:** `q_bridge_webchat.db` beside `tasks.db` (not in web root)
- **Poll auth:** `TASKS_Q_BRIDGE_POLL_API_KEY` or `db/q_bridge_poll_api_key.txt` (Broca plugin Bearer)
- **User messages:** require logged-in Tasks session; `tasks_user_id` in session metadata for plugin/SMCP injection
- **Page context:** each message carries `page_context` (project/task/document screen) → Broca prepends `[Chat context — Sanctum Tasks UI]` for Q (not shown in widget)
