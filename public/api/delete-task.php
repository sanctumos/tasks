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

$existing = getTaskById($id);
if (!$existing) {
    jsonResponse(['success' => false, 'error' => 'Task not found'], 404);
}

deleteTask($id);

jsonResponse(['success' => true]);

