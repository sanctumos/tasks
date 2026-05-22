# Q Vernal webchat bridge (PHP)

Fork of `sanctumos/broca-web-client` PHP poll bridge, embedded in Tasks.

- **API:** `/q-bridge/api/v1/index.php?action=…` (`messages`, `inbox`, `outbox`, `responses`, `sessions`, `config`)
- **Widget:** `/q-bridge/widget/` (bubble UI — embed from Tasks admin)
- **DB:** `q_bridge_webchat.db` beside `tasks.db` (not in web root)
- **Poll auth:** `TASKS_Q_BRIDGE_POLL_API_KEY` or `db/q_bridge_poll_api_key.txt` (Broca plugin Bearer)
- **User messages:** require logged-in Tasks session; `tasks_user_id` in session metadata for plugin/SMCP injection
