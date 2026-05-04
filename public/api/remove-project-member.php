<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
$targetUserId = isset($body['user_id']) ? (int)$body['user_id'] : 0;
if ($projectId <= 0 || $targetUserId <= 0) {
    apiError('validation.invalid_payload', 'project_id and user_id required', 400);
}

$result = removeProjectMember((int)$user['id'], $projectId, $targetUserId);
if (!$result['success']) {
    apiError('project.member_failed', $result['error'] ?? 'Could not remove member', 400);
}

$members = listProjectMembers($projectId);
apiSuccess(['members' => $members]);
