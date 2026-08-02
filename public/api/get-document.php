<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

try {
    $doc = getDocumentById($id);
} catch (Exception $e) {
    $msg = $e->getMessage();
    $busy = (stripos($msg, 'database is locked') !== false)
        || (stripos($msg, 'SQLITE_BUSY') !== false)
        || (stripos($msg, 'locked') !== false);
    if ($busy) {
        apiError(
            'db.busy',
            'Database busy while reading document; retry the GET shortly',
            503,
            ['document_id' => $id, 'retryable' => true]
        );
    }
    error_log('get-document.php document_id=' . $id . ' exception=' . get_class($e) . ' message=' . $msg);
    apiError(
        'document.read_failed',
        APP_DEBUG ? ('Failed to read document: ' . $msg) : 'Failed to read document',
        500,
        ['document_id' => $id]
    );
}

if (!$doc || !userCanAccessDocument($user, $doc)) {
    apiError('document.not_found', 'Document not found', 404);
}

$doc = sanitizeDocumentForApiPayload($doc);

apiSuccess(['document' => $doc]);
