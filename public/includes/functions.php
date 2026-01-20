<?php
require_once __DIR__ . '/config.php';

// Ensure DB is initialized when helpers are loaded
initializeDatabase();

function nowUtc() {
    return gmdate('Y-m-d H:i:s');
}

function sanitizeStatus($status) {
    $allowed = ['todo', 'doing', 'done'];
    if (!in_array($status, $allowed, true)) {
        return null;
    }
    return $status;
}

// --------------------
// Users
// --------------------
function getUserById($id) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id, username, role, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    return $res->fetchArray(SQLITE3_ASSOC) ?: null;
}

function getUserByUsername($username) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id, username, role, created_at FROM users WHERE username = :u LIMIT 1");
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $res = $stmt->execute();
    return $res->fetchArray(SQLITE3_ASSOC) ?: null;
}

function listUsers() {
    $db = getDbConnection();
    $res = $db->query("SELECT id, username, role, created_at FROM users ORDER BY username ASC");
    $users = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}

// --------------------
// API keys
// --------------------
function createApiKeyForUser($userId, $keyName) {
    $db = getDbConnection();
    $apiKey = bin2hex(random_bytes(32)); // 64 char hex

    $stmt = $db->prepare("INSERT INTO api_keys (user_id, key_name, api_key) VALUES (:uid, :name, :key)");
    $stmt->bindValue(':uid', (int)$userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $keyName, SQLITE3_TEXT);
    $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
    $stmt->execute();

    return $apiKey;
}

function validateApiKeyAndGetUser($apiKey) {
    $db = getDbConnection();

    $stmt = $db->prepare("
        SELECT api_keys.id as api_key_id, api_keys.user_id as user_id
        FROM api_keys
        WHERE api_keys.api_key = :key
        LIMIT 1
    ");
    $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }

    $update = $db->prepare("UPDATE api_keys SET last_used = CURRENT_TIMESTAMP WHERE id = :id");
    $update->bindValue(':id', (int)$row['api_key_id'], SQLITE3_INTEGER);
    $update->execute();

    $user = getUserById((int)$row['user_id']);
    if (!$user) {
        return null;
    }

    return $user;
}

function getAllApiKeys() {
    $db = getDbConnection();
    $res = $db->query("
        SELECT 
            ak.id,
            ak.key_name,
            ak.api_key,
            ak.created_at,
            ak.last_used,
            u.username as user_username
        FROM api_keys ak
        JOIN users u ON u.id = ak.user_id
        ORDER BY ak.created_at DESC
    ");
    $keys = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $keys[] = $row;
    }
    return $keys;
}

function deleteApiKey($id) {
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM api_keys WHERE id = :id");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $stmt->execute();
    return true;
}

// --------------------
// Tasks
// --------------------
function createTask($title, $status, $createdByUserId, $assignedToUserId = null, $body = null) {
    $title = trim((string)$title);
    if ($title === '') {
        return ['success' => false, 'error' => 'Title is required'];
    }

    $status = $status ? sanitizeStatus($status) : 'todo';
    if ($status === null) {
        return ['success' => false, 'error' => 'Invalid status'];
    }

    $body = $body !== null ? trim((string)$body) : null;
    if ($body === '') {
        $body = null;
    }

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO tasks (title, body, status, created_by_user_id, assigned_to_user_id, created_at, updated_at)
        VALUES (:title, :body, :status, :cuid, :auid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    if ($body === null) {
        $stmt->bindValue(':body', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':body', $body, SQLITE3_TEXT);
    }
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':cuid', (int)$createdByUserId, SQLITE3_INTEGER);
    if ($assignedToUserId === null) {
        $stmt->bindValue(':auid', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':auid', (int)$assignedToUserId, SQLITE3_INTEGER);
    }
    $stmt->execute();

    $id = (int)$db->lastInsertRowID();
    return ['success' => true, 'id' => $id];
}

function getTaskById($id) {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT
          t.*,
          cu.username AS created_by_username,
          au.username AS assigned_to_username
        FROM tasks t
        JOIN users cu ON cu.id = t.created_by_user_id
        LEFT JOIN users au ON au.id = t.assigned_to_user_id
        WHERE t.id = :id
        LIMIT 1
    ");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    return $res->fetchArray(SQLITE3_ASSOC) ?: null;
}

function listTasks($filters = []) {
    $db = getDbConnection();

    $where = [];
    $params = [];

    if (!empty($filters['status'])) {
        $s = sanitizeStatus($filters['status']);
        if ($s !== null) {
            $where[] = 't.status = :status';
            $params[':status'] = [$s, SQLITE3_TEXT];
        }
    }

    if (array_key_exists('assigned_to_user_id', $filters) && $filters['assigned_to_user_id'] !== null && $filters['assigned_to_user_id'] !== '') {
        $where[] = 't.assigned_to_user_id = :assigned_to_user_id';
        $params[':assigned_to_user_id'] = [(int)$filters['assigned_to_user_id'], SQLITE3_INTEGER];
    }

    $limit = isset($filters['limit']) ? (int)$filters['limit'] : 100;
    $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;
    if ($limit <= 0) $limit = 100;
    if ($limit > 500) $limit = 500;
    if ($offset < 0) $offset = 0;

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
          t.*,
          cu.username AS created_by_username,
          au.username AS assigned_to_username
        FROM tasks t
        JOIN users cu ON cu.id = t.created_by_user_id
        LEFT JOIN users au ON au.id = t.assigned_to_user_id
        $whereSql
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $res = $stmt->execute();

    $tasks = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tasks[] = $row;
    }
    return $tasks;
}

function updateTask($id, $fields = []) {
    $id = (int)$id;
    if ($id <= 0) {
        return ['success' => false, 'error' => 'Invalid id'];
    }

    $sets = [];
    $params = [':id' => [$id, SQLITE3_INTEGER]];

    if (array_key_exists('title', $fields)) {
        $title = trim((string)$fields['title']);
        if ($title === '') {
            return ['success' => false, 'error' => 'Title cannot be empty'];
        }
        $sets[] = 'title = :title';
        $params[':title'] = [$title, SQLITE3_TEXT];
    }

    if (array_key_exists('status', $fields)) {
        $status = sanitizeStatus($fields['status']);
        if ($status === null) {
            return ['success' => false, 'error' => 'Invalid status'];
        }
        $sets[] = 'status = :status';
        $params[':status'] = [$status, SQLITE3_TEXT];
    }

    if (array_key_exists('assigned_to_user_id', $fields)) {
        if ($fields['assigned_to_user_id'] === null || $fields['assigned_to_user_id'] === '') {
            $sets[] = 'assigned_to_user_id = :assigned_to_user_id';
            $params[':assigned_to_user_id'] = [null, SQLITE3_NULL];
        } else {
            $sets[] = 'assigned_to_user_id = :assigned_to_user_id';
            $params[':assigned_to_user_id'] = [(int)$fields['assigned_to_user_id'], SQLITE3_INTEGER];
        }
    }

    if (array_key_exists('body', $fields)) {
        $body = $fields['body'] !== null ? trim((string)$fields['body']) : null;
        if ($body === '') {
            $body = null;
        }
        $sets[] = 'body = :body';
        if ($body === null) {
            $params[':body'] = [null, SQLITE3_NULL];
        } else {
            $params[':body'] = [$body, SQLITE3_TEXT];
        }
    }

    if (!$sets) {
        return ['success' => false, 'error' => 'No fields to update'];
    }

    $sets[] = 'updated_at = CURRENT_TIMESTAMP';
    $sql = 'UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = :id';

    $db = getDbConnection();
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $stmt->execute();

    return ['success' => true];
}

function deleteTask($id) {
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM tasks WHERE id = :id");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $stmt->execute();
    return ['success' => true];
}

