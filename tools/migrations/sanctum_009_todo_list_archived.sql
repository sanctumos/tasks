-- Hide completed phases: manual list archive (archived_at set = hidden from default Lists view).
-- Idempotent via ensureColumnExists in applySanctumSchemaMigrations; this file documents intent.

-- ALTER TABLE todo_lists ADD COLUMN archived_at DATETIME DEFAULT NULL;
