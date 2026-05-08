<?php
/**
 * User notification inbox (session-authenticated).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit;
}

$uid = (int)$currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $action = (string)$_POST['action'];
    if ($action === 'mark_all_read') {
        markAllNotificationsRead($uid);
        $_SESSION['admin_flash_success'] = 'All notifications marked read.';
        header('Location: /admin/notifications.php');
        exit;
    }
    if ($action === 'mark_read' && isset($_POST['id'])) {
        $nid = (int)$_POST['id'];
        if ($nid > 0) {
            markNotificationsRead($uid, [$nid]);
            $_SESSION['admin_flash_success'] = 'Marked read.';
        }
        header('Location: /admin/notifications.php');
        exit;
    }
}

$pageTitle = 'Notifications';
$bundle = listNotificationsForUser($uid, 80, null, false);
$items = $bundle['notifications'];
$unreadTotal = countUnreadNotifications($uid);

require __DIR__ . '/_layout_top.php';
?>

<div class="page-header">
    <div class="page-header__title">
        <h1><i class="bi bi-bell me-2 text-muted"></i>Notifications</h1>
        <div class="subtitle"><?= $unreadTotal > 0 ? (int)$unreadTotal . ' unread' : 'You are caught up.' ?></div>
    </div>
    <div class="page-header__actions d-flex gap-2 flex-wrap">
        <?php if ($unreadTotal > 0): ?>
            <form method="post" action="/admin/notifications.php" class="m-0">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Mark all read</button>
            </form>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-primary" href="/admin/">Home</a>
    </div>
</div>

<?php if (!empty($_SESSION['admin_flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars((string)$_SESSION['admin_flash_success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['admin_flash_success']); ?>
<?php endif; ?>

<?php if (!$items): ?>
    <div class="surface surface-pad text-center text-muted">No notifications yet. You will see assignments, @mentions, and comments on tasks you follow here.</div>
<?php else: ?>
    <ul class="list-group shadow-sm">
        <?php foreach ($items as $n): ?>
            <?php
            $isUnread = $n['read_at'] === null || $n['read_at'] === '';
            $href = (string)($n['href'] ?? '');
            if ($href === '') {
                $href = '/admin/';
            }
            $actor = (string)($n['actor_username'] ?? '');
            $actorBit = $actor !== '' ? ('@' . $actor . ' · ') : '';
            ?>
            <li class="list-group-item d-flex flex-column flex-md-row align-items-md-start gap-2<?= $isUnread ? ' st-notif-unread' : '' ?>">
                <div class="flex-grow-1">
                    <div class="small text-muted mb-1"><?= htmlspecialchars($actorBit . (string)($n['created_at'] ?? '')) ?></div>
                    <div class="fw-semibold"><?= htmlspecialchars((string)($n['label'] ?? '')) ?></div>
                    <?php if (($n['title'] ?? '') !== ''): ?>
                        <div class="text-body small"><?= htmlspecialchars((string)$n['title']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($n['snippet'])): ?>
                        <div class="text-muted small mt-1"><em><?= htmlspecialchars(truncateString((string)$n['snippet'], 200)) ?></em></div>
                    <?php endif; ?>
                    <div class="mt-2">
                        <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($href) ?>">Open</a>
                        <?php if ($isUnread): ?>
                            <form method="post" action="/admin/notifications.php" class="d-inline ms-1">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
