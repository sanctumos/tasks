<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();
$full = getUserById((int)$user['id'], false);
if (!$full) {
    apiError('auth.invalid_user', 'User not found', 401);
}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$userIdParam = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (($projectId > 0) === ($userIdParam > 0)) {
    apiError('validation.bad_request', 'Provide exactly one of project_id or user_id', 400);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;
$beforeId = $beforeId > 0 ? $beforeId : null;

if ($projectId > 0) {
    $proj = getDirectoryProjectById($projectId);
    if (!$proj || !userCanAccessDirectoryProject($full, $proj)) {
        apiError('auth.forbidden', 'Project not found', 404);
    }
    $rows = listDirectoryProjectActivity($projectId, $limit, $beforeId);
    $rows = array_map('activityFeedStripForApi', $rows);
    apiSuccess(['events' => $rows, 'count' => count($rows)]);
}

$rows = listUserActivityFeedForViewer($full, $userIdParam, $limit, $beforeId);
if ($rows === null) {
    apiError('auth.forbidden', 'You cannot view this activity feed', 403);
}
$rows = array_map('activityFeedStripForApi', $rows);
apiSuccess(['events' => $rows, 'count' => count($rows)]);
