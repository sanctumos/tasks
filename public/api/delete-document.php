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
    apiError('auth.forbidden', 'You do not have permission to delete this document', 403);
}

$res = deleteDocument($id);
if (!$res['success']) {
    apiError('document.delete_failed', $res['error'] ?? 'Delete failed', 400);
}

apiSuccess(['deleted' => true, 'id' => $id]);
