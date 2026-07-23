<?php
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/auth.php';

initializeDatabase();

$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($jobId <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid export id', 400);
}

$job = getBoardExportJobById($jobId);
if (!$job) {
    apiError('not_found', 'Export not found', 404);
}

$requestUser = null;
$apiKey = getApiKeyFromRequest();
if ($apiKey !== null && $apiKey !== '') {
    $requestUser = validateApiKeyAndGetUser($apiKey);
    if (!$requestUser) {
        apiError('auth.invalid_api_key', 'Invalid or missing API key', 401);
    }
} elseif (isLoggedIn()) {
    $requestUser = getCurrentUser();
}
if (!$requestUser) {
    apiError('auth.required', 'Authentication required', 401);
}

$full = getUserById((int)$requestUser['id'], false) ?: $requestUser;
$project = getDirectoryProjectById((int)$job['project_id']);
$gate = boardExportAccessGate($full, $project);
if (empty($gate['ok'])) {
    apiError('not_found', 'Export not found', 404);
}

emitBoardExportDownload($job);
