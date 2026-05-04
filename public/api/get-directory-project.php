<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$proj = getDirectoryProjectById($id);
if (!$proj || !userCanAccessDirectoryProject($user, $proj)) {
    apiError('project.not_found', 'Project not found', 404);
}

apiSuccess(['project' => $proj]);
