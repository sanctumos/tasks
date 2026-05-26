<?php
/**
 * Cross-project activity timeline (per user), Basecamp-style.
 * Default: current user. Staff with directory-wide access may pass ?user_id= for another user.
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

$viewerId = (int)$currentUser['id'];
$unrestricted = userHasUnrestrictedOrgDirectoryAccess($currentUser);
$pk = normalizePersonKind($currentUser['person_kind'] ?? 'team_member');
$canPickOther = $unrestricted && $pk !== 'client';

// Scope sentinel: user_id=0 (or unset, for staff) → all actors across accessible
// projects. user_id=<viewerId> → just the viewer. user_id=<n> → that actor (staff
// only; restricted users get coerced to themselves).
$hasUserParam = array_key_exists('user_id', $_GET);
if ($hasUserParam) {
    $requested = (int)$_GET['user_id'];
    if ($requested < 0) {
        $requested = 0;
    }
} else {
    $requested = $canPickOther ? 0 : $viewerId;
}
if (!$canPickOther && $requested !== $viewerId) {
    $requested = $viewerId;
}

$beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;
$beforeId = $beforeId > 0 ? $beforeId : null;

if ($requested === 0) {
    $feed = listAccessibleProjectsActivityForViewer($currentUser, 100, $beforeId);
} else {
    $feed = listUserActivityFeedForViewer($currentUser, $requested, 100, $beforeId);
    if ($feed === null) {
        $feed = [];
    }
}

if ($requested === 0) {
    $targetLabel = 'All users';
} elseif ($requested === $viewerId) {
    $targetLabel = 'You';
} else {
    $tu = getUserById($requested, false);
    $targetLabel = $tu ? (string)$tu['username'] : ('User #' . $requested);
}

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
        <div class="subtitle">What happened across projects you can access — newest first.</div>
    </div>
</div>

<?php if ($canPickOther): ?>
    <form class="filter-bar surface surface-pad mb-3" method="get" action="/admin/activity.php">
        <div class="filter-bar__field flex-grow-1" style="max-width: 22rem;">
            <label class="form-label small text-muted mb-1">User</label>
            <select class="form-select" name="user_id" onchange="this.form.submit()">
                <option value="0" <?= $requested === 0 ? 'selected' : '' ?>>All users</option>
                <?php foreach (listUsers(false) as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $requested === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
<?php endif; ?>

<div class="surface surface-pad mb-3">
    <div class="text-muted small">Showing activity for <strong><?= htmlspecialchars($targetLabel) ?></strong><?= ($requested !== 0 && $requested !== $viewerId) ? ' <span class="text-muted">(as seen through your access)</span>' : '' ?>.</div>
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
        $moreQ = ['before_id' => $oldestId];
        // Carry the active scope across pages: 0 = all users, viewerId = self
        // (omit to keep URLs clean), specific actor = pass id.
        if ($requested === 0) {
            $moreQ['user_id'] = 0;
        } elseif ($requested !== $viewerId) {
            $moreQ['user_id'] = $requested;
        }
        ?>
        <div class="text-center mt-3">
            <a class="btn btn-outline-secondary btn-sm" href="/admin/activity.php?<?= htmlspecialchars(http_build_query($moreQ)) ?>">Load older</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
