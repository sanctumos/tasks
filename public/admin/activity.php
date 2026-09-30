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
    <div class="st-feedfilter mb-3" id="st-activity-filter">
        <div class="input-group input-group-sm" style="max-width:280px">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="search" id="st-activity-filter-q" class="form-control border-start-0" placeholder="Filter this feed…" autocomplete="off" aria-label="Filter activity feed">
        </div>
        <button type="button" class="st-chip-toggle" data-st-chip="comment" aria-pressed="false">Comments</button>
        <button type="button" class="st-chip-toggle" data-st-chip="status" aria-pressed="false">Status changes</button>
        <button type="button" class="st-chip-toggle" data-st-chip="create" aria-pressed="false">Creates</button>
        <span class="st-feedfilter__count text-muted small" id="st-activity-filter-count" aria-live="polite"></span>
    </div>
    <ul class="activity-feed list-unstyled mb-0" id="st-activity-feed">
        <?php foreach ($feed as $ev):
            $kind = activityFeedFilterKind((string)($ev['action'] ?? ''));
            $filterText = activityFeedFilterText($ev);
            ?>
            <li class="activity-feed__item surface surface-pad"
                data-st-filter-item
                data-st-filter-text="<?= htmlspecialchars($filterText, ENT_QUOTES, 'UTF-8') ?>"
                <?php if ($kind !== ''): ?>data-st-chip-<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?>="1"<?php endif; ?>>
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
<?php if (!empty($feed)): ?>
<script>
(function () {
    if (!window.stFilter) return;
    var items = document.querySelectorAll('#st-activity-feed [data-st-filter-item]');
    if (!items.length) return;
    stFilter.attach({
        input: document.getElementById('st-activity-filter-q'),
        items: items,
        chips: document.querySelectorAll('#st-activity-filter [data-st-chip]'),
        countEl: document.getElementById('st-activity-filter-count'),
        urlParam: '',
        chipMode: 'any'
    });
})();
</script>
<?php endif; ?>
