<?php
/**
 * Admin comment composer endpoint.
 * Posts a comment on the given task using the current session user, then
 * redirects back to the task view (anchoring the discussion thread).
 *
 * Lives next to the API endpoint /api/create-comment.php — same DB call
 * (addTaskComment) but session-authenticated for the admin UI.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit();
}

requireCsrfToken();

$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$comment = trim((string)($_POST['comment'] ?? ''));
if ($taskId <= 0) {
    header('Location: /admin/');
    exit();
}

$task = getTaskById($taskId, false);
$currentUser = getCurrentUser();
if (!$task || !$currentUser || !userCanAccessTaskForViewer($currentUser, $task)) {
    header('Location: /admin/');
    exit();
}

$result = ['success' => false, 'error' => 'Empty comment'];
if ($comment !== '') {
    $result = addTaskComment($taskId, (int)$currentUser['id'], $comment);
}

if (!empty($result['success'])) {
    $_SESSION['admin_flash_success'] = 'Comment posted.';
} else {
    $_SESSION['admin_flash_error'] = $result['error'] ?? 'Failed to post comment.';
}

header('Location: /admin/view.php?id=' . $taskId . '#discussion-end');
exit();
