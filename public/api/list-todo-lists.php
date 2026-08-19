<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) {
    apiError('validation.invalid_project_id', 'Missing or invalid project_id', 400);
}

$includeArchived = isset($_GET['include_archived']) && in_array(strtolower((string)$_GET['include_archived']), ['1', 'true', 'yes'], true);
$lists = listTodoListsForProject($user, $projectId, $includeArchived);
apiSuccess(['todo_lists' => $lists]);
