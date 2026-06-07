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

$fields = [];
if (array_key_exists('title', $body)) {
    $fields['title'] = (string)$body['title'];
}
if (array_key_exists('url', $body)) {
    $fields['url'] = (string)$body['url'];
}
if (array_key_exists('description', $body)) {
    $fields['description'] = (string)$body['description'];
}
if (array_key_exists('sort_order', $body)) {
    $fields['sort_order'] = (int)$body['sort_order'];
}

$result = updateProjectDoor((int)$user['id'], $id, $fields);
if (!$result['success']) {
    $code = ($result['error'] ?? '') === 'Insufficient permission' ? 403 : 400;
    apiError('project_door.update_failed', $result['error'] ?? 'Update failed', $code);
}

$door = getProjectDoorById($id);
apiSuccess(['project_door' => $door]);
