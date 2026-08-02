<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

// Bare JSON arrays are a common agent mistake; the contract is {"updates":[...]}.
$isBareList = $body !== [] && array_keys($body) === range(0, count($body) - 1);
if ($isBareList) {
    apiError(
        'validation.invalid_updates',
        'Body must be an object with an "updates" array (not a bare JSON array). Example: {"updates":[{"id":1,"status":"done"}]}. CLI/MCP --json still accepts a bare array and wraps it.',
        400,
        ['hint' => 'wrapper_required', 'see' => 'docs/api.md']
    );
}

$updates = $body['updates'] ?? null;
if (!is_array($updates)) {
    apiError(
        'validation.invalid_updates',
        'updates must be an array inside {"updates":[...]}. A bare JSON array is rejected.',
        400,
        ['hint' => 'wrapper_required']
    );
}
if (count($updates) > 100) {
    apiError('validation.batch_too_large', 'Maximum 100 updates per request', 400);
}

$result = bulkUpdateTasks($updates);
createAuditLog((int)$apiUser['id'], 'api.task_bulk_update', 'task', null, ['count' => count($updates), 'updated' => (int)$result['updated']]);

apiSuccess($result);
