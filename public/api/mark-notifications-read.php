<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

if (!empty($body['all']) && ($body['all'] === true || $body['all'] === 1 || $body['all'] === '1')) {
    markAllNotificationsRead($uid);
    apiSuccess([
        'marked_all' => true,
        'unread_count' => countUnreadNotifications($uid),
    ]);
}

$ids = $body['ids'] ?? ($body['id'] ?? null);
if ($ids !== null && !is_array($ids)) {
    $ids = [$ids];
}
$ids = is_array($ids) ? $ids : [];
$ids = array_filter(array_map('intval', $ids));
if ($ids === []) {
    apiError('validation.missing_ids', 'Provide id, ids, or all: true', 400);
}

markNotificationsRead($uid, $ids);
apiSuccess([
    'marked_ids' => $ids,
    'unread_count' => countUnreadNotifications($uid),
]);
