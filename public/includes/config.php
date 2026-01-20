<?php
// tasks.technonomicon.net - Configuration + DB bootstrap

// Database configuration
define('DB_PATH', __DIR__ . '/../../db/tasks.db');
define('DB_TIMEOUT', 30);

// Security settings
define('SESSION_NAME', 'technonomicon_tasks');
define('SESSION_LIFETIME', 3600); // 1 hour
define('PASSWORD_COST', 12); // bcrypt cost

// Initialize session (admin UI uses session auth)
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('UTC');

function getDbConnection() {
    try {
        $db = new SQLite3(DB_PATH);
        $db->enableExceptions(true);
        $db->busyTimeout(DB_TIMEOUT * 1000);
        $db->exec('PRAGMA foreign_keys = ON;');
        return $db;
    } catch (Exception $e) {
        http_response_code(500);
        die('Database connection failed: ' . $e->getMessage());
    }
}

function columnExists($db, $table, $column) {
    $result = $db->query("PRAGMA table_info($table)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $column) {
            return true;
        }
    }
    return false;
}

function ensureColumnExists($db, $table, $column, $definition) {
    if (!columnExists($db, $table, $column)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

function ensureIndexExists($db, $indexName, $sql) {
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'index' AND name = :name");
    $stmt->bindValue(':name', $indexName, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        $db->exec($sql);
    }
}

function initializeDatabase() {
    $db = getDbConnection();

    // Users
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'admin',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // API Keys (each key maps to a user)
    $db->exec("
        CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            key_name TEXT NOT NULL,
            api_key TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used DATETIME,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )
    ");

    // Tasks
    $db->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'todo',
            created_by_user_id INTEGER NOT NULL,
            assigned_to_user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id),
            FOREIGN KEY(assigned_to_user_id) REFERENCES users(id)
        )
    ");

    // Helpful indexes
    ensureIndexExists($db, 'idx_tasks_status', 'CREATE INDEX idx_tasks_status ON tasks(status)');
    ensureIndexExists($db, 'idx_tasks_assigned_to', 'CREATE INDEX idx_tasks_assigned_to ON tasks(assigned_to_user_id)');
    ensureIndexExists($db, 'idx_api_keys_user', 'CREATE INDEX idx_api_keys_user ON api_keys(user_id)');

    // Default admin user (matches technonomicon.net convention)
    $result = $db->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        $defaultHash = password_hash('go0dp4ssw0rd', PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', :hash, 'admin')");
        $stmt->bindValue(':hash', $defaultHash, SQLITE3_TEXT);
        $stmt->execute();
    }

    // Ensure a default API key exists for admin, persisted in db/api_key.txt
    $adminRes = $db->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
    $admin = $adminRes->fetchArray(SQLITE3_ASSOC);
    if ($admin && isset($admin['id'])) {
        $adminId = (int)$admin['id'];

        $apiKeyFile = __DIR__ . '/../../db/api_key.txt';
        $apiKey = null;
        if (file_exists($apiKeyFile)) {
            $apiKey = trim((string)@file_get_contents($apiKeyFile));
            if ($apiKey === '') {
                $apiKey = null;
            }
        }

        if ($apiKey === null) {
            $apiKey = bin2hex(random_bytes(32));
            // Best-effort persist; if this fails, the key still exists in DB.
            @file_put_contents($apiKeyFile, $apiKey);
        }

        // Ensure the key is present in the api_keys table and linked to admin.
        $stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = :key LIMIT 1");
        $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
        $res = $stmt->execute();
        $existingKey = $res->fetchArray(SQLITE3_ASSOC);
        if (!$existingKey) {
            $ins = $db->prepare("INSERT INTO api_keys (user_id, key_name, api_key) VALUES (:uid, :name, :key)");
            $ins->bindValue(':uid', $adminId, SQLITE3_INTEGER);
            $ins->bindValue(':name', 'default', SQLITE3_TEXT);
            $ins->bindValue(':key', $apiKey, SQLITE3_TEXT);
            $ins->execute();
        }
    }
}

