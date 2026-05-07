<?php
/**
 * Documents top-level page: lists all docs the viewer can access across
 * their accessible directory projects, sorted by most recent activity.
 * Optional ?project_id=N narrows the list. Doubles as the entry point
 * for creating a new doc.
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

$projectFilter = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$selectedProject = null;
if ($projectFilter > 0) {
    $selectedProject = getDirectoryProjectById($projectFilter);
    if (!$selectedProject || !userCanAccessDirectoryProject($currentUser, $selectedProject)) {
        $projectFilter = 0;
        $selectedProject = null;
    }
}

$accessibleProjects = listDirectoryProjectsForUser($currentUser, 500);
$documents = listDocumentsForUser($currentUser, 500, $projectFilter ?: null);

$flashSuccess = $_SESSION['admin_flash_success'] ?? null;
$flashError = $_SESSION['admin_flash_error'] ?? null;
unset($_SESSION['admin_flash_success'], $_SESSION['admin_flash_error']);

$pageTitle = 'Docs';
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
    ['label' => 'Docs'],
];
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

<div class="page-header">
    <div class="page-header__title">
        <h1><i class="bi bi-journals me-2"></i>Docs</h1>
        <div class="subtitle">Long-form markdown reference material with its own discussion thread, attached to a project.</div>
    </div>
    <div class="page-header__actions d-flex align-items-center flex-wrap gap-2">
        <?= st_doc_help('documents', 'Project documents vs task bodies') ?>
        <a href="/admin/doc-create.php<?= $projectFilter ? '?project_id=' . (int)$projectFilter : '' ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New doc
        </a>
    </div>
</div>

<form class="filter-bar" method="get" action="/admin/docs.php">
    <div class="filter-bar__field">
        <select class="form-select" name="project_id" onchange="this.form.submit()">
            <option value="">All projects</option>
            <?php foreach ($accessibleProjects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($projectFilter > 0): ?>
        <div class="filter-bar__actions">
            <a class="btn btn-outline-secondary btn-sm" href="/admin/docs.php"><i class="bi bi-x-lg me-1"></i>Clear filter</a>
        </div>
    <?php endif; ?>
</form>

<?php if (empty($documents)): ?>
    <div class="surface surface-pad text-center">
        <div class="mb-3" style="font-size: 2rem; color: var(--st-text-muted);"><i class="bi bi-journal-text"></i></div>
        <h2 class="h5 mb-1"><?= $selectedProject ? 'No docs in ' . htmlspecialchars($selectedProject['name']) . ' yet' : 'No docs yet' ?></h2>
        <p class="text-muted small mb-3">Write a spec, decision record, runbook, or onboarding note. Markdown supported. Each doc gets its own discussion.</p>
        <a class="btn btn-primary" href="/admin/doc-create.php<?= $projectFilter ? '?project_id=' . (int)$projectFilter : '' ?>">
            <i class="bi bi-plus-lg me-1"></i>Write the first doc
        </a>
    </div>
<?php else: ?>
    <div class="surface">
        <table class="task-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Project</th>
                    <th>Author</th>
                    <th>Comments</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td class="task-title-cell">
                            <a href="/admin/doc.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars((string)$d['title']) ?></a>
                        </td>
                        <td>
                            <a class="text-decoration-none" href="/admin/project.php?id=<?= (int)$d['project_id'] ?>">
                                <i class="bi bi-kanban me-1"></i><?= htmlspecialchars((string)$d['project_name']) ?>
                            </a>
                        </td>
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

<?php require __DIR__ . '/_layout_bottom.php'; ?>
