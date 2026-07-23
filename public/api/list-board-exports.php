<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();
initializeDatabase();

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) {
    apiError('validation.invalid_project_id', 'project_id is required', 400);
}

$full = getUserById((int)$apiUser['id'], false);
$project = getDirectoryProjectById($projectId);
$gate = boardExportAccessGate($full ?: $apiUser, $project);
if (empty($gate['ok'])) {
    $http = (int)($gate['http'] ?? 400);
    apiError($http === 404 ? 'not_found' : 'validation.export_not_allowed', (string)$gate['error'], $http);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$jobs = listBoardExportJobsForProject($projectId, $limit);
apiSuccess([
    'project_id' => $projectId,
    'exports' => $jobs,
    'count' => count($jobs),
]);
