<?php
/**
 * Admin comment composer endpoint for documents. Mirrors
 * /admin/comment.php for tasks: CSRF + session auth, calls
 * addDocumentComment, redirects back to the doc.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/docs.php');
    exit;
}

requireCsrfToken();

$documentId = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
$comment = trim((string)($_POST['comment'] ?? ''));
if ($documentId <= 0) {
    header('Location: /admin/docs.php');
    exit;
}

$doc = getDocumentById($documentId, false);
$currentUser = getCurrentUser();
if (!$doc || !$currentUser || !userCanAccessDocument($currentUser, $doc)) {
    header('Location: /admin/docs.php');
    exit;
}

$result = ['success' => false, 'error' => 'Empty comment'];
if ($comment !== '') {
    $result = addDocumentComment($documentId, (int)$currentUser['id'], $comment);
}

if (!empty($result['success'])) {
    $_SESSION['admin_flash_success'] = 'Comment posted.';
} else {
    $_SESSION['admin_flash_error'] = $result['error'] ?? 'Failed to post comment.';
}

header('Location: /admin/doc.php?id=' . $documentId . '#discussion-end');
exit;
