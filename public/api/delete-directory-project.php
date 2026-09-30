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

$opts = [
    'confirm_name' => (string)($body['confirm_name'] ?? ''),
    'force' => !empty($body['force']),
    'acknowledge_no_export' => !empty($body['acknowledge_no_export']),
];

$result = purgeDirectoryProject((int)$user['id'], $id, $opts);
if (!$result['success']) {
    $code = (string)($result['code'] ?? 'project.purge_failed');
    $status = 400;
    if ($code === 'auth.forbidden') {
        $status = 403;
    } elseif ($code === 'project.not_found') {
        $status = 404;
    }
    apiError($code, $result['error'] ?? 'Permanent delete failed', $status);
}

apiSuccess([
    'deleted' => true,
    'id' => $id,
    'counts' => $result['deleted'] ?? [],
]);
