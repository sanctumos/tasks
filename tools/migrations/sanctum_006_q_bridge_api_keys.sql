-- Idempotent: hidden per-user API keys for Q Vernal webchat bridge (key_kind = q_bridge).
-- Applied via applySanctumSchemaMigrations() in public/includes/config.php.
-- Manual backfill: php tools/backfill_q_bridge_api_keys.php

-- ALTER TABLE api_keys ADD COLUMN key_kind TEXT NOT NULL DEFAULT 'standard';
