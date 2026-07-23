<?php
/**
 * Settings tab: archived boards the viewer can access + ZIP download shortcuts.
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
    ];
}
?>

<div class="surface surface-pad mb-3">
    <div class="section-title"><i class="bi bi-archive"></i> Archived boards</div>
    <p class="text-muted small mb-0">
        Boards you can still open after archive. Use <strong>Archive downloads</strong> on a board to generate a ZIP,
        or download the latest ready snapshot from here when one exists.
    </p>
</div>

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
                    <th style="text-align: right; width: 220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($archiveRows as $row): ?>
                    <?php
                    $p = $row['project'];
                    $pid = (int)$p['id'];
                    $ready = $row['latest_ready'];
                    $pending = !empty($row['pending']);
                    ?>
                    <tr>
                        <td>
                            <a href="/admin/project.php?id=<?= $pid ?>&amp;tab=archives">
                                <strong><?= htmlspecialchars((string)$p['name']) ?></strong>
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
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
