<?php
/**
 * Project Doors — external tool links (Figma, Drive, etc.) scoped per directory project.
 */

/**
 * @return array{success:bool, url?:string, error?:string}
 */
function normalizeProjectDoorUrl(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return ['success' => false, 'error' => 'URL is required'];
    }
    if (!preg_match('#^https?://#i', $url)) {
        return ['success' => false, 'error' => 'URL must start with http:// or https://'];
    }
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['host'])) {
        return ['success' => false, 'error' => 'Invalid URL'];
    }
    $scheme = strtolower((string)($parsed['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['success' => false, 'error' => 'Only http and https URLs are allowed'];
    }
    if (!empty($parsed['user']) || !empty($parsed['pass'])) {
        return ['success' => false, 'error' => 'URL must not contain embedded credentials'];
    }
    return ['success' => true, 'url' => $url];
}

function getProjectDoorById(int $doorId): ?array
{
    if ($doorId <= 0) {
        return null;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('
        SELECT d.id, d.project_id, d.title, d.url, d.description, d.sort_order,
               d.created_by_user_id, d.created_at, d.updated_at,
               u.username AS created_by_username
        FROM project_doors d
        LEFT JOIN users u ON u.id = d.created_by_user_id
        WHERE d.id = :id
        LIMIT 1
    ');
    $stmt->bindValue(':id', $doorId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['project_id'] = (int)$row['project_id'];
    $row['sort_order'] = (int)$row['sort_order'];
    $row['created_by_user_id'] = (int)$row['created_by_user_id'];
    return $row;
}

/**
 * @return array<int, array<string, mixed>>
 */
function listProjectDoorsForProject(array $userRow, int $projectId): array
{
    $proj = getDirectoryProjectById($projectId);
    if (!$proj || !userCanAccessDirectoryProject($userRow, $proj)) {
        return [];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('
        SELECT d.id, d.project_id, d.title, d.url, d.description, d.sort_order,
               d.created_by_user_id, d.created_at, d.updated_at,
               u.username AS created_by_username
        FROM project_doors d
        LEFT JOIN users u ON u.id = d.created_by_user_id
        WHERE d.project_id = :p
        ORDER BY d.sort_order ASC, d.title COLLATE NOCASE ASC, d.id ASC
    ');
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = (int)$row['project_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['created_by_user_id'] = (int)$row['created_by_user_id'];
        $rows[] = $row;
    }
    return $rows;
}

/**
 * @param array{title?:string, url?:string, description?:?string} $fields
 * @return array{success:bool, id?:int, error?:string}
 */
function createProjectDoor(int $userId, int $projectId, array $fields): array
{
    $proj = getDirectoryProjectById($projectId);
    $actor = getUserById($userId, false);
    if (!$proj || !$actor || !userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission'];
    }
    $title = trim((string)($fields['title'] ?? ''));
    if ($title === '') {
        return ['success' => false, 'error' => 'Title is required'];
    }
    if (strlen($title) > 200) {
        return ['success' => false, 'error' => 'Title must be 200 characters or fewer'];
    }
    $urlCheck = normalizeProjectDoorUrl((string)($fields['url'] ?? ''));
    if (!$urlCheck['success']) {
        return ['success' => false, 'error' => $urlCheck['error'] ?? 'Invalid URL'];
    }
    $description = isset($fields['description']) ? trim((string)$fields['description']) : '';
    if (strlen($description) > 500) {
        return ['success' => false, 'error' => 'Description must be 500 characters or fewer'];
    }
    $db = getDbConnection();
    $mxStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort FROM project_doors WHERE project_id = :p');
    $mxStmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $mxRow = $mxStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $maxSort = (int)($mxRow['next_sort'] ?? 0);
    $stmt = $db->prepare('
        INSERT INTO project_doors (project_id, title, url, description, sort_order, created_by_user_id)
        VALUES (:p, :t, :u, :d, :s, :c)
    ');
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':t', $title, SQLITE3_TEXT);
    $stmt->bindValue(':u', $urlCheck['url'], SQLITE3_TEXT);
    $stmt->bindValue(':d', $description !== '' ? $description : null, $description !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $stmt->bindValue(':s', $maxSort, SQLITE3_INTEGER);
    $stmt->bindValue(':c', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    $id = (int)$db->lastInsertRowID();
    createAuditLog($userId, 'project_door.create', 'project_door', (string)$id, [
        'project_id' => $projectId,
        'title' => $title,
        'url' => $urlCheck['url'],
    ]);
    return ['success' => true, 'id' => $id];
}

/**
 * @param array{title?:string, url?:string, description?:?string, sort_order?:int} $fields
 * @return array{success:bool, error?:string}
 */
function updateProjectDoor(int $userId, int $doorId, array $fields): array
{
    $door = getProjectDoorById($doorId);
    if (!$door) {
        return ['success' => false, 'error' => 'Door not found'];
    }
    $proj = getDirectoryProjectById((int)$door['project_id']);
    $actor = getUserById($userId, false);
    if (!$proj || !$actor || !userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission'];
    }

    $sets = [];
    $bind = [':id' => [$doorId, SQLITE3_INTEGER]];

    if (array_key_exists('title', $fields)) {
        $title = trim((string)$fields['title']);
        if ($title === '') {
            return ['success' => false, 'error' => 'Title is required'];
        }
        if (strlen($title) > 200) {
            return ['success' => false, 'error' => 'Title must be 200 characters or fewer'];
        }
        $sets[] = 'title = :title';
        $bind[':title'] = [$title, SQLITE3_TEXT];
    }
    if (array_key_exists('url', $fields)) {
        $urlCheck = normalizeProjectDoorUrl((string)$fields['url']);
        if (!$urlCheck['success']) {
            return ['success' => false, 'error' => $urlCheck['error'] ?? 'Invalid URL'];
        }
        $sets[] = 'url = :url';
        $bind[':url'] = [$urlCheck['url'], SQLITE3_TEXT];
    }
    if (array_key_exists('description', $fields)) {
        $description = trim((string)$fields['description']);
        if (strlen($description) > 500) {
            return ['success' => false, 'error' => 'Description must be 500 characters or fewer'];
        }
        $sets[] = 'description = :description';
        $bind[':description'] = [$description !== '' ? $description : null, $description !== '' ? SQLITE3_TEXT : SQLITE3_NULL];
    }
    if (array_key_exists('sort_order', $fields)) {
        $sets[] = 'sort_order = :sort_order';
        $bind[':sort_order'] = [(int)$fields['sort_order'], SQLITE3_INTEGER];
    }
    if ($sets === []) {
        return ['success' => true];
    }
    $sets[] = 'updated_at = CURRENT_TIMESTAMP';
    $db = getDbConnection();
    $stmt = $db->prepare('UPDATE project_doors SET ' . implode(', ', $sets) . ' WHERE id = :id');
    foreach ($bind as $param => $pair) {
        $stmt->bindValue($param, $pair[0], $pair[1]);
    }
    $stmt->execute();
    createAuditLog($userId, 'project_door.update', 'project_door', (string)$doorId, ['project_id' => (int)$door['project_id']]);
    return ['success' => true];
}

function deleteProjectDoor(int $userId, int $doorId): array
{
    $door = getProjectDoorById($doorId);
    if (!$door) {
        return ['success' => false, 'error' => 'Door not found'];
    }
    $proj = getDirectoryProjectById((int)$door['project_id']);
    $actor = getUserById($userId, false);
    if (!$proj || !$actor || !userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('DELETE FROM project_doors WHERE id = :id');
    $stmt->bindValue(':id', $doorId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($userId, 'project_door.delete', 'project_door', (string)$doorId, ['project_id' => (int)$door['project_id']]);
    return ['success' => true];
}
