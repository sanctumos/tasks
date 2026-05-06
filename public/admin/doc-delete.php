<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/docs.php');
    exit;
}

requireCsrfToken();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header('Location: /admin/docs.php');
    exit;
}

$doc = getDocumentById($id, false);
$currentUser = getCurrentUser();
if (!$doc || !$currentUser || !userCanAccessDocument($currentUser, $doc)) {
    header('Location: /admin/docs.php');
    exit;
}
if (!userCanManageDocument($currentUser, $doc)) {
    $_SESSION['admin_flash_error'] = 'You do not have permission to delete this document.';
    header('Location: /admin/doc.php?id=' . $id);
    exit;
}

$res = deleteDocument($id);
if (!empty($res['success'])) {
    $_SESSION['admin_flash_success'] = 'Document deleted.';
} else {
    $_SESSION['admin_flash_error'] = $res['error'] ?? 'Delete failed.';
}

$projectId = (int)($doc['project_id'] ?? 0);
$redirect = '/admin/docs.php' . ($projectId ? '?project_id=' . $projectId : '');
header('Location: ' . $redirect);
exit;
