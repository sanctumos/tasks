<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();
initializeDatabase();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST', 405);
}

$body = readJsonBody();
$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
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

$result = requestBoardExportJob((int)$apiUser['id'], $projectId);
if (empty($result['success'])) {
    apiError('export.failed', (string)($result['error'] ?? 'Could not request export'), 400);
}

apiSuccess([
    'job_id' => (int)$result['id'],
    'reused' => !empty($result['reused']),
    'status' => 'pending',
], [], 201);
