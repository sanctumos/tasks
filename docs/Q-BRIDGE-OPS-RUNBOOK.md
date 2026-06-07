# Ask Q / q-bridge ops runbook

**When:** Ask Q stops replying on prod · **Host:** multihost (`tasks.decisionsciencecorp.com`) + moya (`broca-q`)

---

## 1. Quick symptom check

| Symptom | Likely layer |
|---------|----------------|
| Bubble missing on `/admin/*` | PHP embed / `TASKS_Q_BRIDGE_ENABLED` |
| Send spins forever, no Q reply | Broca `broca-q` on moya or bridge DB queue |
| 401 / login errors in widget | Tasks session cookie / HTTPS |
| 429 in network tab | Rate limits (`public/q-bridge/config/settings.php`) |

---

## 2. multihost — PHP bridge (read-only first)

```bash
ssh multihost
tail -n 80 /var/www/tasks.decisionsciencecorp.com/q-bridge/logs/api.log
ls -la /var/www/tasks.decisionsciencecorp.com/db/q_bridge_webchat.db
```

Seed a test message (from repo on box):

```bash
cd /root/repos/tasks.decisionsciencecorp.com
php tools/e2e_q_bridge_seed_message.php
```

Poll for response (needs poll API key from env / `q_bridge_poll_api_key.txt`):

```bash
curl -sS -G -H "Authorization: Bearer $POLL_KEY" \
  "https://tasks.decisionsciencecorp.com/q-bridge/api/v1/index.php?action=responses&session_id=otto-e2e-..."
```

---

## 3. moya — Broca + Letta

SSH: `sshpass -f ~/.ssh/athena-moya.pass ssh -p 7837 rizzn@sanctum.zero1.network`

```bash
screen -ls | grep broca-q
curl -sS http://127.0.0.1:8284/v1/health/
KEY=$(grep '^AGENT_API_KEY=' ~/sanctum/agents/athena/broca/.env | cut -d= -f2-)
curl -sS -H "Authorization: Bearer $KEY" http://127.0.0.1:8284/v1/agents/ | jq '.[] | select(.name=="Q_Vernal") | .id'
```

**Restart broca-q** (only when Mark/Otto scoped):

```bash
cd ~/sanctum/agents/q/broca && ./restart.sh   # or screen -r broca-q
```

**Letta agent:** `agent-64e52a67-537a-4def-8402-d4bdccc47395` · tools: chatter profile per `docs/Q-VERNAL-TOOL-PROFILE.md`.

---

## 4. Scheduled / manual prod smoke

```bash
bash tools/q_prod_smoke.sh
```

Runs bridge seed (on multihost when `Q_PROD_SMOKE_SSH=multihost`) + optional `ask_q_prod_verify.py`.

---

## 5. Rate limits

Per-user caps on widget routes: `messages`, `responses`, `history`, `user_session`. **Admins** tune limits at **Settings → Ask Q** (`/admin/settings.php?tab=ask-q`); stored in Tasks `app_settings` key `q_bridge.rate_limits`. File defaults in `q-bridge/includes/rate_limit_config.php`.

Clear stuck counters (break-glass): backup `q_bridge_webchat.db`, then `DELETE FROM rate_limits WHERE ip_address LIKE 'user:%';`

---

## References

- Migration: `tools/q_moya_migration/README.md`
- job_rules: `docs/Q-VERNAL-JOB-RULES.md` · `tools/moya_update_q_job_rules.py`
- Tool attach: `tools/moya_patch_q_chatter_tool_ids.py`
