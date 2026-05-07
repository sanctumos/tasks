<?php
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError('method.not_allowed', 'Use GET for this endpoint', 405);
}

$attachmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attachmentId <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid attachment id', 400);
}

$requestUser = null;
$apiKey = getApiKeyFromRequest();
if ($apiKey !== null && $apiKey !== '') {
    $requestUser = validateApiKeyAndGetUser($apiKey);
    if (!$requestUser) {
        apiError('auth.invalid_api_key', 'Invalid or missing API key', 401);
    }
    $rateState = checkApiRateLimit($apiKey);
    setRateLimitHeaders($rateState);
    if (empty($rateState['allowed'])) {
        header('Retry-After: ' . (int)($rateState['retry_after'] ?? 1));
        apiError(
            'rate_limited',
            'Rate limit exceeded. Slow down and retry later.',
            429,
            ['retry_after' => (int)($rateState['retry_after'] ?? 1)],
            ['rate_limit' => $rateState]
        );
    }
} elseif (isLoggedIn()) {
    $requestUser = getCurrentUser();
}

if (!$requestUser) {
    apiError('auth.required', 'Authentication required', 401);
}

$db = getDbConnection();
$stmt = $db->prepare("
    SELECT id, task_id, file_url, mime_type, storage_kind, storage_rel_path
    FROM task_attachments
    WHERE id = :id
    LIMIT 1
");
$stmt->bindValue(':id', $attachmentId, SQLITE3_INTEGER);
$res = $stmt->execute();
$attachment = $res->fetchArray(SQLITE3_ASSOC) ?: null;
if (!$attachment) {
    apiError('asset.not_found', 'Asset not found', 404);
}

$task = getTaskById((int)$attachment['task_id'], false);
if (!$task) {
    apiError('asset.not_found', 'Asset not found', 404);
}
if (!userCanAccessTask((int)$requestUser['id'], $task, (string)$requestUser['role'])) {
    apiError('asset.not_found', 'Asset not found', 404);
}

$kind = normalizeTaskAttachmentStorageKind((string)($attachment['storage_kind'] ?? 'remote'));
if ($kind === 'local') {
    $rel = trim((string)($attachment['storage_rel_path'] ?? ''));
    $abs = taskAttachmentAbsolutePath($rel);
    if ($abs === null || !is_file($abs)) {
        apiError('asset.not_found', 'Asset file is missing', 404);
    }
    $mimeType = trim((string)($attachment['mime_type'] ?? ''));
    if ($mimeType === '') {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string)$finfo->file($abs);
    }
    if ($mimeType === '') {
        $mimeType = 'application/octet-stream';
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string)filesize($abs));
    header('Cache-Control: private, max-age=300');
    readfile($abs);
    exit();
}

$remote = trim((string)($attachment['file_url'] ?? ''));
if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_URL)) {
    apiError('asset.not_found', 'Asset URL is unavailable', 404);
}
header('Cache-Control: private, max-age=60');
header('Location: ' . $remote, true, 302);
exit();
