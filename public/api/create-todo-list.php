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
$name = isset($body['name']) ? (string)$body['name'] : '';
if ($projectId <= 0) {
    apiError('validation.invalid_project_id', 'Missing or invalid project_id', 400);
}

$result = createTodoList((int)$user['id'], $projectId, $name);
if (!$result['success']) {
    apiError('todo_list.create_failed', $result['error'] ?? 'Create failed', 400);
}

$dbLists = listTodoListsForProject($user, $projectId);
apiSuccess(['todo_lists' => $dbLists, 'id' => $result['id'] ?? null]);
