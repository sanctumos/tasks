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

$existing = getDocumentById($id, false);
if (!$existing || !userCanAccessDocument($user, $existing)) {
    apiError('document.not_found', 'Document not found', 404);
}
if (!userCanManageDocument($user, $existing)) {
    apiError('auth.forbidden', 'You do not have permission to update this document', 403);
}

$rotateRequested = filter_var($body['rotate_public_link'] ?? false, FILTER_VALIDATE_BOOLEAN);
$hasShareMutation = array_key_exists('public_link_enabled', $body) || ($rotateRequested === true);

$fields = [];
foreach (['title', 'body', 'status', 'project_id', 'directory_path'] as $k) {
    if (array_key_exists($k, $body)) {
        $fields[$k] = $body[$k];
    }
}
if (array_key_exists('expected_updated_at', $body) && $body['expected_updated_at'] !== null && $body['expected_updated_at'] !== '') {
    $fields['expected_updated_at'] = (string)$body['expected_updated_at'];
}

$res = ['success' => true];
if ($fields !== []) {
    try {
        $res = updateDocument($id, $fields, (int)$user['id']);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $busy = (stripos($msg, 'database is locked') !== false)
            || (stripos($msg, 'SQLITE_BUSY') !== false)
            || (stripos($msg, 'locked') !== false);
        if ($busy) {
            apiError(
                'db.busy',
                'Database busy while updating document; retry shortly',
                503,
                ['document_id' => $id, 'retryable' => true]
            );
        }
        error_log('update-document.php document_id=' . $id . ' exception=' . get_class($e) . ' message=' . $msg);
        apiError(
            'document.update_failed',
            APP_DEBUG ? ('Update failed: ' . $msg) : 'Update failed',
            500,
            ['document_id' => $id]
        );
    }
    if (!$res['success']) {
        $code = ($res['error_code'] ?? '') === 'document.conflict'
            ? 'document.conflict'
            : 'document.update_failed';
        $status = ($code === 'document.conflict') ? 409 : 400;
        apiError($code, $res['error'] ?? 'Update failed', $status, $res['details'] ?? []);
    }
} elseif (!$hasShareMutation) {
    apiError('validation.no_fields', 'No fields to update', 400);
}

if ($hasShareMutation) {
    $fresh = getDocumentById($id, false);
    if (!$fresh) {
        apiError('document.not_found', 'Document not found', 404);
    }
    $rotate = filter_var($body['rotate_public_link'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (array_key_exists('public_link_enabled', $body)) {
        $wantEnabled = filter_var($body['public_link_enabled'], FILTER_VALIDATE_BOOLEAN);
    } else {
        $wantEnabled = !empty($fresh['public_link_enabled']);
        if (!$wantEnabled && $rotate) {
            apiError('document.public_rotate_requires_enabled', 'Cannot rotate token while public sharing is off', 400);
        }
    }
    $shareRes = documentSetPublicSharing($id, (int)$user['id'], $wantEnabled, ($rotate === true && $wantEnabled === true));
    if (!$shareRes['success']) {
        apiError('document.public_link_failed', $shareRes['error'] ?? 'Public link update failed', 400);
    }
}

$doc = getDocumentById($id);
$doc = $doc ? sanitizeDocumentForApiPayload($doc) : null;
apiSuccess(['document' => $doc]);
