<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$status = $_GET['status'] ?? null;
$assignedToUserId = $_GET['assigned_to_user_id'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

$tasks = listTasks([
    'status' => $status,
    'assigned_to_user_id' => $assignedToUserId,
    'limit' => $limit,
    'offset' => $offset,
]);

jsonResponse([
    'success' => true,
    'tasks' => $tasks,
    'count' => count($tasks),
]);

