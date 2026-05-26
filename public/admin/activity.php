<?php
/**
 * Global activity timeline (Basecamp-style): every actor, every event in
 * directory projects the viewer can access — newest first.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser) {
    auth_redirect_to_login();
    exit;
}

// Legacy per-user URLs (?user_id=) — this page is global-only now.
if (array_key_exists('user_id', $_GET)) {
    $clean = [];
    if (isset($_GET['before_id']) && (int)$_GET['before_id'] > 0) {
        $clean['before_id'] = (int)$_GET['before_id'];
    }
    $target = '/admin/activity.php';
    if ($clean !== []) {
        $target .= '?' . http_build_query($clean);
    }
    header('Location: ' . $target, true, 302);
    exit;
}

$beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;
$beforeId = $beforeId > 0 ? $beforeId : null;

$feed = listAccessibleProjectsActivityForViewer($currentUser, 100, $beforeId);

$pageTitle = 'Activity';
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
    ['label' => 'Activity'],
];
require __DIR__ . '/_layout_top.php';
?>

<div class="page-header">
    <div class="page-header__title">
        <h1><i class="bi bi-activity me-2"></i>Activity</h1>
        <div class="subtitle">Everything that happened in projects you can access — newest first.</div>
    </div>
</div>

<?php if (empty($feed)): ?>
    <div class="surface surface-pad text-center text-muted">
        <p class="mb-0">No activity in your visible projects yet.</p>
    </div>
<?php else: ?>
    <ul class="activity-feed list-unstyled mb-0">
        <?php foreach ($feed as $ev): ?>
            <li class="activity-feed__item surface surface-pad mb-2">
                <div class="activity-feed__icon"><i class="bi <?= htmlspecialchars((string)($ev['icon'] ?? 'bi-activity')) ?>"></i></div>
                <div class="activity-feed__body">
                    <a class="activity-feed__summary text-decoration-none" href="<?= htmlspecialchars((string)($ev['href'] ?? '/admin/')) ?>"><?= htmlspecialchars((string)($ev['summary'] ?? '')) ?></a>
                    <div class="activity-feed__meta text-muted small">
                        <span title="<?= htmlspecialchars(st_absolute_time_attr($ev['created_at'] ?? null)) ?>"><?= htmlspecialchars(st_absolute_time($ev['created_at'] ?? null)) ?></span>
                        <span class="ms-1">· <?= htmlspecialchars(st_relative_time($ev['created_at'] ?? null)) ?></span>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    $oldestId = (int)($feed[count($feed) - 1]['id'] ?? 0);
    if ($oldestId > 0 && count($feed) >= 100):
        $moreQ = http_build_query(['before_id' => $oldestId]);
        ?>
        <div class="text-center mt-3">
            <a class="btn btn-outline-secondary btn-sm" href="/admin/activity.php?<?= htmlspecialchars($moreQ) ?>">Load older</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
