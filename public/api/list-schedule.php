<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();
$full = getUserById((int)$user['id'], false);
if (!$full) {
    apiError('auth.invalid_user', 'User not found', 401);
}

$scope = isset($_GET['scope']) ? (string)$_GET['scope'] : 'mine';
if (normalizeScheduleScope($scope) === null) {
    apiError('validation.invalid_scope', 'scope must be mine, project, or all', 400);
}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$includeDone = isset($_GET['include_done']) && filter_var($_GET['include_done'], FILTER_VALIDATE_BOOLEAN);
$includeOverdue = !isset($_GET['include_overdue']) || filter_var($_GET['include_overdue'], FILTER_VALIDATE_BOOLEAN);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;

$options = [
    'scope' => $scope,
    'due_after' => $_GET['due_after'] ?? null,
    'due_before' => $_GET['due_before'] ?? null,
    'include_done' => $includeDone,
    'include_overdue' => $includeOverdue,
    'limit' => $limit,
];
if ($projectId > 0) {
    $options['project_id'] = $projectId;
}

$result = listScheduleForViewer($full, $options);
if (!empty($result['error'])) {
    apiError('schedule.invalid_request', (string)$result['error'], 400);
}

apiSuccess([
    'schedule' => [
        'scope' => $result['scope'],
        'due_after' => $result['due_after'],
        'due_before' => $result['due_before'],
        'entries' => $result['entries'],
        'grouped_by_date' => $result['grouped_by_date'],
        'count' => $result['count'],
    ],
]);
