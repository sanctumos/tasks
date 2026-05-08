<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$doc = getDocumentById($id);
if (!$doc || !userCanAccessDocument($user, $doc)) {
    apiError('document.not_found', 'Document not found', 404);
}

$doc = sanitizeDocumentForApiPayload($doc);

apiSuccess(['document' => $doc]);
