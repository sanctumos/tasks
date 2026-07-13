<?php
/**
 * Delete a todo list by id.
 *
 * POST JSON: id (todo_lists.id)
 * Refuses if the list has tasks or is the only list on its project.
 */
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$listId = isset($body['id']) ? (int)$body['id'] : 0;
if ($listId <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$result = deleteTodoList((int)$user['id'], $listId);
if (!$result['success']) {
    $status = 400;
    if (($result['error'] ?? '') === 'Insufficient permission') {
        $status = 403;
    }
    apiError(
        'todo_list.delete_failed',
        $result['error'] ?? 'Delete failed',
        $status,
        isset($result['task_count']) ? ['task_count' => (int)$result['task_count']] : []
    );
}

apiSuccess([
    'deleted' => true,
    'id' => $listId,
    'already_deleted' => !empty($result['already_deleted']),
]);
