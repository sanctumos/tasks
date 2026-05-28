# Q Vernal — lettatest → moya (223) migration

Follows **`docs/sanctum-letta-monday-agent-migration-runbook-2026-04-30.md`** (agent `.af` import + Broca cutover §7.6).

## Cutover summary (2026-05-28)

| Item | Value |
|------|--------|
| Source Letta | lettatest `127.0.0.1:18283`, `agent-4afbed9b-a6c0-403f-8499-4fb75b83c095` |
| Target Letta | moya `127.0.0.1:8284`, **`agent-64e52a67-537a-4def-8402-d4bdccc47395`** |
| Messages imported | 257 |
| Blocks | `persona`, `job_rules` (ephemeral `alex_psf_shopify_install` dropped) |
| SMCP tools | 49 × `q_vernal_tasks__*` |
| Broca | `~/sanctum/agents/q/broca/`, screen **`broca-q`** |
| SMCP | `~/sanctum/agents/q/smcp/` |
| Work dir on moya | `~/sanctum/migration-q-moya-20260528/` |

## Scripts

| Script | Role |
|--------|------|
| `sanitize_q_export.py` | Prepare lettatest export for import |
| `repair_q_moya_blocks_tools.py` | Copy blocks/tools from source → target (HTTP) |
| `rehydrate_q_broca_identities.py` | Recreate Letta identities for `q_vernal_webchat` profiles |
| `upgrade_q_broca_to_athena_broca3.sh` | Align Broca tree with Athena broca-3 on moya |
| `../moya-install-q-smcp.sh` | SMCP + plugin sync from workspace |
| `../moya_attach_q_smcp.py` | Register MCP server + attach tools |
| `../moya-start-q-broca.sh` | Start `broca-q` screen |

## Smoke

Prod bridge seed on multihost:

```bash
cd /root/repos/tasks.decisionsciencecorp.com && php tools/e2e_q_bridge_seed_message.php
```

Verify response:

```bash
curl -sS -G -H "Authorization: Bearer $POLL_KEY" \
  "https://tasks.decisionsciencecorp.com/q-bridge/api/v1/index.php?action=responses&session_id=otto-e2e-..."
```

## lettatest

Leave **broca-q stopped** on lettatest after cutover. Rehearsal Letta agent remains for rollback reference only.
