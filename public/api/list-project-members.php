<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) {
    apiError('validation.invalid_project_id', 'Missing or invalid project_id', 400);
}

$proj = getDirectoryProjectById($projectId);
if (!$proj || !userCanAccessDirectoryProject($user, $proj)) {
    apiError('project.not_found', 'Project not found', 404);
}

$members = listProjectMembers($projectId);
apiSuccess(['members' => $members]);
