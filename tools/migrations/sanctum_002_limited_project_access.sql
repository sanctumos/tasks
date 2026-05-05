-- Idempotent: per-user flag to enforce project membership (and all_access) even for manager role.
-- Applied automatically via PHP applySanctumSchemaMigrations(); schema parity mirrored in sanctumos/py-tasks.
-- Run manually if you maintain schema only via SQL:

-- ALTER TABLE users ADD COLUMN limited_project_access INTEGER NOT NULL DEFAULT 0;
