<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_auth.php';

// Mention autocomplete: returns active users whose username matches the
// supplied query. Accepts session OR API-key auth so admin pages can call
// it directly without exposing a key in the browser.

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET') {
    apiError('method_not_allowed', 'GET required', 405);
}

$user = null;
if (isLoggedIn()) {
    $user = getCurrentUser();
} else {
    $apiKey = getApiKeyFromRequest();
    if ($apiKey) {
        $user = validateApiKeyAndGetUser($apiKey);
    }
}
if (!$user) {
    apiError('auth.unauthenticated', 'Authentication required', 401);
}

$q = trim((string)($_GET['q'] ?? ''));
$limitRaw = isset($_GET['limit']) ? (int)$_GET['limit'] : 8;
$limit = max(1, min(25, $limitRaw));

if ($q === '') {
    apiSuccess(['users' => [], 'count' => 0, 'q' => '']);
}
if (strlen($q) > 64) {
    $q = substr($q, 0, 64);
}

// Username pattern: alnum, underscore, dot, hyphen. Keep this strict so we
// don't run wildcard SQL on free-form input that contains LIKE metachars.
if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $q)) {
    apiSuccess(['users' => [], 'count' => 0, 'q' => $q]);
}

$db = getDbConnection();
$stmt = $db->prepare("
    SELECT id, username, role, person_kind, org_id
    FROM users
    WHERE is_active = 1
      AND username LIKE :pat
    ORDER BY
      CASE WHEN username = :exact THEN 0
           WHEN username LIKE :prefix THEN 1
           ELSE 2 END,
      length(username) ASC,
      username ASC
    LIMIT :lim
");
$stmt->bindValue(':pat', '%' . $q . '%', SQLITE3_TEXT);
$stmt->bindValue(':prefix', $q . '%', SQLITE3_TEXT);
$stmt->bindValue(':exact', $q, SQLITE3_TEXT);
$stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
$res = $stmt->execute();

$users = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $users[] = [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'role' => normalizeRole((string)($row['role'] ?? 'member')) ?? 'member',
        'person_kind' => normalizePersonKind($row['person_kind'] ?? 'team_member'),
        'org_id' => $row['org_id'] !== null ? (int)$row['org_id'] : null,
    ];
}

apiSuccess([
    'users' => $users,
    'count' => count($users),
    'q' => $q,
]);
