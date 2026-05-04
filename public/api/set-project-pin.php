<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
if ($projectId <= 0) {
    apiError('validation.invalid_project_id', 'Missing or invalid project_id', 400);
}

$pinned = array_key_exists('pinned', $body) ? (bool)$body['pinned'] : true;
$sortOrder = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;

if (!$pinned) {
    removeUserProjectPin((int)$user['id'], $projectId);
} else {
    $result = setUserProjectPin((int)$user['id'], $projectId, $sortOrder);
    if (!$result['success']) {
        apiError('project.pin_failed', $result['error'] ?? 'Could not pin project', 400);
    }
}

$pins = listUserProjectPinsForUser($user, 200);
apiSuccess(['pins' => $pins]);
