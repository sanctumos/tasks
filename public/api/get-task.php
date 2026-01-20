<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    jsonResponse(['success' => false, 'error' => 'Missing or invalid id'], 400);
}

$task = getTaskById($id);
if (!$task) {
    jsonResponse(['success' => false, 'error' => 'Task not found'], 404);
}

jsonResponse(['success' => true, 'task' => $task]);

