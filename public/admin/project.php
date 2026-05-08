<?php
/**
 * Project workspace v2 — single project surface with tabs:
 *   Tasks  ·  Lists  ·  Members  ·  Settings
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$project = $id > 0 ? getDirectoryProjectById($id) : null;
if (!$project || !userCanAccessDirectoryProject($currentUser, $project)) {
    header('Location: /admin/workspace-projects.php');
    exit;
}

$canManage = userCanManageDirectoryProject($currentUser, $project);
$tab = (string)($_GET['tab'] ?? 'lists');
if (!in_array($tab, ['tasks', 'lists', 'docs', 'members', 'settings'], true)) {
    $tab = 'lists';
}

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    requireCsrfToken();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update') {
        $fields = [
            'name' => (string)($_POST['name'] ?? ''),
            'description' => isset($_POST['description']) ? (string)$_POST['description'] : null,
            'status' => (string)($_POST['status'] ?? 'active'),
            'client_visible' => isset($_POST['client_visible']),
            'all_access' => isset($_POST['all_access']),
        ];
        $result = updateDirectoryProject((int)$currentUser['id'], $id, $fields);
        if ($result['success']) {
            $message = 'Project updated.';
            $project = getDirectoryProjectById($id);
        } else {
            $message = $result['error'] ?? 'Update failed';
            $messageType = 'danger';
        }
    } elseif ($action === 'add_member') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $role = (string)($_POST['member_role'] ?? 'member');
        $result = addProjectMember((int)$currentUser['id'], $id, $uid, $role);
        if ($result['success']) {
            $message = 'Member added or updated.';
        } else {
            $message = $result['error'] ?? 'Could not add member';
            $messageType = 'danger';
        }
    } elseif ($action === 'remove_member') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $result = removeProjectMember((int)$currentUser['id'], $id, $uid);
        if ($result['success']) {
            $message = 'Member removed.';
        } else {
            $message = $result['error'] ?? 'Could not remove';
            $messageType = 'danger';
        }
    } elseif ($action === 'create_list') {
        $name = trim((string)($_POST['list_name'] ?? ''));
        $result = createTodoList((int)$currentUser['id'], $id, $name);
        if ($result['success']) {
            $message = 'To-do list created.';
        } else {
            $message = $result['error'] ?? 'Could not create list';
            $messageType = 'danger';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $message = 'You do not have permission to change this project.';
    $messageType = 'danger';
}

$members = listProjectMembers($id);
$lists = listTodoListsForProject($currentUser, $id);
if (empty($lists) && $canManage) {
    $seedList = createTodoList((int)$currentUser['id'], $id, 'General');
    if (!empty($seedList['success'])) {
        $lists = listTodoListsForProject($currentUser, $id);
    }
}
$projectDocsCount = countDocumentsForUser($currentUser, $id);
$projectDocCurrentDir = '';
$projectDocs = [];
$projectDocsDirChildren = [];
$projectDocsInDir = [];
if ($tab === 'docs') {
    $projectDocCurrentDir = normalizeDocumentDirectoryPath((string)($_GET['dir'] ?? ''));
    $projectDocs = listDocumentsForUser($currentUser, 500, $id);
    $aggProjectDocs = aggregateDocumentsForDirectoryView($projectDocs, $projectDocCurrentDir);
    $projectDocsDirChildren = $aggProjectDocs['dir_children'];
    $projectDocsInDir = $aggProjectDocs['documents_in_dir'];
}
$orgUsers = [];
$pOrgId = (int)$project['org_id'];
foreach (listUsers(false) as $u) {
    if (userMayAccessOrganization($u, $pOrgId)) {
        $orgUsers[] = $u;
    }
}

// Tasks belonging to this project (by project_id) — for the Tasks tab
$projectTasksResult = listTasks([
    'project_id' => $id,
    'sort_by' => 'rank',
    'sort_dir' => 'ASC',
    'limit' => 250,
    'offset' => 0,
], true);
$projectTasks = $projectTasksResult['tasks'];

// Also catch tasks linked by legacy text-name (project name match)
$legacyTasksResult = listTasks([
    'project' => $project['name'],
    'sort_by' => 'rank',
    'sort_dir' => 'ASC',
    'limit' => 250,
    'offset' => 0,
], true);
foreach ($legacyTasksResult['tasks'] as $lt) {
    if ((int)($lt['project_id'] ?? 0) !== $id) {
        $projectTasks[] = $lt;
    }
}
$totalTasks = count($projectTasks);

$statuses = listTaskStatuses();
$statusMap = [];
foreach ($statuses as $s) { $statusMap[$s['slug']] = $s; }
$grouped = [];
foreach ($statuses as $s) { $grouped[$s['slug']] = []; }
foreach ($projectTasks as $t) {
    $slug = (string)$t['status'];
    if (!isset($grouped[$slug])) $grouped[$slug] = [];
    $grouped[$slug][] = $t;
}

$users = listUsers(false);

// --- Lists view (Basecamp-style): group project tasks by list_id ----------
$tasksByList = [];
$tasksUnfiled = [];
foreach ($projectTasks as $t) {
    $lid = (int)($t['list_id'] ?? 0);
    if ($lid <= 0) {
        $tasksUnfiled[] = $t;
        continue;
    }
    $tasksByList[$lid] ??= [];
    $tasksByList[$lid][] = $t;
}
foreach ($tasksByList as $lid => &$rows) {
    usort($rows, function ($a, $b) {
        $aDone = (int)($a['status_is_done'] ?? 0);
        $bDone = (int)($b['status_is_done'] ?? 0);
        if ($aDone !== $bDone) return $aDone <=> $bDone;
        $ar = (int)($a['rank'] ?? 0);
        $br = (int)($b['rank'] ?? 0);
        if ($ar !== $br) return $ar <=> $br;
        return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });
}
unset($rows);

$defaultStatusSlug = 'todo';
$doneStatusSlug = 'done';
foreach ($statuses as $s) {
    if ((int)($s['is_default'] ?? 0) === 1) {
        $defaultStatusSlug = (string)$s['slug'];
        break;
    }
}
foreach ($statuses as $s) {
    if ((int)($s['is_done'] ?? 0) === 1) {
        $doneStatusSlug = (string)$s['slug'];
        break;
    }
}

$projectOrgName = '';
if (!empty($project['org_id']) && ($porg = getOrganizationById((int)$project['org_id']))) {
    $projectOrgName = (string)$porg['name'];
}

$pageTitle = $project['name'];
$tabHuman = [
    'tasks' => 'Tasks',
    'lists' => 'Lists',
    'docs' => 'Docs',
    'members' => 'Members',
    'settings' => 'Settings',
][$tab] ?? ucfirst($tab);
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
    ['href' => '/admin/workspace-projects.php', 'label' => 'Projects'],
];
if ($tab === 'lists') {
    $adminBreadcrumbs[] = ['label' => (string)$project['name']];
} else {
    $adminBreadcrumbs[] = ['href' => '/admin/project.php?id=' . $id . '&tab=lists', 'label' => (string)$project['name']];
    $adminBreadcrumbs[] = ['label' => $tabHuman];
}
require __DIR__ . '/_layout_top.php';

function st_tab_link(string $tab, string $active, string $label, string $icon, ?int $count = null): string {
    $cls = $active === $tab ? 'active' : '';
    $href = '/admin/project.php?id=' . (int)($_GET['id'] ?? 0) . '&tab=' . urlencode($tab);
    $countHtml = $count !== null ? '<span class="count">' . (int)$count . '</span>' : '';
    $aria = $active === $tab ? ' aria-current="page"' : '';
    return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '"' . $aria . '><i class="bi ' . htmlspecialchars($icon) . '"></i><span>' . htmlspecialchars($label) . '</span>' . $countHtml . '</a>';
}
?>

<div class="page-header">
    <div class="page-header__title">
        <h1><?= htmlspecialchars($project['name']) ?></h1>
        <div class="subtitle">
            <?php if ($projectOrgName !== ''): ?><i class="bi bi-building"></i> <?= htmlspecialchars($projectOrgName) ?> · <?php endif; ?>
            <?php if (!empty($project['description'])): ?><?= htmlspecialchars($project['description']) ?> · <?php endif; ?>
            <span class="status-pill status-pill--<?= $project['status'] === 'active' ? 'doing' : ($project['status'] === 'archived' ? 'todo' : 'blocked') ?>"><?= htmlspecialchars((string)$project['status']) ?></span>
            <?php if (!empty($project['all_access'])): ?> · <i class="bi bi-globe"></i> all-access<?php endif; ?>
            <?php if (!empty($project['client_visible'])): ?> · <i class="bi bi-eye"></i> client-visible<?php endif; ?>
        </div>
    </div>
    <div class="page-header__actions d-flex align-items-center flex-wrap gap-2">
        <?= st_doc_help('projects', 'Projects tabs lists docs and members') ?>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#newTaskModal" <?= $canManage ? '' : 'disabled' ?>>
            <i class="bi bi-plus-lg"></i> New task
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show"><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<nav class="tabbar" aria-label="Project sections">
    <?= st_tab_link('lists', $tab, 'Lists', 'bi-card-checklist', count($lists)) ?>
    <?= st_tab_link('tasks', $tab, 'Tasks', 'bi-list-check', $totalTasks) ?>
    <?= st_tab_link('docs', $tab, 'Docs', 'bi-journals', $projectDocsCount) ?>
    <?= st_tab_link('members', $tab, 'Members', 'bi-people', count($members)) ?>
    <?php if ($canManage): ?>
        <?= st_tab_link('settings', $tab, 'Settings', 'bi-gear', null) ?>
    <?php endif; ?>
</nav>

<?php if ($tab === 'tasks'): ?>

    <?php if ($totalTasks === 0): ?>
        <div class="surface surface-pad text-center">
            <div class="mb-3" style="font-size: 2rem; color: var(--st-text-muted);"><i class="bi bi-inbox"></i></div>
            <h2 class="h5 mb-1">No tasks here yet</h2>
            <p class="text-muted small mb-3">Create the first task in this project to start tracking work.</p>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#newTaskModal" <?= $canManage ? '' : 'disabled' ?>>
                <i class="bi bi-plus-lg me-1"></i>New task
            </button>
        </div>
    <?php else: ?>
        <div class="board">
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
                        <?php foreach (($grouped[$s['slug']] ?? []) as $t): ?>
                            <div class="task-card task-card--interactive">
                                <a class="task-card__title text-decoration-none stretched-link" href="/admin/view.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                                <div class="task-card__meta">
                                    <?= st_priority_chip_html((string)($t['priority'] ?? 'normal')) ?>
                                    <?php if (!empty($t['due_at'])): ?>
                                        <span><i class="bi bi-calendar-event"></i> <?= htmlspecialchars(substr((string)$t['due_at'], 0, 10)) ?></span>
                                    <?php endif; ?>
                                    <?= st_signal_icons_html($t) ?>
                                </div>
                                <div class="task-card__footer">
                                    <span class="task-card__assignee">
                                        <?php if (!empty($t['assigned_to_user_id'])): ?>
                                            <i class="bi bi-person-fill"></i> <?= htmlspecialchars($t['assigned_to_username'] ?? '') ?>
                                        <?php else: ?>
                                            <i class="bi bi-person"></i> <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="text-muted small"><?= st_relative_time($t['updated_at'] ?? null) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'lists'): ?>

    <?php $listsRedirect = '/admin/project.php?id=' . (int)$id . '&tab=lists'; ?>
    <div class="todolist-toolbar">
        <div class="section-title">
            <i class="bi bi-card-checklist"></i>
            To-do lists
            <span class="count"><?= count($lists) ?></span>
        </div>
        <?php if ($canManage): ?>
            <form method="post" action="/admin/project.php?id=<?= (int)$id ?>&amp;tab=lists" class="todolist-toolbar__new">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="create_list">
                <input class="form-control form-control-sm" name="list_name" placeholder="New list name…" required maxlength="200">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Add list
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($lists) && empty($tasksUnfiled)): ?>
        <div class="surface surface-pad text-center">
            <div class="mb-3" style="font-size: 2rem; color: var(--st-text-muted);"><i class="bi bi-card-checklist"></i></div>
            <h2 class="h5 mb-1">No lists yet</h2>
            <p class="text-muted small mb-3">Create the first to-do list above. Each list is a checklist of tasks that live inside this project.</p>
        </div>
    <?php endif; ?>

    <?php
    $renderTodoRow = function (array $t) use ($listsRedirect, $doneStatusSlug, $defaultStatusSlug, $canManage) {
        $isDone = (int)($t['status_is_done'] ?? 0) === 1;
        $rowCls = 'todo-row' . ($isDone ? ' todo-row--done' : '');
        $nextStatus = $isDone ? $defaultStatusSlug : $doneStatusSlug;
        ob_start();
        ?>
        <li class="<?= $rowCls ?>">
            <form method="post" action="/admin/update.php" class="todo-row__check">
                <?= csrfInputField() ?>
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($nextStatus) ?>">
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($listsRedirect) ?>">
                <button type="submit"
                        class="todo-checkbox<?= $isDone ? ' todo-checkbox--done' : '' ?>"
                        aria-label="<?= $isDone ? 'Mark as not done' : 'Mark as done' ?>"
                        <?= $canManage ? '' : 'disabled' ?>>
                    <?php if ($isDone): ?><i class="bi bi-check-lg"></i><?php endif; ?>
                </button>
            </form>
            <div class="todo-row__body">
                <a class="todo-row__title" href="/admin/view.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars((string)$t['title']) ?></a>
                <div class="todo-row__meta">
                    <?php if (!$isDone): ?>
                        <?= st_priority_chip_html((string)($t['priority'] ?? 'normal')) ?>
                    <?php endif; ?>
                    <?php if (!empty($t['due_at'])): ?>
                        <span class="todo-row__due"><i class="bi bi-calendar-event"></i> <?= htmlspecialchars(substr((string)$t['due_at'], 0, 10)) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($t['assigned_to_username'])): ?>
                        <span class="todo-row__assignee"><i class="bi bi-person-fill"></i> <?= htmlspecialchars((string)$t['assigned_to_username']) ?></span>
                    <?php else: ?>
                        <span class="todo-row__assignee text-muted"><i class="bi bi-person"></i> Unassigned</span>
                    <?php endif; ?>
                    <?= st_signal_icons_html($t) ?>
                </div>
            </div>
        </li>
        <?php
        return ob_get_clean();
    };
    ?>

    <?php foreach ($lists as $tl):
        $listId = (int)$tl['id'];
        $rows = $tasksByList[$listId] ?? [];
        $totalRows = count($rows);
        $doneRows = 0;
        foreach ($rows as $r) {
            if ((int)($r['status_is_done'] ?? 0) === 1) $doneRows++;
        }
        $remaining = max(0, $totalRows - $doneRows);
    ?>
        <section class="todo-list" id="list-<?= $listId ?>">
            <header class="todo-list__header">
                <div class="todo-list__title">
                    <i class="bi bi-card-checklist"></i>
                    <h2 class="h5 mb-0"><?= htmlspecialchars((string)$tl['name']) ?></h2>
                </div>
                <div class="todo-list__progress">
                    <?php if ($totalRows === 0): ?>
                        <span class="text-muted small">empty</span>
                    <?php else: ?>
                        <span class="todo-list__progress-pill"><?= $doneRows ?> / <?= $totalRows ?> done</span>
                        <?php if ($remaining > 0): ?>
                            <span class="text-muted small"><?= $remaining ?> remaining</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($totalRows === 0): ?>
                <p class="todo-list__empty text-muted small">No to-dos in this list yet.</p>
            <?php else: ?>
                <ol class="todo-list__items">
                    <?php foreach ($rows as $t): echo $renderTodoRow($t); endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <form method="post" action="/admin/create.php" class="todo-list__add">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="project" value="<?= htmlspecialchars((string)$project['name']) ?>">
                    <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                    <input type="hidden" name="list_id" value="<?= $listId ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($defaultStatusSlug) ?>">
                    <input type="hidden" name="priority" value="normal">
                    <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($listsRedirect) ?>">
                    <i class="bi bi-plus-circle text-muted"></i>
                    <input type="text" name="title" class="todo-list__add-input" placeholder="Add a to-do…" maxlength="240" data-mention="1" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Add</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if (!empty($tasksUnfiled)):
        $totalRows = count($tasksUnfiled);
        $doneRows = 0;
        foreach ($tasksUnfiled as $r) {
            if ((int)($r['status_is_done'] ?? 0) === 1) $doneRows++;
        }
    ?>
        <section class="todo-list todo-list--unfiled">
            <header class="todo-list__header">
                <div class="todo-list__title">
                    <i class="bi bi-inbox"></i>
                    <h2 class="h5 mb-0">Unfiled</h2>
                </div>
                <div class="todo-list__progress">
                    <span class="todo-list__progress-pill"><?= $doneRows ?> / <?= $totalRows ?> done</span>
                </div>
            </header>
            <p class="todo-list__empty text-muted small">These tasks aren’t attached to a to-do list yet — open one and assign it to a list to file it here.</p>
            <ol class="todo-list__items">
                <?php foreach ($tasksUnfiled as $t): echo $renderTodoRow($t); endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

<?php elseif ($tab === 'docs'): ?>
    <?php
    $buildProjectDocsUrl = static function (int $projectId, string $dirPath = ''): string {
        $dirPath = normalizeDocumentDirectoryPath($dirPath);
        $q = ['id' => $projectId, 'tab' => 'docs'];
        if ($dirPath !== '') {
            $q['dir'] = $dirPath;
        }
        return '/admin/project.php?' . http_build_query($q);
    };
    ?>

    <div class="surface surface-pad mb-3">
        <div class="section-title-row align-items-start flex-wrap gap-2">
            <div class="section-title"><i class="bi bi-journals"></i> Documents <span class="count"><?= (int)$projectDocsCount ?></span></div>
            <div class="d-flex align-items-center flex-wrap gap-2 ms-auto">
                <a class="btn btn-outline-secondary btn-sm" href="/admin/docs.php?project_id=<?= (int)$id ?><?= $projectDocCurrentDir !== '' ? '&dir=' . rawurlencode($projectDocCurrentDir) : '' ?>">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Docs workspace
                </a>
                <a class="btn btn-primary btn-sm" href="/admin/doc-create.php?project_id=<?= (int)$id ?>"><i class="bi bi-plus-lg me-1"></i>New doc</a>
            </div>
        </div>
        <?php if ($projectDocsCount === 0 && $projectDocCurrentDir === ''): ?>
            <p class="text-muted small mb-0">No docs in this project yet. Use Docs for long-form reference material — specs, runbooks, decision records, onboarding notes — with their own discussion thread.</p>
        <?php else: ?>
            <?php if ($projectDocsCount > 500): ?>
            <p class="text-muted small mb-3">Folder navigation here uses your <?= count($projectDocs) ?> most recently updated documents (capped list). Total in project: <?= (int)$projectDocsCount ?>. Use <strong>Docs workspace</strong> for the full chronological list.</p>
            <?php endif; ?>
            <?php
            $crumbPartsProject = $projectDocCurrentDir === '' ? [] : explode('/', $projectDocCurrentDir);
            $runningPathProject = '';
            ?>
            <div class="surface surface-pad mb-3">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="text-muted small"><i class="bi bi-folder2-open me-1"></i>Directory</span>
                    <a class="btn btn-sm <?= $projectDocCurrentDir === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= htmlspecialchars($buildProjectDocsUrl($id, '')) ?>">/</a>
                    <?php foreach ($crumbPartsProject as $part): ?>
                        <?php $runningPathProject = $runningPathProject === '' ? $part : ($runningPathProject . '/' . $part); ?>
                        <span class="text-muted small">/</span>
                        <a class="btn btn-sm <?= $runningPathProject === $projectDocCurrentDir ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= htmlspecialchars($buildProjectDocsUrl($id, $runningPathProject)) ?>"><?= htmlspecialchars($part) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($projectDocsDirChildren)): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($projectDocsDirChildren as $name => $cnt): ?>
                            <?php $target = $projectDocCurrentDir === '' ? $name : ($projectDocCurrentDir . '/' . $name); ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($buildProjectDocsUrl($id, $target)) ?>">
                                <i class="bi bi-folder me-1"></i><?= htmlspecialchars($name) ?>
                                <span class="text-muted">· <?= (int)$cnt ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($projectDocCurrentDir !== ''): ?>
                    <div class="text-muted small">No subdirectories here.</div>
                <?php endif; ?>
            </div>

            <?php if (empty($projectDocsInDir)): ?>
                <div class="surface surface-pad text-center text-muted mb-3">
                    No documents in this directory.
                </div>
            <?php else: ?>
            <div class="surface">
                <table class="task-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Directory</th>
                            <th>Author</th>
                            <th>Comments</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projectDocsInDir as $d): ?>
                            <tr>
                                <td class="task-title-cell">
                                    <a href="/admin/doc.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars((string)$d['title']) ?></a>
                                </td>
                                <td><span class="text-muted small"><?= htmlspecialchars((string)(normalizeDocumentDirectoryPath((string)($d['directory_path'] ?? '')) ?: '/')) ?></span></td>
                                <td><?= htmlspecialchars((string)$d['created_by_username']) ?></td>
                                <td><i class="bi bi-chat-text text-muted me-1"></i><?= (int)$d['comment_count'] ?></td>
                                <td>
                                    <span title="<?= htmlspecialchars(st_absolute_time_attr($d['updated_at'] ?? null)) ?>">
                                        <?= htmlspecialchars(st_absolute_time($d['updated_at'] ?? null)) ?>
                                        <span class="text-muted small">(<?= st_relative_time($d['updated_at'] ?? null) ?>)</span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'members'): ?>

    <div class="surface mb-3">
        <table class="task-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role</th>
                    <?php if ($canManage): ?><th style="text-align: right; width: 130px;">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($m['username']) ?></strong>
                            <div class="text-muted small"><?= htmlspecialchars($m['person_kind']) ?></div>
                        </td>
                        <td><span class="tag-chip"><?= htmlspecialchars($m['role']) ?></span></td>
                        <?php if ($canManage): ?>
                        <td class="task-actions">
                            <form method="post" action="/admin/project.php?id=<?= (int)$id ?>&amp;tab=members" class="d-inline m-0" onsubmit="return confirm('Remove this member?');">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Remove</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$members): ?>
                    <tr><td colspan="<?= $canManage ? 3 : 2 ?>" class="text-muted text-center py-4">No members yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canManage): ?>
        <div class="surface surface-pad">
            <div class="section-title"><i class="bi bi-person-plus"></i> Add member</div>
            <form method="post" action="/admin/project.php?id=<?= (int)$id ?>&amp;tab=members" class="row g-3 align-items-end">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="add_member">
                <div class="col-12 col-md-6">
                    <label class="form-label">User</label>
                    <select class="form-select" name="user_id" required>
                        <option value="">Select a user…</option>
                        <?php foreach ($orgUsers as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['person_kind'] ?? 'team_member') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="member_role">
                        <option value="member">member</option>
                        <option value="lead">lead</option>
                        <option value="client">client</option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'settings' && $canManage): ?>

    <div class="surface surface-pad">
        <div class="section-title"><i class="bi bi-gear"></i> Project settings</div>
        <form method="post" action="/admin/project.php?id=<?= (int)$id ?>&amp;tab=settings">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="update">
            <div class="row g-3">
                <div class="col-12 col-md-8">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="name" required maxlength="200" value="<?= htmlspecialchars($project['name']) ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach (['active', 'archived', 'trashed'] as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>" <?= ($project['status'] ?? '') === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <input class="form-control" name="description" value="<?= htmlspecialchars((string)($project['description'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="client_visible" id="cv" value="1" <?= !empty($project['client_visible']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cv">Client-visible</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="all_access" id="aa" value="1" <?= !empty($project['all_access']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aa">All-access (everyone in the org sees it)</label>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save settings</button>
            </div>
        </form>
    </div>

<?php endif; ?>

<?php /* New task modal — pre-fills the project */ ?>
<div class="modal fade" id="newTaskModal" tabindex="-1" aria-labelledby="newTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/admin/create.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="newTaskModalLabel">New task in <?= htmlspecialchars($project['name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="project" value="<?= htmlspecialchars($project['name']) ?>">
                    <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input class="form-control form-control-lg" name="title" required autofocus placeholder="What needs to happen?" data-mention="1">
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statuses as $s): ?>
                                    <option value="<?= htmlspecialchars($s['slug']) ?>" <?= ((int)$s['is_default'] === 1) ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority">
                                <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                                    <option value="<?= $p ?>" <?= $p === 'normal' ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Assign to</label>
                            <select class="form-select" name="assigned_to_user_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">To-do list</label>
                            <?php if (empty($lists)): ?>
                                <div class="alert alert-warning py-2 mb-0 small">No lists in this project yet. Open the <strong>Lists</strong> tab (or ask a project admin) to add one.</div>
                            <?php else: ?>
                                <select class="form-select" name="list_id" required>
                                    <?php foreach ($lists as $tl): ?>
                                        <option value="<?= (int)$tl['id'] ?>"><?= htmlspecialchars((string)$tl['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Due (UTC)</label>
                            <input class="form-control" type="datetime-local" name="due_at">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Body</label>
                            <textarea class="form-control" name="body" rows="3" data-mention="1" placeholder="Optional notes… Tag teammates with @username."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit" <?= empty($lists) ? 'disabled' : '' ?>><i class="bi bi-plus-lg me-1"></i>Create task</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
