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

$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$fields = [];
foreach (['name', 'description', 'status', 'client_visible', 'all_access'] as $k) {
    if (array_key_exists($k, $body)) {
        $fields[$k] = $body[$k];
    }
}

if (!$fields) {
    apiError('validation.no_fields', 'No updatable fields supplied', 400);
}

$result = updateDirectoryProject((int)$user['id'], $id, $fields);
if (!$result['success']) {
    apiError('project.update_failed', $result['error'] ?? 'Update failed', 400);
}

$proj = getDirectoryProjectById($id);
apiSuccess(['project' => $proj]);
