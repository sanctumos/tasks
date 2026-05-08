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

$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
$title = (string)($body['title'] ?? '');
$content = $body['body'] ?? null;
$directoryPath = isset($body['directory_path']) ? (string)$body['directory_path'] : '';

$wantPublicSharing = isset($body['public_link_enabled'])
    ? filter_var($body['public_link_enabled'], FILTER_VALIDATE_BOOLEAN)
    : false;

if ($projectId <= 0) {
    apiError('validation.invalid_project_id', 'project_id is required', 400);
}

$result = createDocument((int)$user['id'], $projectId, $title, $content, $directoryPath, $wantPublicSharing);
if (!$result['success']) {
    apiError('document.create_failed', $result['error'] ?? 'Create failed', 400);
}

$doc = getDocumentById((int)$result['id']);
$doc = $doc ? sanitizeDocumentForApiPayload($doc) : null;
apiSuccess(['document' => $doc], [], 201);
