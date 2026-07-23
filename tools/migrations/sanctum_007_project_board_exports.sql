-- Idempotent: project_board_exports for archived board ZIP snapshots.
-- Also applied via applySanctumSchemaMigrations() in public/includes/config.php.

CREATE TABLE IF NOT EXISTS project_board_exports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    requested_by_user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    storage_rel_path TEXT DEFAULT NULL,
    byte_size INTEGER DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY(requested_by_user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_project_board_exports_project_created
    ON project_board_exports(project_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_project_board_exports_status
    ON project_board_exports(status);
