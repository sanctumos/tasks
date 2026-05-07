<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
if ($taskId <= 0) {
    apiError('validation.invalid_task_id', 'Missing or invalid task_id', 400);
}
$task = getTaskById($taskId, false);
if (!$task) {
    apiError('task.not_found', 'Task not found', 404);
}
if (!userCanAccessTask((int)$apiUser['id'], $task, (string)$apiUser['role'])) {
    apiError('task.not_found', 'Task not found', 404);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    apiError('validation.file_required', 'Missing uploaded file field "file"', 400);
}
$upload = $_FILES['file'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    apiError('validation.upload_failed', 'Upload failed', 400, ['upload_error' => (int)($upload['error'] ?? -1)]);
}

$tmpName = (string)($upload['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    apiError('validation.upload_failed', 'Upload temp file missing', 400);
}

$sizeBytes = isset($upload['size']) ? (int)$upload['size'] : (int)@filesize($tmpName);
if ($sizeBytes <= 0) {
    apiError('validation.empty_file', 'Uploaded file is empty', 400);
}
if ($sizeBytes > TASKS_ASSET_MAX_BYTES) {
    apiError(
        'validation.file_too_large',
        'Uploaded file exceeds max size',
        400,
        ['max_bytes' => TASKS_ASSET_MAX_BYTES]
    );
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = strtolower((string)$finfo->file($tmpName));
if (!isAllowedTaskAssetMimeType($mimeType)) {
    apiError('validation.unsupported_mime', 'Unsupported MIME type for task image upload', 400, [
        'mime_type' => $mimeType,
        'allowed' => allowedTaskAssetMimeTypes(),
    ]);
}

$persist = persistTaskAssetUpload($taskId, $tmpName, $mimeType);
if (empty($persist['success'])) {
    apiError('task.attachment_upload_failed', (string)($persist['error'] ?? 'Failed to store upload'), 500);
}
$storageRelPath = (string)$persist['storage_rel_path'];

$originalName = trim((string)($upload['name'] ?? ''));
if ($originalName === '') {
    $originalName = basename($storageRelPath);
}

$attachmentResult = addTaskAttachment(
    $taskId,
    (int)$apiUser['id'],
    $originalName,
    'about:blank',
    $mimeType,
    $sizeBytes,
    [
        'storage_kind' => 'local',
        'storage_rel_path' => $storageRelPath,
    ]
);
if (empty($attachmentResult['success'])) {
    $abs = taskAttachmentAbsolutePath($storageRelPath);
    if ($abs !== null && is_file($abs)) {
        @unlink($abs);
    }
    apiError('task.attachment_add_failed', (string)($attachmentResult['error'] ?? 'Failed to add attachment'), 400);
}

$attachmentId = (int)$attachmentResult['id'];
$canonicalUrl = buildAbsoluteUrl('/api/get-asset.php', ['id' => $attachmentId]);

$db = getDbConnection();
$upd = $db->prepare("UPDATE task_attachments SET file_url = :file_url WHERE id = :id");
$upd->bindValue(':file_url', $canonicalUrl, SQLITE3_TEXT);
$upd->bindValue(':id', $attachmentId, SQLITE3_INTEGER);
$upd->execute();

$markdown = '![' . str_replace([']', '['], '', $originalName) . '](' . $canonicalUrl . ')';

apiSuccess([
    'task_id' => $taskId,
    'attachment_id' => $attachmentId,
    'file_url' => $canonicalUrl,
    'markdown' => $markdown,
], [], 201);
