<?php
/**
 * Archive a directory project (soft-hide). Chatter-safe: requires project manage ACL
 * (lead / org admin / unrestricted manager) — not a full update-directory-project surface.
 */
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$id = isset($body['id']) ? (int)$body['id'] : (isset($body['project_id']) ? (int)$body['project_id'] : 0);
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$proj = getDirectoryProjectById($id);
if (!$proj || !userCanAccessDirectoryProject($user, $proj)) {
    apiError('project.not_found', 'Project not found', 404);
}
if (!userCanManageDirectoryProject($user, $proj)) {
    apiError('auth.forbidden', 'You do not have permission to archive this project', 403);
}

if ((string)($proj['status'] ?? '') === 'archived') {
    apiSuccess(['project' => $proj, 'already_archived' => true]);
}

$result = updateDirectoryProject((int)$user['id'], $id, ['status' => 'archived']);
if (!$result['success']) {
    apiError('project.archive_failed', $result['error'] ?? 'Archive failed', 400);
}

$fresh = getDirectoryProjectById($id);
apiSuccess(['project' => $fresh, 'already_archived' => false]);
