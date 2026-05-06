<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
if ($projectId !== null && $projectId <= 0) {
    apiError('validation.invalid_project_id', 'Invalid project_id', 400);
}

$documents = listDocumentsForUser($user, $limit, $projectId);

apiSuccess([
    'documents' => $documents,
    'count' => count($documents),
]);
