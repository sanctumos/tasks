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

$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$existing = getProjectDoorById($id);
if (!$existing) {
    apiError('project_door.not_found', 'Door not found', 404);
}
$proj = getDirectoryProjectById((int)$existing['project_id']);
if (!$proj || !userCanAccessDirectoryProject($user, $proj)) {
    apiError('project_door.not_found', 'Door not found', 404);
}

$result = deleteProjectDoor((int)$user['id'], $id);
if (!$result['success']) {
    $code = ($result['error'] ?? '') === 'Insufficient permission' ? 403 : 400;
    apiError('project_door.delete_failed', $result['error'] ?? 'Delete failed', $code);
}

apiSuccess(['deleted' => true, 'id' => $id]);
