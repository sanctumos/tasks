-- Idempotent: content_hash for duplicate board-export suppression.
-- Also applied via applySanctumSchemaMigrations() in public/includes/config.php.

-- SQLite lacks IF NOT EXISTS for ADD COLUMN in older versions; app migration uses ensureColumnExists.
-- Safe to run once on a DB that already has project_board_exports:

ALTER TABLE project_board_exports ADD COLUMN content_hash TEXT DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_project_board_exports_project_hash
    ON project_board_exports(project_id, content_hash);
