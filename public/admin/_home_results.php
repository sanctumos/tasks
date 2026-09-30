<?php
/**
 * Home cross-project board/list results fragment.
 * Expects in scope: $tasks, $total, $grouped, $statuses, $statusMap, $users,
 * $directoryProjectByName, $initialView.
 */
if (!isset($total)) {
    $total = is_array($tasks ?? null) ? count($tasks) : 0;
}
?>
<div id="st-home-results" class="st-home-results" data-total-count="<?= (int)$total ?>" data-match-count="<?= (int)$total ?>">
<div data-view-root data-view="<?= htmlspecialchars($initialView) ?>" style="position: relative;">

    <?php /* ------- BOARD VIEW ------- */ ?>
    <div class="board" data-when-view="board" style="<?= $initialView === 'board' ? '' : 'display:none;' ?>">
        <?php foreach ($statuses as $s):
            $kind = st_status_kind(['slug' => $s['slug'], 'is_done' => $s['is_done']]);
            $count = count($grouped[$s['slug']] ?? []);
        ?>
            <div class="swimlane">
                <div class="swimlane__header">
                    <span class="status-pill status-pill--<?= $kind ?>"><?= htmlspecialchars($s['label']) ?></span>
                    <span class="swimlane__count"><?= $count ?></span>
                </div>
                <div class="swimlane__body">
                    <?php if ($count === 0): ?>
                        <div class="swimlane__empty">No tasks here.</div>
                    <?php endif; ?>
                    <?php foreach (($grouped[$s['slug']] ?? []) as $t):
                        $projectLink = null;
                        if (!empty($t['project'])) {
                            $key = strtolower((string)$t['project']);
                            if (isset($directoryProjectByName[$key])) {
                                $projectLink = '/admin/project.php?id=' . (int)$directoryProjectByName[$key]['id'];
                            }
                        }
                    ?>
                        <div class="task-card task-card--interactive">
                            <a class="task-card__title text-decoration-none stretched-link" href="/admin/view.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                            <div class="task-card__meta">
                                <?= st_priority_chip_html((string)($t['priority'] ?? 'normal')) ?>
                                <?php if (!empty($t['project'])): ?>
                                    <?php if ($projectLink): ?>
                                        <a href="<?= $projectLink ?>" class="position-relative" style="z-index:2;"><i class="bi bi-folder2"></i> <?= htmlspecialchars($t['project']) ?></a>
                                    <?php else: ?>
                                        <span><i class="bi bi-folder2"></i> <?= htmlspecialchars($t['project']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($t['due_at'])): ?>
                                    <span title="Due <?= htmlspecialchars($t['due_at']) ?>"><i class="bi bi-calendar-event"></i> <?= htmlspecialchars(substr((string)$t['due_at'], 0, 10)) ?></span>
                                <?php endif; ?>
                                <?= st_signal_icons_html($t) ?>
                            </div>
                            <div class="task-card__footer">
                                <span class="task-card__assignee"><?= st_render_task_assignee_html($t) ?></span>
                                <span class="text-muted small"><?= st_relative_time($t['updated_at'] ?? null) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php /* ------- LIST VIEW (desktop table + mobile cards) ------- */ ?>
    <div data-when-view="list" style="<?= $initialView === 'list' ? '' : 'display:none;' ?>">
        <div class="surface task-list-table">
            <table class="task-table">
                <thead>
                <tr>
                    <th>Title</th>
                    <th style="width: 130px;">Status</th>
                    <th style="width: 110px;">Priority</th>
                    <th style="width: 160px;">Project</th>
                    <th style="width: 160px;">Assignee</th>
                    <th style="width: 110px;">Due</th>
                    <th style="width: 110px;">Updated</th>
                    <th style="width: 110px;">Signals</th>
                    <th style="width: 90px; text-align: right;">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t):
                    $projectLink = null;
                    if (!empty($t['project'])) {
                        $key = strtolower((string)$t['project']);
                        if (isset($directoryProjectByName[$key])) {
                            $projectLink = '/admin/project.php?id=' . (int)$directoryProjectByName[$key]['id'];
                        }
                    }
                ?>
                    <tr>
                        <td class="task-title-cell">
                            <a href="/admin/view.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                            <div class="text-muted small">#<?= (int)$t['id'] ?> · by <?= htmlspecialchars($t['created_by_username'] ?? '') ?></div>
                        </td>
                        <td>
                            <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm js-autosave" name="status" aria-label="Status">
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?= htmlspecialchars($s['slug']) ?>" <?= $t['status'] === $s['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm js-autosave" name="priority" aria-label="Priority">
                                    <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                                        <option value="<?= $p ?>" <?= ($t['priority'] ?? 'normal') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="small">
                            <?php if (!empty($t['project'])): ?>
                                <?php if ($projectLink): ?>
                                    <a class="text-decoration-none" href="<?= $projectLink ?>"><i class="bi bi-folder2 me-1"></i><?= htmlspecialchars($t['project']) ?></a>
                                <?php else: ?>
                                    <span class="text-muted"><i class="bi bi-folder2 me-1"></i><?= htmlspecialchars($t['project']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm js-autosave" name="assigned_to_user_id" aria-label="Assignee">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>" <?= (string)($t['assigned_to_user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="small text-muted"><?= !empty($t['due_at']) ? htmlspecialchars(substr((string)$t['due_at'], 0, 10)) : '—' ?></td>
                        <td class="small text-muted"><?= st_relative_time($t['updated_at'] ?? null) ?></td>
                        <td class="small"><?= st_signal_icons_html($t) ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="task-actions">
                            <a class="btn btn-sm btn-outline-secondary" title="Open" href="/admin/view.php?id=<?= (int)$t['id'] ?>"><i class="bi bi-arrow-right-short"></i></a>
                            <form method="post" action="/admin/delete.php" class="d-inline m-0" onsubmit="return confirm('Delete task #<?= (int)$t['id'] ?>?');">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                    <tr><td colspan="9" class="text-muted text-center py-4">No tasks match these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="task-list-cards">
            <?php foreach ($tasks as $t):
                $projectLink = null;
                if (!empty($t['project'])) {
                    $key = strtolower((string)$t['project']);
                    if (isset($directoryProjectByName[$key])) {
                        $projectLink = '/admin/project.php?id=' . (int)$directoryProjectByName[$key]['id'];
                    }
                }
            ?>
                <div class="task-card task-card--interactive">
                    <a class="task-card__title stretched-link text-decoration-none" href="/admin/view.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                    <div class="task-card__meta">
                        <?= st_status_pill_html($t, $statusMap) ?>
                        <?= st_priority_chip_html((string)($t['priority'] ?? 'normal')) ?>
                        <?php if (!empty($t['project'])): ?>
                            <?php if ($projectLink): ?>
                                <a href="<?= $projectLink ?>" class="position-relative" style="z-index:2;"><i class="bi bi-folder2"></i> <?= htmlspecialchars($t['project']) ?></a>
                            <?php else: ?>
                                <span><i class="bi bi-folder2"></i> <?= htmlspecialchars($t['project']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?= st_signal_icons_html($t) ?>
                    </div>
                    <div class="task-card__footer">
                        <span class="task-card__assignee"><?= st_render_task_assignee_html($t) ?></span>
                        <span class="text-muted small"><?= st_relative_time($t['updated_at'] ?? null) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($tasks)): ?>
                <div class="empty-hint">No tasks match these filters.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

</div>
