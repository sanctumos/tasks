<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_auth.php';

// Omnibox + agents: session OR API-key auth (mirrors search-users.php).

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('method.not_allowed', 'Use GET for this endpoint', 405);
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

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

$result = searchOmniboxForUser($user, $q, $limit);
if (isset($result['success']) && $result['success'] === false) {
    $http = (int)($result['http'] ?? 400);
    apiError('validation.invalid_q', (string)($result['error'] ?? 'Invalid q'), $http);
}

apiSuccess($result);
