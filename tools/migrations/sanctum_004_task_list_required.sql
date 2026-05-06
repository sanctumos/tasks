-- Idempotent: seed "General" todo list for every project that has none,
-- then backfill tasks.list_id for rows that already have project_id.
-- Mirrors applySanctumSchemaMigrations() in public/includes/config.php.

INSERT INTO todo_lists (project_id, name, sort_order)
SELECT p.id, 'General', 0
FROM projects p
WHERE NOT EXISTS (SELECT 1 FROM todo_lists tl WHERE tl.project_id = p.id);

UPDATE tasks
SET list_id = (
    SELECT tl.id FROM todo_lists tl
    WHERE tl.project_id = tasks.project_id
    ORDER BY tl.sort_order ASC, tl.id ASC LIMIT 1
),
updated_at = CURRENT_TIMESTAMP
WHERE project_id IS NOT NULL
  AND list_id IS NULL
  AND EXISTS (
      SELECT 1 FROM todo_lists tl2 WHERE tl2.project_id = tasks.project_id
  );
