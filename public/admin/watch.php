<?php
/**
 * Admin watch / unwatch toggle endpoint.
 * Body: task_id, action=watch|unwatch (defaults to toggle based on current state).
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
$action = strtolower(trim((string)($_POST['action'] ?? '')));
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

$userId = (int)$currentUser['id'];
$alreadyWatching = false;
foreach (($task['watchers'] ?? []) as $w) {
    if ((int)($w['user_id'] ?? 0) === $userId) {
        $alreadyWatching = true;
        break;
    }
}

if ($action === '' || ($action !== 'watch' && $action !== 'unwatch')) {
    $action = $alreadyWatching ? 'unwatch' : 'watch';
}

$result = $action === 'watch'
    ? addTaskWatcher($taskId, $userId)
    : removeTaskWatcher($taskId, $userId);

if (!empty($result['success'])) {
    $_SESSION['admin_flash_success'] = $action === 'watch' ? 'Watching this task.' : 'Stopped watching.';
} else {
    $_SESSION['admin_flash_error'] = $result['error'] ?? ('Failed to ' . $action . '.');
}

header('Location: /admin/view.php?id=' . $taskId);
exit();
