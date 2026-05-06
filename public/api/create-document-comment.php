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

$documentId = isset($body['document_id']) ? (int)$body['document_id'] : 0;
$comment = trim((string)($body['comment'] ?? ''));
if ($documentId <= 0) {
    apiError('validation.invalid_document_id', 'Missing or invalid document_id', 400);
}
if ($comment === '') {
    apiError('validation.missing_comment', 'comment is required', 400);
}

$doc = getDocumentById($documentId, false);
if (!$doc || !userCanAccessDocument($apiUser, $doc)) {
    apiError('document.not_found', 'Document not found', 404);
}

$result = addDocumentComment($documentId, (int)$apiUser['id'], $comment);
if (!$result['success']) {
    $statusCode = ($result['error'] ?? '') === 'Document not found' ? 404 : 400;
    apiError('document.comment_create_failed', $result['error'] ?? 'Failed to add comment', $statusCode);
}

apiSuccess([
    'document_id' => $documentId,
    'comment_id' => (int)$result['id'],
    'comment' => [
        'id' => (int)$result['id'],
        'document_id' => $documentId,
        'user_id' => (int)$apiUser['id'],
        'username' => $apiUser['username'],
        'comment' => $comment,
        'created_at' => $result['created_at'] ?? nowUtc(),
    ],
], [], 201);
