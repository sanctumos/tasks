<?php
/**
 * Settings tab: archived boards the viewer can access + ZIP download shortcuts.
 * Admins also get a permanent-delete confirm flow (Tasks #4050 / Doc #1377).
 */

$archivedProjects = listDirectoryProjectsForUser($currentUser, 300, ['include_archived' => true]);
$archivedProjects = array_values(array_filter(
    $archivedProjects,
    static fn(array $p): bool => ($p['status'] ?? '') === 'archived'
));

$archiveRows = [];
foreach ($archivedProjects as $ap) {
    $pid = (int)$ap['id'];
    $jobs = listBoardExportJobsForProject($pid, 5);
    $ready = null;
    foreach ($jobs as $job) {
        if (($job['status'] ?? '') === 'ready') {
            $ready = $job;
            break;
        }
    }
    $archiveRows[] = [
        'project' => $ap,
        'latest_ready' => $ready,
        'pending' => (bool)array_filter($jobs, static fn(array $j): bool => in_array((string)($j['status'] ?? ''), ['pending', 'running'], true)),
        'has_ready_file' => getLatestReadyBoardExportWithFile($pid) !== null,
    ];
}

$flashSuccess = $_SESSION['admin_flash_success'] ?? null;
$flashError = $_SESSION['admin_flash_error'] ?? null;
unset($_SESSION['admin_flash_success'], $_SESSION['admin_flash_error']);
$purgeError = $archivePurgeError ?? null;
?>

<div class="surface surface-pad mb-3">
    <div class="section-title"><i class="bi bi-archive"></i> Archived boards</div>
    <p class="text-muted small mb-0">
        Boards you can still open after archive. Use <strong>Archive downloads</strong> on a board to generate a ZIP,
        or download the latest ready snapshot from here when one exists.
        <?php if (!empty($isAdmin)): ?>
            Admins can <strong>permanently erase</strong> a board here after typing its exact name — irreversible.
        <?php endif; ?>
    </p>
</div>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars((string)$flashSuccess) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars((string)$flashError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($purgeError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars((string)$purgeError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($archiveRows === []): ?>
    <div class="surface surface-pad text-center">
        <div class="mb-2" style="font-size: 1.75rem; color: var(--st-text-muted);"><i class="bi bi-inbox"></i></div>
        <p class="text-muted mb-2">No archived boards in your directory right now.</p>
        <a class="btn btn-sm btn-outline-secondary" href="/admin/workspace-projects.php?show_archived=1">Open Projects (show archived)</a>
    </div>
<?php else: ?>
    <div class="surface mb-3">
        <table class="task-table">
            <thead>
                <tr>
                    <th>Board</th>
                    <th>Updated</th>
                    <th>ZIP status</th>
                    <th style="text-align: right; width: 280px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($archiveRows as $row): ?>
                    <?php
                    $p = $row['project'];
                    $pid = (int)$p['id'];
                    $ready = $row['latest_ready'];
                    $pending = !empty($row['pending']);
                    $hasReadyFile = !empty($row['has_ready_file']);
                    $boardName = (string)$p['name'];
                    ?>
                    <tr>
                        <td>
                            <a href="/admin/project.php?id=<?= $pid ?>&amp;tab=archives">
                                <strong><?= htmlspecialchars($boardName) ?></strong>
                            </a>
                            <?php if (!empty($p['description'])): ?>
                                <div class="text-muted small"><?= htmlspecialchars((string)$p['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars((string)($p['updated_at'] ?? '')) ?></td>
                        <td>
                            <?php if ($ready): ?>
                                <span class="tag-chip">ready</span>
                                <div class="text-muted small mt-1">
                                    <?= htmlspecialchars((string)($ready['created_at'] ?? '')) ?>
                                    <?php if (!empty($ready['byte_size'])): ?>
                                        · <?= htmlspecialchars(number_format(((int)$ready['byte_size']) / 1024, 1)) ?> KB
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($pending): ?>
                                <span class="tag-chip">building…</span>
                            <?php else: ?>
                                <span class="text-muted small">No ZIP yet</span>
                            <?php endif; ?>
                        </td>
                        <td class="task-actions" style="text-align: right;">
                            <a class="btn btn-sm btn-outline-secondary" href="/admin/project.php?id=<?= $pid ?>&amp;tab=archives">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open
                            </a>
                            <?php if ($ready): ?>
                                <a class="btn btn-sm btn-outline-primary" href="/api/download-board-export.php?id=<?= (int)$ready['id'] ?>">
                                    <i class="bi bi-download me-1"></i>Download
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($isAdmin)): ?>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#purge-<?= $pid ?>"
                                    aria-expanded="false"
                                    aria-controls="purge-<?= $pid ?>"
                                >
                                    Erase…
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($isAdmin)): ?>
                    <tr class="collapse" id="purge-<?= $pid ?>">
                        <td colspan="4" class="bg-light">
                            <form method="post" action="/admin/settings.php?tab=archived-boards" class="p-3" onsubmit="return confirm('This permanently deletes the board and all of its tasks, docs, and files. This cannot be undone.');">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="settings_action" value="purge_directory_project">
                                <input type="hidden" name="project_id" value="<?= $pid ?>">
                                <p class="small mb-2">
                                    Type the board name exactly to permanently erase <strong><?= htmlspecialchars($boardName) ?></strong>
                                    (tasks, documents, lists, members, and local files).
                                </p>
                                <div class="d-flex flex-wrap gap-2 align-items-end">
                                    <div class="flex-grow-1" style="min-width: 12rem;">
                                        <label class="form-label small mb-1" for="confirm-name-<?= $pid ?>">Board name</label>
                                        <input class="form-control form-control-sm" type="text" name="confirm_name" id="confirm-name-<?= $pid ?>" autocomplete="off" required>
                                    </div>
                                    <?php if (!$hasReadyFile): ?>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox" name="acknowledge_no_export" value="1" id="ack-<?= $pid ?>" required>
                                            <label class="form-check-label small" for="ack-<?= $pid ?>">No ZIP on disk — erase anyway</label>
                                        </div>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-danger">Permanently delete</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
