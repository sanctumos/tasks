<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_auth.php';

// Mention autocomplete: returns users the caller may see (same directory ACL as
// assignee pickers / omnibox People). Session OR API-key auth.

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
        if ($user) {
            $rateState = checkApiRateLimit($apiKey);
            setRateLimitHeaders($rateState);
            if (empty($rateState['allowed'])) {
                header('Retry-After: ' . (int)($rateState['retry_after'] ?? 1));
                apiError(
                    'rate_limited',
                    'Rate limit exceeded. Slow down and retry later.',
                    429,
                    ['retry_after' => (int)($rateState['retry_after'] ?? 1)],
                    ['rate_limit' => $rateState]
                );
            }
        }
    }
}
if (!$user) {
    apiError('auth.unauthenticated', 'Authentication required', 401);
}

$q = trim((string)($_GET['q'] ?? ''));
$limitRaw = isset($_GET['limit']) ? (int)$_GET['limit'] : 8;
$limit = max(1, min(25, $limitRaw));

$found = searchUsersVisibleForViewer($user, $q, $limit, 0);
$users = [];
foreach ($found['users'] as $row) {
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
    'q' => $found['q'],
]);
