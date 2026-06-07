<?php
/**
 * Schedule view — due dates aggregated per viewer scope (Phase 4.1).
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

$scope = normalizeScheduleScope($_GET['scope'] ?? 'mine') ?? 'mine';
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$includeDone = isset($_GET['include_done']) && (string)$_GET['include_done'] === '1';

$startRaw = trim((string)($_GET['start'] ?? ''));
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
if ($days < 7) {
    $days = 7;
}
if ($days > 90) {
    $days = 90;
}

$utc = new DateTimeZone('UTC');
if ($startRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw)) {
    $windowStart = DateTime::createFromFormat('Y-m-d', $startRaw, $utc);
} else {
    $windowStart = new DateTime('today', $utc);
}
if (!$windowStart) {
    $windowStart = new DateTime('today', $utc);
}
$dueAfter = $windowStart->format('Y-m-d 00:00:00');
$dueBefore = (clone $windowStart)->modify('+' . $days . ' days')->setTime(23, 59, 59)->format('Y-m-d H:i:s');

$directoryProjects = listDirectoryProjectsForUser($currentUser, 300);

$options = [
    'scope' => $scope,
    'due_after' => $dueAfter,
    'due_before' => $dueBefore,
    'include_done' => $includeDone,
    'include_overdue' => true,
    'limit' => 300,
];
if ($scope === 'project' && $projectId > 0) {
    $options['project_id'] = $projectId;
} elseif ($scope === 'project' && $projectId <= 0 && $directoryProjects !== []) {
    $options['project_id'] = (int)$directoryProjects[0]['id'];
    $projectId = (int)$directoryProjects[0]['id'];
}

$schedule = listScheduleForViewer($currentUser, $options);
$groups = $schedule['grouped_by_date'] ?? [];
$entryCount = (int)($schedule['count'] ?? 0);

$prevStart = (clone $windowStart)->modify('-' . $days . ' days')->format('Y-m-d');
$nextStart = (clone $windowStart)->modify('+' . $days . ' days')->format('Y-m-d');

function st_schedule_query(array $overrides = []): string
{
    $base = [
        'scope' => $_GET['scope'] ?? 'mine',
        'start' => $_GET['start'] ?? (new DateTime('today', new DateTimeZone('UTC')))->format('Y-m-d'),
        'days' => $_GET['days'] ?? 30,
    ];
    if (!empty($_GET['project_id'])) {
        $base['project_id'] = (int)$_GET['project_id'];
    }
    if (isset($_GET['include_done']) && (string)$_GET['include_done'] === '1') {
        $base['include_done'] = '1';
    }
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($base[$k]);
        } else {
            $base[$k] = $v;
        }
    }
    return http_build_query($base);
}

$pageTitle = 'Schedule';
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
    ['label' => 'Schedule'],
];
require __DIR__ . '/_layout_top.php';
?>

<div class="page-header">
    <div class="page-header__title">
        <h1><i class="bi bi-calendar3 me-2"></i>Schedule</h1>
        <div class="subtitle">Due dates from tasks you can see — grouped by day.</div>
    </div>
    <div class="page-header__actions d-flex flex-wrap gap-2 align-items-center">
        <?= st_doc_help('schedule', 'Due-date aggregation from task due_at fields') ?>
        <a class="btn btn-outline-secondary btn-sm" href="/admin/schedule.php?<?= htmlspecialchars(st_schedule_query(['start' => $prevStart])) ?>"><i class="bi bi-chevron-left"></i> Earlier</a>
        <a class="btn btn-outline-secondary btn-sm" href="/admin/schedule.php?<?= htmlspecialchars(st_schedule_query(['start' => $nextStart])) ?>">Later <i class="bi bi-chevron-right"></i></a>
    </div>
</div>

<form method="get" action="/admin/schedule.php" class="surface surface-pad mb-3 schedule-filters">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1" for="scope">Show</label>
            <select class="form-select form-select-sm" name="scope" id="scope">
                <option value="mine" <?= $scope === 'mine' ? 'selected' : '' ?>>Assigned to me</option>
                <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All visible projects</option>
                <option value="project" <?= $scope === 'project' ? 'selected' : '' ?>>One project</option>
            </select>
        </div>
        <div class="col-md-4<?= $scope !== 'project' ? ' d-none' : '' ?>" id="schedule-project-wrap">
            <label class="form-label small text-muted mb-1" for="project_id">Project</label>
            <select class="form-select form-select-sm" name="project_id" id="project_id">
                <?php foreach ($directoryProjects as $dp): ?>
                    <option value="<?= (int)$dp['id'] ?>" <?= $projectId === (int)$dp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1" for="start">From</label>
            <input class="form-control form-control-sm" type="date" name="start" id="start" value="<?= htmlspecialchars($windowStart->format('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1" for="days">Days</label>
            <select class="form-select form-select-sm" name="days" id="days">
                <?php foreach ([14, 30, 60, 90] as $d): ?>
                    <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $d ?> days</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-auto">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="include_done" id="include_done" value="1" <?= $includeDone ? 'checked' : '' ?>>
                <label class="form-check-label small" for="include_done">Include done</label>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </div>
    </div>
</form>

<p class="text-muted small mb-3">
    <?= (int)$entryCount ?> task<?= $entryCount === 1 ? '' : 's' ?> with due dates
    · <?= htmlspecialchars(substr($dueAfter, 0, 10)) ?> → <?= htmlspecialchars(substr($dueBefore, 0, 10)) ?>
</p>

<?php if ($groups === []): ?>
    <div class="surface surface-pad text-center text-muted">
        <p class="mb-0">Nothing due in this window.</p>
    </div>
<?php else: ?>
    <div class="schedule-by-day">
        <?php foreach ($groups as $group): ?>
            <?php
            $gDate = (string)($group['date'] ?? '');
            $gItems = $group['entries'] ?? [];
            $isToday = $gDate === (new DateTime('today', $utc))->format('Y-m-d');
            ?>
            <section class="schedule-day surface mb-3">
                <header class="schedule-day__head px-3 py-2 border-bottom">
                    <strong><?= htmlspecialchars($gDate) ?></strong>
                    <?php if ($isToday): ?><span class="badge text-bg-primary ms-2">Today</span><?php endif; ?>
                    <span class="text-muted small ms-2"><?= count($gItems) ?> item<?= count($gItems) === 1 ? '' : 's' ?></span>
                </header>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($gItems as $item): ?>
                        <li class="schedule-day__item px-3 py-2 border-bottom border-light-subtle d-flex flex-wrap gap-2 align-items-center justify-content-between">
                            <div class="schedule-day__title">
                                <a href="/admin/view.php?id=<?= (int)($item['task_id'] ?? 0) ?>" class="text-decoration-none fw-medium"><?= htmlspecialchars((string)($item['title'] ?? '')) ?></a>
                                <?php if (!empty($item['is_overdue'])): ?>
                                    <span class="badge text-bg-danger ms-1">Overdue</span>
                                <?php endif; ?>
                            </div>
                            <div class="schedule-day__meta text-muted small d-flex flex-wrap gap-2">
                                <?php if (!empty($item['project_name']) && $scope !== 'project'): ?>
                                    <span><i class="bi bi-folder2"></i> <?= htmlspecialchars((string)$item['project_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($scope !== 'mine' && !empty($item['assigned_to_username'])): ?>
                                    <span><i class="bi bi-person"></i> <?= htmlspecialchars((string)$item['assigned_to_username']) ?></span>
                                <?php endif; ?>
                                <span class="status-pill status-pill--<?= htmlspecialchars((string)($item['status'] ?? 'todo')) ?>"><?= htmlspecialchars((string)($item['status_label'] ?? $item['status'] ?? '')) ?></span>
                                <span title="<?= htmlspecialchars((string)($item['due_at'] ?? '')) ?>"><i class="bi bi-clock"></i> <?= htmlspecialchars(substr((string)($item['due_at'] ?? ''), 11, 5)) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.getElementById('scope')?.addEventListener('change', function () {
    var wrap = document.getElementById('schedule-project-wrap');
    if (wrap) wrap.classList.toggle('d-none', this.value !== 'project');
});
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
