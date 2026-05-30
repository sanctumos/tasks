-- Idempotent: per-user skin override (nullable = use org default).
-- Org default lives in organizations.settings_json → default_skin_slug (app layer).

-- Applied via initializeDatabase() ensureColumnExists; this file documents the migration.
