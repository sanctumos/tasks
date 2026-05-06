-- Idempotent: documents + document_comments tables for long-form markdown
-- reference material attached to a directory project, with a per-document
-- discussion thread. Modeled on Basecamp's Docs.
--
-- Applied automatically by PHP applySanctumSchemaMigrations() (config.php),
-- which runs on EVERY initializeDatabase() call (no $bootstrappedCore gate).
-- This file is the canonical recovery script for environments where the
-- gated bootstrap path was skipped (e.g. PHP-FPM workers that were already
-- bootstrapped before the documents code shipped).
--
-- Apply with:
--   sqlite3 /var/www/<host>/db/tasks.db < sanctum_003_documents.sql

CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    body TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_by_user_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY(created_by_user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_documents_project ON documents(project_id);
CREATE INDEX IF NOT EXISTS idx_documents_status  ON documents(status);
CREATE INDEX IF NOT EXISTS idx_documents_updated ON documents(updated_at);

CREATE TABLE IF NOT EXISTS document_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_document_comments_doc ON document_comments(document_id);
