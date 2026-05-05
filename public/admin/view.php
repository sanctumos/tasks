<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /admin/');
    exit();
}

$task = getTaskById($id);
if (!$task) {
    header('Location: /admin/');
    exit();
}

$currentUser = getCurrentUser();
if (!$currentUser || !userCanAccessTaskForViewer($currentUser, $task)) {
    header('Location: /admin/');
    exit();
}

$statuses = listTaskStatuses();
$statusMap = [];
foreach ($statuses as $s) { $statusMap[$s['slug']] = $s; }

$users = listUsers();

$accessibleProjects = listDirectoryProjectsForUser($currentUser, 500);
$projectsById = [];
foreach ($accessibleProjects as $p) { $projectsById[(int)$p['id']] = $p; }
$currentProjectId = (int)($task['project_id'] ?? 0);
if ($currentProjectId > 0 && !isset($projectsById[$currentProjectId])) {
    // Always include the task's current project even if the viewer isn't a
    // member, so the dropdown reflects truth instead of forcing a wrong move.
    $cp = getDirectoryProjectById($currentProjectId);
    if ($cp) {
        $accessibleProjects[] = $cp;
        $projectsById[$currentProjectId] = $cp;
        usort($accessibleProjects, function ($a, $b) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }
}

$tagsText = !empty($task['tags']) ? implode(', ', $task['tags']) : '';

$dueAtValue = '';
if (!empty($task['due_at'])) {
    try {
        $dueAtValue = (new DateTime((string)$task['due_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        $dueAtValue = '';
    }
}

$watchers = $task['watchers'] ?? [];
$attachments = $task['attachments'] ?? [];
$comments = $task['comments'] ?? [];
$commentCount = count($comments);
$attachmentCount = count($attachments);
$watcherCount = count($watchers);

$currentUserId = (int)$currentUser['id'];
$isWatching = false;
foreach ($watchers as $w) {
    if ((int)($w['user_id'] ?? 0) === $currentUserId) { $isWatching = true; break; }
}

$flashSuccess = $_SESSION['admin_flash_success'] ?? null;
$flashError = $_SESSION['admin_flash_error'] ?? null;
unset($_SESSION['admin_flash_success'], $_SESSION['admin_flash_error']);

$pageTitle = '#' . (int)$task['id'] . ' ' . substr((string)$task['title'], 0, 50);
$adminBreadcrumbs = [['href' => '/admin/', 'label' => 'Tasks']];
$pid = (int)($task['project_id'] ?? 0);
if ($pid > 0 && ($dp = getDirectoryProjectById($pid))) {
    $adminBreadcrumbs[] = ['href' => '/admin/workspace-projects.php', 'label' => 'Projects'];
    $adminBreadcrumbs[] = ['href' => '/admin/project.php?id=' . $pid, 'label' => (string)$dp['name']];
}
$tit = (string)$task['title'];
$crumbTitle = (strlen($tit) > 56) ? (substr($tit, 0, 53) . '…') : $tit;
$adminBreadcrumbs[] = ['label' => '#' . (int)$task['id'] . ' · ' . $crumbTitle];

require __DIR__ . '/_layout_top.php';
?>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($flashSuccess) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($flashError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="page-header task-header">
    <div class="page-header__title task-header__title">
        <div class="task-title-row">
            <h1 class="task-title js-inline-edit-target" data-edit-target="title-edit"><?= htmlspecialchars($task['title']) ?></h1>
            <button type="button" class="btn btn-sm btn-link task-title-rename js-inline-edit-toggle" data-edit-target="title-edit" title="Rename task">
                <i class="bi bi-pencil"></i><span class="visually-hidden">Rename</span>
            </button>
        </div>
        <form id="title-edit" method="post" action="/admin/update.php" class="js-inline-edit-form d-none">
            <?= csrfInputField() ?>
            <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
            <input type="hidden" name="redirect_to" value="/admin/view.php?id=<?= (int)$task['id'] ?>">
            <div class="d-flex gap-2 align-items-center">
                <input class="form-control form-control-sm flex-grow-1" name="title" value="<?= htmlspecialchars($task['title']) ?>" required>
                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check-lg"></i></button>
                <button class="btn btn-outline-secondary btn-sm js-inline-edit-cancel" type="button" data-edit-target="title-edit"><i class="bi bi-x-lg"></i></button>
            </div>
        </form>

        <div class="task-header__chips">
            <span class="meta-chip" title="Task ID">#<?= (int)$task['id'] ?></span>
            <?php if (!empty($task['directory_project']['name'])): ?>
                <a class="meta-chip meta-chip--link" href="/admin/project.php?id=<?= (int)$task['directory_project']['id'] ?>" title="Open project">
                    <i class="bi bi-kanban"></i><?= htmlspecialchars($task['directory_project']['name']) ?>
                </a>
            <?php else: ?>
                <span class="meta-chip meta-chip--warn" title="This task is not attached to a directory project">
                    <i class="bi bi-exclamation-triangle"></i>orphan task
                </span>
            <?php endif; ?>
            <span class="meta-chip" title="<?= htmlspecialchars(st_absolute_time_attr($task['created_at'] ?? null)) ?>">
                <i class="bi bi-clock-history"></i>opened <?= st_relative_time($task['created_at'] ?? null) ?> by <?= htmlspecialchars($task['created_by_username'] ?? '—') ?>
            </span>
            <span class="meta-chip" title="<?= htmlspecialchars(st_absolute_time_attr($task['updated_at'] ?? null)) ?>">
                <i class="bi bi-arrow-clockwise"></i>updated <?= st_relative_time($task['updated_at'] ?? null) ?>
            </span>
        </div>
    </div>

    <div class="page-header__actions task-header__actions">
        <form method="post" action="/admin/watch.php" class="m-0">
            <?= csrfInputField() ?>
            <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
            <input type="hidden" name="action" value="<?= $isWatching ? 'unwatch' : 'watch' ?>">
            <button type="submit" class="btn btn-sm <?= $isWatching ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <i class="bi <?= $isWatching ? 'bi-eye-fill' : 'bi-eye' ?> me-1"></i><?= $isWatching ? 'Watching' : 'Watch' ?>
                <span class="ms-1 text-muted small">· <?= (int)$watcherCount ?></span>
            </button>
        </form>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-three-dots"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item js-inline-edit-toggle" href="#description-edit" data-edit-target="description-edit"><i class="bi bi-pencil me-2"></i>Edit description</a></li>
                <li><a class="dropdown-item js-copy-link" href="#" data-copy-url="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '')) ?>"><i class="bi bi-link-45deg me-2"></i>Copy link</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="/admin/delete.php" onsubmit="return confirm('Delete task #<?= (int)$task['id'] ?>? This cannot be undone.');" class="m-0">
                        <?= csrfInputField() ?>
                        <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                        <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Delete task</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-8">

        <div class="surface surface-pad mb-3" id="description-card">
            <div class="section-title-row">
                <div class="section-title"><i class="bi bi-card-text"></i> Description</div>
                <button type="button" class="btn btn-sm btn-link js-inline-edit-toggle" data-edit-target="description-edit">
                    <i class="bi bi-pencil"></i> Edit
                </button>
            </div>
            <div class="description-display js-inline-edit-target" data-edit-target="description-edit">
                <?php if (!empty($task['body'])): ?>
                    <div class="description-body"><?= nl2br(htmlspecialchars((string)$task['body'])) ?></div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No description yet. <a class="js-inline-edit-toggle" href="#" data-edit-target="description-edit">Add one</a>.</p>
                <?php endif; ?>
            </div>
            <form id="description-edit" method="post" action="/admin/update.php" class="js-inline-edit-form d-none mt-2">
                <?= csrfInputField() ?>
                <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                <input type="hidden" name="redirect_to" value="/admin/view.php?id=<?= (int)$task['id'] ?>">
                <div class="mb-2">
                    <textarea class="form-control" name="body" rows="8" placeholder="Task description / details…"><?= htmlspecialchars((string)($task['body'] ?? '')) ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <button class="btn btn-outline-secondary btn-sm js-inline-edit-cancel" type="button" data-edit-target="description-edit">Cancel</button>
                </div>
            </form>
        </div>

        <div class="surface surface-pad mb-3" id="discussion">
            <div class="section-title-row">
                <div class="section-title">
                    <i class="bi bi-chat-left-text"></i> Discussion
                    <span class="count"><?= (int)$commentCount ?></span>
                </div>
                <a href="#discussion-composer" class="btn btn-sm btn-link"><i class="bi bi-plus-lg"></i> New comment</a>
            </div>

            <?php if ($commentCount === 0): ?>
                <div class="empty-hint">No comments yet. Start the conversation below.</div>
            <?php else: ?>
                <ol class="comment-thread">
                    <?php foreach ($comments as $c):
                        $username = (string)($c['username'] ?? '—');
                        $body = (string)($c['comment'] ?? '');
                        $createdIso = (string)($c['created_at'] ?? '');
                        $isMine = (int)($c['user_id'] ?? 0) === $currentUserId;
                    ?>
                        <li class="comment-item<?= $isMine ? ' comment-item--mine' : '' ?>" id="comment-<?= (int)($c['id'] ?? 0) ?>">
                            <div class="comment-avatar-col">
                                <?= st_avatar_html($username) ?>
                            </div>
                            <div class="comment-body-col">
                                <div class="comment-meta">
                                    <span class="comment-author"><?= htmlspecialchars($username) ?></span>
                                    <span class="comment-time" title="<?= htmlspecialchars(st_absolute_time_attr($createdIso)) ?>"><?= st_relative_time($createdIso) ?></span>
                                </div>
                                <div class="comment-body"><?= st_format_comment_body($body) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <div id="discussion-end"></div>
            <?php endif; ?>

            <form id="discussion-composer" class="comment-composer mt-3" method="post" action="/admin/comment.php">
                <?= csrfInputField() ?>
                <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                <div class="comment-composer__row">
                    <div class="comment-avatar-col">
                        <?= st_avatar_html($currentUser['username'] ?? '?') ?>
                    </div>
                    <div class="comment-composer__main">
                        <textarea class="form-control" name="comment" rows="3" maxlength="2000" placeholder="Write a comment, ask a question, or @mention context..." required></textarea>
                        <div class="comment-composer__actions">
                            <span class="fine-print">Plain text · URLs become clickable · max 2000 chars</span>
                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-send me-1"></i>Post comment</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($attachmentCount > 0): ?>
            <div class="surface surface-pad mb-3" id="attachments">
                <div class="section-title">
                    <i class="bi bi-paperclip"></i> Attachments
                    <span class="count"><?= (int)$attachmentCount ?></span>
                </div>
                <ul class="attachment-list">
                    <?php foreach ($attachments as $a): ?>
                        <li>
                            <i class="bi bi-file-earmark text-muted"></i>
                            <a href="<?= htmlspecialchars((string)$a['file_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars((string)$a['file_name']) ?></a>
                            <?php if (!empty($a['mime_type'])): ?>
                                <span class="text-muted small ms-2"><?= htmlspecialchars((string)$a['mime_type']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($a['size_bytes'])): ?>
                                <span class="text-muted small ms-2"><?= (int)$a['size_bytes'] ?> bytes</span>
                            <?php endif; ?>
                            <span class="text-muted small ms-2">· <?= htmlspecialchars((string)($a['uploaded_by_username'] ?? '')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="fine-print mb-0 mt-2">Attachments are added via the API today (`POST /api/add-attachment.php`).</p>
            </div>
        <?php endif; ?>

    </div>

    <div class="col-12 col-lg-4">
        <aside class="metadata-rail">
            <div class="metadata-rail__row">
                <label>Status</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <select class="form-select form-select-sm js-autosave" name="status">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= htmlspecialchars($s['slug']) ?>" <?= $task['status'] === $s['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Priority</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <select class="form-select form-select-sm js-autosave" name="priority">
                        <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                            <option value="<?= $p ?>" <?= ($task['priority'] ?? 'normal') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Assignee</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <select class="form-select form-select-sm js-autosave" name="assigned_to_user_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (string)($task['assigned_to_user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Due</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <input type="datetime-local" class="form-control form-control-sm js-autosave-blur" name="due_at" value="<?= htmlspecialchars($dueAtValue) ?>">
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Project</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <select class="form-select form-select-sm js-autosave" name="project_id">
                        <?php if ($currentProjectId === 0): ?>
                            <option value="" selected>(none — pick to fix)</option>
                        <?php endif; ?>
                        <?php foreach ($accessibleProjects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $currentProjectId === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Tags</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <input class="form-control form-control-sm js-autosave-blur" name="tags" value="<?= htmlspecialchars($tagsText) ?>" placeholder="comma,separated">
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Rank</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <input type="number" class="form-control form-control-sm js-autosave-blur" name="rank" value="<?= (int)($task['rank'] ?? 0) ?>">
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Recurrence</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <input class="form-control form-control-sm js-autosave-blur" name="recurrence_rule" value="<?= htmlspecialchars((string)($task['recurrence_rule'] ?? '')) ?>" placeholder="RRULE (optional)">
                </form>
            </div>

            <?php if ($watcherCount > 0): ?>
                <div class="metadata-rail__row metadata-rail__row--block">
                    <label>Watchers</label>
                    <div class="metadata-rail__value watcher-list">
                        <?php foreach ($watchers as $w):
                            $wname = (string)($w['username'] ?? '—');
                        ?>
                            <span class="watcher-chip" title="<?= htmlspecialchars($wname) ?>">
                                <?= st_avatar_html($wname, 'st-avatar--xs') ?>
                                <span><?= htmlspecialchars($wname) ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
