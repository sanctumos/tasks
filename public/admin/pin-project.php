<?php
/**
 * Pin / unpin a directory project for the logged-in user (board navigation).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit();
}

$user = getCurrentUser();
$projectId = (int)($_POST['project_id'] ?? 0);
$wantPin = ((string)($_POST['pinned'] ?? '1')) === '1';
$return = auth_resolve_intended_url(
    isset($_POST['return']) && is_string($_POST['return']) ? $_POST['return'] : null
);
if ($return === null) {
    $return = '/admin/';
}

if ($projectId <= 0 || $user === null) {
    $_SESSION['admin_flash_error'] = 'Could not update pin.';
    header('Location: ' . $return);
    exit();
}

if ($wantPin) {
    $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
    $result = setUserProjectPin((int)$user['id'], $projectId, $sortOrder);
    if (empty($result['success'])) {
        $_SESSION['admin_flash_error'] = $result['error'] ?? 'Could not pin board.';
    } else {
        $_SESSION['admin_flash_success'] = 'Board pinned — it will show up in your nav and on Home.';
    }
} else {
    removeUserProjectPin((int)$user['id'], $projectId);
    $_SESSION['admin_flash_success'] = 'Board unpinned.';
}

header('Location: ' . $return);
exit();
