<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$body = readJsonBody();
if ($body === null) {
    jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);
}

$title = $body['title'] ?? '';
$status = $body['status'] ?? null;
$assignedToUserId = $body['assigned_to_user_id'] ?? null;

$result = createTask($title, $status, (int)$user['id'], $assignedToUserId);
if (!$result['success']) {
    jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Create failed'], 400);
}

$task = getTaskById((int)$result['id']);

jsonResponse([
    'success' => true,
    'task' => $task,
]);

