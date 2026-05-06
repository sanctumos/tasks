<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$documentId = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
if ($documentId <= 0) {
    apiError('validation.invalid_document_id', 'Missing or invalid document_id', 400);
}

$doc = getDocumentById($documentId, false);
if (!$doc || !userCanAccessDocument($user, $doc)) {
    apiError('document.not_found', 'Document not found', 404);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$comments = listDocumentComments($documentId, $limit, $offset);

apiSuccess([
    'document_id' => $documentId,
    'comments' => $comments,
    'count' => count($comments),
]);
