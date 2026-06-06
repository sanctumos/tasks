<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit();
}

requireCsrfToken();
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id > 0) {
    $currentUser = getCurrentUser();
    $task = getTaskById($id, false);
    if (!$currentUser || !$task || !userCanManageTaskForViewer($currentUser, $task)) {
        $_SESSION['admin_flash_error'] = 'You do not have permission to delete this task.';
    } else {
        $res = deleteTask($id);
        if (!empty($res['success'])) {
            $_SESSION['admin_flash_success'] = 'Task deleted.';
        } else {
            $_SESSION['admin_flash_error'] = $res['error'] ?? 'Delete failed.';
        }
    }
}

header('Location: /admin/');
exit();

