<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$body = readJsonBody();
if ($body === null) {
    jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);
}

$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
    jsonResponse(['success' => false, 'error' => 'Missing or invalid id'], 400);
}

$fields = [];
if (array_key_exists('title', $body)) $fields['title'] = $body['title'];
if (array_key_exists('status', $body)) $fields['status'] = $body['status'];
if (array_key_exists('assigned_to_user_id', $body)) $fields['assigned_to_user_id'] = $body['assigned_to_user_id'];
if (array_key_exists('body', $body)) $fields['body'] = $body['body'];

$result = updateTask($id, $fields);
if (!$result['success']) {
    jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Update failed'], 400);
}

$task = getTaskById($id);
if (!$task) {
    jsonResponse(['success' => false, 'error' => 'Task not found'], 404);
}

jsonResponse(['success' => true, 'task' => $task]);

