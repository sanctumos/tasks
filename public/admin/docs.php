<?php
/**
 * Documents top-level page: lists all docs the viewer can access across
 * their accessible directory projects, sorted by most recent activity.
 * Optional ?project_id=N and ?q= narrow the list.
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

$projectFilter = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$selectedProject = null;
if ($projectFilter > 0) {
    $selectedProject = getDirectoryProjectById($projectFilter);
    if (!$selectedProject || !userCanAccessDirectoryProject($currentUser, $selectedProject)) {
        $projectFilter = 0;
        $selectedProject = null;
    }
}

$docsQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$isDocsSearch = $docsQ !== '';

$accessibleProjects = listDirectoryProjectsForUser($currentUser, 500);
$documents = listDocumentsForUser(
    $currentUser,
    500,
    $projectFilter ?: null,
    $isDocsSearch ? $docsQ : null
);
$currentDir = normalizeDocumentDirectoryPath((string)($_GET['dir'] ?? ''));
$aggDocs = aggregateDocumentsForDirectoryView($documents, $currentDir);
$dirChildren = $aggDocs['dir_children'];
$documentsInDir = $aggDocs['documents_in_dir'];

$buildDocsUrl = static function (int $projectFilter, string $dirPath = '', string $q = ''): string {
    $params = [];
    if ($projectFilter > 0) {
        $params['project_id'] = $projectFilter;
    }
    $dirPath = normalizeDocumentDirectoryPath($dirPath);
    if ($dirPath !== '') {
        $params['dir'] = $dirPath;
    }
    if (trim($q) !== '') {
        $params['q'] = trim($q);
    }
    return '/admin/docs.php' . ($params ? ('?' . http_build_query($params)) : '');
};

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
    <div class="filter-bar__field" style="min-width: 14rem; flex: 1 1 14rem;">
        <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted" aria-hidden="true"></i></span>
            <input class="form-control border-start-0" type="search" name="q"
                   value="<?= htmlspecialchars($docsQ, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Search documents…" aria-label="Search documents">
        </div>
    </div>
    <div class="filter-bar__field">
        <select class="form-select" name="project_id">
            <option value="">All projects</option>
            <?php foreach ($accessibleProjects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-bar__actions">
        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel-fill me-1"></i>Search</button>
        <?php if ($projectFilter > 0 || $currentDir !== '' || $isDocsSearch): ?>
            <a class="btn btn-outline-secondary btn-sm" href="/admin/docs.php"><i class="bi bi-x-lg me-1"></i>Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($isDocsSearch): ?>
    <?php if (empty($documents)): ?>
        <div class="surface surface-pad text-center st-docs-search-empty">
            <p class="mb-2">No documents match “<?= htmlspecialchars($docsQ, ENT_QUOTES, 'UTF-8') ?>”.</p>
            <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($buildDocsUrl($projectFilter), ENT_QUOTES, 'UTF-8') ?>">Clear search</a>
        </div>
    <?php else: ?>
        <div class="surface surface-pad">
            <div class="text-muted small mb-3"><?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?> matching “<?= htmlspecialchars($docsQ, ENT_QUOTES, 'UTF-8') ?>”</div>
            <?php foreach ($documents as $d):
                $snip = documentSearchSnippet($d['body'] ?? null, $docsQ);
                $dirLabel = normalizeDocumentDirectoryPath((string)($d['directory_path'] ?? '')) ?: '/';
                ?>
                <a class="st-docresult" href="/admin/doc.php?id=<?= (int)$d['id'] ?>">
                    <i class="bi bi-file-text st-docresult__icon" aria-hidden="true"></i>
                    <div class="min-w-0">
                        <div class="st-docresult__title"><?= highlightSearchMatch((string)$d['title'], $docsQ) ?></div>
                        <?php if ($snip !== ''): ?>
                            <div class="st-docresult__snip"><?= $snip ?></div>
                        <?php endif; ?>
                        <div class="fine-print text-muted mt-1">
                            <?= htmlspecialchars((string)$d['project_name'], ENT_QUOTES, 'UTF-8') ?>
                            · <?= htmlspecialchars($dirLabel, ENT_QUOTES, 'UTF-8') ?>
                            · updated <?= htmlspecialchars(st_absolute_time($d['updated_at'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php elseif (empty($documents) && $currentDir === ''): ?>
    <div class="surface surface-pad text-center">
        <div class="mb-3" style="font-size: 2rem; color: var(--st-text-muted);"><i class="bi bi-journal-text"></i></div>
        <h2 class="h5 mb-1"><?= $selectedProject ? 'No docs in ' . htmlspecialchars($selectedProject['name']) . ' yet' : 'No docs yet' ?></h2>
        <p class="text-muted small mb-3">Write a spec, decision record, runbook, or onboarding note. Markdown supported. Each doc gets its own discussion.</p>
        <a class="btn btn-primary" href="/admin/doc-create.php<?= $projectFilter ? '?project_id=' . (int)$projectFilter : '' ?>">
            <i class="bi bi-plus-lg me-1"></i>Write the first doc
        </a>
    </div>
<?php else: ?>
    <?php
    $crumbParts = $currentDir === '' ? [] : explode('/', $currentDir);
    $runningPath = '';
    ?>
    <div class="surface surface-pad mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="text-muted small"><i class="bi bi-folder2-open me-1"></i>Directory</span>
            <a class="btn btn-sm <?= $currentDir === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= htmlspecialchars($buildDocsUrl($projectFilter, '')) ?>">/</a>
            <?php foreach ($crumbParts as $part): ?>
                <?php $runningPath = $runningPath === '' ? $part : ($runningPath . '/' . $part); ?>
                <span class="text-muted small">/</span>
                <a class="btn btn-sm <?= $runningPath === $currentDir ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= htmlspecialchars($buildDocsUrl($projectFilter, $runningPath)) ?>"><?= htmlspecialchars($part) ?></a>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($dirChildren)): ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($dirChildren as $name => $count): ?>
                    <?php $target = $currentDir === '' ? $name : ($currentDir . '/' . $name); ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($buildDocsUrl($projectFilter, $target)) ?>">
                        <i class="bi bi-folder me-1"></i><?= htmlspecialchars($name) ?>
                        <span class="text-muted">· <?= (int)$count ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php elseif ($currentDir !== ''): ?>
            <div class="text-muted small">No subdirectories here.</div>
        <?php endif; ?>
    </div>

    <?php if (empty($documentsInDir)): ?>
        <div class="surface surface-pad text-center text-muted">
            No documents in this directory.
        </div>
    <?php else: ?>
    <div class="surface docs-table-wrap">
        <table class="task-table docs-table">
            <thead>
                <tr>
                    <th class="docs-table__col-title">Title</th>
                    <th class="docs-table__col-directory">Directory</th>
                    <th class="docs-table__col-project">Project</th>
                    <th class="docs-table__col-author">Author</th>
                    <th class="docs-table__col-comments">Comments</th>
                    <th class="docs-table__col-updated">Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documentsInDir as $d): ?>
                    <tr>
                        <td class="task-title-cell docs-table__col-title">
                            <a href="/admin/doc.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars((string)$d['title']) ?></a>
                        </td>
                        <td class="docs-table__col-directory"><span class="text-muted small"><?= htmlspecialchars((string)(normalizeDocumentDirectoryPath((string)($d['directory_path'] ?? '')) ?: '/')) ?></span></td>
                        <td class="docs-table__col-project">
                            <a class="text-decoration-none" href="/admin/project.php?id=<?= (int)$d['project_id'] ?>">
                                <i class="bi bi-kanban me-1"></i><?= htmlspecialchars((string)$d['project_name']) ?>
                            </a>
                        </td>
                        <td class="docs-table__col-author"><?= htmlspecialchars((string)$d['created_by_username']) ?></td>
                        <td class="docs-table__col-comments"><i class="bi bi-chat-text text-muted me-1"></i><?= (int)$d['comment_count'] ?></td>
                        <td class="docs-table__col-updated">
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

<?php require __DIR__ . '/_layout_bottom.php'; ?>
