-- Idempotent schema: organizations, users.org_id / person_kind, projects, project_members, tasks.project_id
-- Canonical bootstrap is PHP initializeDatabase(); Python mirror bootstrap lives in sanctumos/py-tasks. This file documents the same DDL for operators who prefer raw SQL.

CREATE TABLE IF NOT EXISTS organizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    settings_json TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- SQLite: ADD COLUMN only if missing — prefer app bootstrap or PRAGMA table_info guard when running by hand.

CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    org_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    client_visible INTEGER NOT NULL DEFAULT 0,
    all_access INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(org_id) REFERENCES organizations(id)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_projects_org_name ON projects(org_id, name);
CREATE INDEX IF NOT EXISTS idx_projects_org_status ON projects(org_id, status);

CREATE TABLE IF NOT EXISTS project_members (
    project_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(project_id, user_id),
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_project_members_user ON project_members(user_id);

CREATE TABLE IF NOT EXISTS todo_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_todo_lists_project ON todo_lists(project_id);

CREATE TABLE IF NOT EXISTS user_project_pins (
    user_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(user_id, project_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_user_project_pins_user ON user_project_pins(user_id);

-- After: ALTER TABLE users ADD COLUMN org_id ...; ADD COLUMN person_kind ...;
-- After: ALTER TABLE tasks ADD COLUMN project_id INTEGER DEFAULT NULL;
-- ALTER TABLE tasks ADD COLUMN list_id INTEGER DEFAULT NULL;
-- CREATE INDEX IF NOT EXISTS idx_tasks_project_id ON tasks(project_id);
-- CREATE INDEX IF NOT EXISTS idx_tasks_list_id ON tasks(list_id);
