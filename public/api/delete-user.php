<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireAdminApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$targetUserId = isset($body['id']) ? (int)$body['id'] : 0;
if ($targetUserId <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}
if ($targetUserId === (int)$apiUser['id']) {
    apiError('validation.self_delete_not_allowed', 'You cannot delete your own user', 400);
}

$force = !empty($body['force']);

$result = deleteUser($targetUserId, (int)$apiUser['id'], $force);
if (!$result['success']) {
    $statusCode = isset($result['references']) ? 409 : 400;
    apiError(
        'user.delete_failed',
        $result['error'] ?? 'Failed to delete user',
        $statusCode,
        isset($result['references']) ? ['references' => $result['references']] : []
    );
}

apiSuccess([
    'id' => $targetUserId,
    'already_deleted' => !empty($result['already_deleted']),
    'references' => $result['references'] ?? [],
]);
