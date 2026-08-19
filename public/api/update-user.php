<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireAdminApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$userId = isset($body['user_id']) ? (int)$body['user_id'] : (isset($body['id']) ? (int)$body['id'] : 0);
if ($userId <= 0) {
    apiError('validation.invalid_user_id', 'Missing or invalid user_id', 400);
}

$target = getUserById($userId, false);
if (!$target) {
    apiError('user.not_found', 'User not found', 404);
}

$updated = [];
$errors = [];

if (array_key_exists('person_kind', $body)) {
    $res = setUserPersonKind((int)$apiUser['id'], $userId, (string)$body['person_kind']);
    if (empty($res['success'])) {
        $errors[] = $res['error'] ?? 'Failed to set person_kind';
    } else {
        $updated['person_kind'] = $res['person_kind'] ?? normalizePersonKind((string)$body['person_kind']);
    }
}

if (array_key_exists('limited_project_access', $body)) {
    $limited = (bool)$body['limited_project_access'];
    $res = setUserLimitedProjectAccess((int)$apiUser['id'], $userId, $limited);
    if (empty($res['success'])) {
        $errors[] = $res['error'] ?? 'Failed to set limited_project_access';
    } else {
        $updated['limited_project_access'] = $limited ? 1 : 0;
    }
}

if (array_key_exists('org_id', $body)) {
    $orgId = (int)$body['org_id'];
    $res = setUserOrganization((int)$apiUser['id'], $userId, $orgId);
    if (empty($res['success'])) {
        $errors[] = $res['error'] ?? 'Failed to set org_id';
    } else {
        $updated['org_id'] = $orgId;
    }
}

if ($updated === [] && $errors === []) {
    apiError('validation.no_fields', 'Provide at least one of: person_kind, limited_project_access, org_id', 400);
}

if ($errors !== [] && $updated === []) {
    apiError('user.update_failed', implode('; ', $errors), 400);
}

$fresh = getUserById($userId, false);
apiSuccess([
    'user' => $fresh,
    'updated' => $updated,
    'warnings' => $errors,
], $errors === [] ? 'User updated' : 'User partially updated');
