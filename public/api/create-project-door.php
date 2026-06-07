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

$result = createProjectDoor((int)$user['id'], $projectId, [
    'title' => (string)($body['title'] ?? ''),
    'url' => (string)($body['url'] ?? ''),
    'description' => isset($body['description']) ? (string)$body['description'] : null,
]);
if (!$result['success']) {
    apiError('project_door.create_failed', $result['error'] ?? 'Create failed', 400);
}

$doors = listProjectDoorsForProject($user, $projectId);
apiSuccess(['project_doors' => $doors, 'id' => $result['id'] ?? null]);
