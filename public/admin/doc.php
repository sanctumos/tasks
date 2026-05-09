<?php
/**
 * Single document view. Renders the markdown body, a Discussion thread
 * with its own comment composer, and inline edit for title + body.
 * Modeled on /admin/view.php for tasks so the look feels native.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /admin/docs.php');
    exit;
}

$doc = getDocumentById($id);
if (!$doc) {
    header('Location: /admin/docs.php');
    exit;
}

$currentUser = getCurrentUser();
if (!$currentUser || !userCanAccessDocument($currentUser, $doc)) {
    header('Location: /admin/docs.php');
    exit;
}

$canManage = userCanManageDocument($currentUser, $doc);
$accessibleProjects = listDirectoryProjectsForUser($currentUser, 500);
$documentPublicShareUrl = null;
if (!empty($doc['public_link_enabled']) && isset($doc['public_link_token']) && is_string($doc['public_link_token'])) {
    $documentPublicShareUrl = tasksDocumentShareAbsoluteUrl($doc['public_link_token']);
}

$comments = $doc['comments'] ?? [];
$commentCount = count($comments);
$currentUserId = (int)$currentUser['id'];

$flashSuccess = $_SESSION['admin_flash_success'] ?? null;
$flashError = $_SESSION['admin_flash_error'] ?? null;
unset($_SESSION['admin_flash_success'], $_SESSION['admin_flash_error']);

$pageTitle = (string)$doc['title'];
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
    ['href' => '/admin/docs.php', 'label' => 'Docs'],
];
if (!empty($doc['project_name'])) {
    $adminBreadcrumbs[] = [
        'href' => '/admin/docs.php?project_id=' . (int)$doc['project_id'],
        'label' => (string)$doc['project_name'],
    ];
}
$docDir = normalizeDocumentDirectoryPath((string)($doc['directory_path'] ?? ''));
if ($docDir !== '') {
    $adminBreadcrumbs[] = [
        'href' => '/admin/docs.php?project_id=' . (int)$doc['project_id'] . '&dir=' . rawurlencode($docDir),
        'label' => $docDir,
    ];
}
$crumbTitle = strlen($doc['title']) > 56 ? (substr($doc['title'], 0, 53) . '…') : $doc['title'];
$adminBreadcrumbs[] = ['label' => $crumbTitle];

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
            <h1 class="task-title js-inline-edit-target" data-edit-target="doc-title-edit"><i class="bi bi-journal-text me-2 text-muted"></i><?= htmlspecialchars($doc['title']) ?></h1>
            <?php if ($canManage): ?>
            <button type="button" class="btn btn-sm btn-link task-title-rename js-inline-edit-toggle" data-edit-target="doc-title-edit" title="Rename document">
                <i class="bi bi-pencil"></i><span class="visually-hidden">Rename</span>
            </button>
            <?php endif; ?>
        </div>
        <?php if ($canManage): ?>
        <form id="doc-title-edit" method="post" action="/admin/doc-update.php" class="js-inline-edit-form d-none">
            <?= csrfInputField() ?>
            <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
            <div class="d-flex gap-2 align-items-center">
                <input class="form-control form-control-sm flex-grow-1" name="title" value="<?= htmlspecialchars($doc['title']) ?>" required maxlength="200">
                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check-lg"></i></button>
                <button class="btn btn-outline-secondary btn-sm js-inline-edit-cancel" type="button" data-edit-target="doc-title-edit"><i class="bi bi-x-lg"></i></button>
            </div>
        </form>
        <?php endif; ?>

        <div class="task-header__chips">
            <span class="meta-chip" title="Document ID">#<?= (int)$doc['id'] ?></span>
            <?php
            $projectDocsTabQuery = ['id' => (int)$doc['project_id'], 'tab' => 'docs'];
            if ($docDir !== '') {
                $projectDocsTabQuery['dir'] = $docDir;
            }
            $projectDocsTabHref = '/admin/project.php?' . http_build_query($projectDocsTabQuery);
            ?>
            <a class="meta-chip meta-chip--link" href="<?= htmlspecialchars($projectDocsTabHref) ?>" title="Open project (Docs tab)">
                <i class="bi bi-kanban"></i><?= htmlspecialchars((string)$doc['project_name']) ?>
            </a>
            <span class="meta-chip" title="<?= htmlspecialchars(st_absolute_time_attr($doc['created_at'] ?? null)) ?>">
                <i class="bi bi-clock-history"></i>created <?= htmlspecialchars(st_absolute_time($doc['created_at'] ?? null)) ?>
                <span class="meta-chip__sub">(<?= st_relative_time($doc['created_at'] ?? null) ?>)</span>
                by <?= htmlspecialchars((string)$doc['created_by_username']) ?>
            </span>
            <span class="meta-chip" title="<?= htmlspecialchars(st_absolute_time_attr($doc['updated_at'] ?? null)) ?>">
                <i class="bi bi-arrow-clockwise"></i>updated <?= htmlspecialchars(st_absolute_time($doc['updated_at'] ?? null)) ?>
                <span class="meta-chip__sub">(<?= st_relative_time($doc['updated_at'] ?? null) ?>)</span>
            </span>
        </div>
    </div>

    <div class="page-header__actions task-header__actions d-flex align-items-center gap-2">
        <?= st_doc_help('documents', 'Project documents') ?>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-three-dots"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php if ($canManage): ?>
                    <li><a class="dropdown-item js-inline-edit-toggle" href="#doc-body-edit" data-edit-target="doc-body-edit"><i class="bi bi-pencil me-2"></i>Edit body</a></li>
                <?php endif; ?>
                <li><a class="dropdown-item js-copy-link" href="#" data-copy-url="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '')) ?>"><i class="bi bi-link-45deg me-2"></i>Copy link</a></li>
                <?php if ($canManage): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="/admin/doc-delete.php" onsubmit="return confirm('Delete this document and all its comments? This cannot be undone.');" class="m-0">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                            <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Delete document</button>
                        </form>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-9">

        <div class="surface surface-pad mb-3" id="doc-body-card">
            <div class="section-title-row">
                <div class="section-title"><i class="bi bi-file-earmark-text"></i> Document</div>
                <?php if ($canManage): ?>
                    <button type="button" class="btn btn-sm btn-link js-inline-edit-toggle" data-edit-target="doc-body-edit">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                <?php endif; ?>
            </div>
            <div class="description-display js-inline-edit-target" data-edit-target="doc-body-edit">
                <?php if (!empty($doc['body'])): ?>
                    <div class="description-body markdown-body doc-body"><?= st_markdown((string)$doc['body']) ?></div>
                <?php else: ?>
                    <p class="text-muted small mb-0">This document is empty. <?php if ($canManage): ?><a class="js-inline-edit-toggle" href="#" data-edit-target="doc-body-edit">Add a body</a>.<?php endif; ?></p>
                <?php endif; ?>
            </div>
            <?php if ($canManage): ?>
            <form id="doc-body-edit" method="post" action="/admin/doc-update.php" class="js-inline-edit-form d-none mt-2">
                <?= csrfInputField() ?>
                <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                <div class="mb-2">
                    <textarea class="form-control" name="body" rows="20" data-mention="1" placeholder="# Heading&#10;&#10;Markdown body… Tag teammates with @username."><?= htmlspecialchars((string)($doc['body'] ?? '')) ?></textarea>
                    <div class="fine-print mt-1"><i class="bi bi-markdown me-1"></i>Markdown rendered on save.</div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <button class="btn btn-outline-secondary btn-sm js-inline-edit-cancel" type="button" data-edit-target="doc-body-edit">Cancel</button>
                </div>
            </form>
            <?php endif; ?>
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
                                    <span class="comment-time" title="<?= htmlspecialchars(st_absolute_time_attr($createdIso)) ?>">
                                        <span class="comment-time__abs"><?= htmlspecialchars(st_absolute_time($createdIso)) ?></span>
                                        <span class="comment-time__rel">· <?= st_relative_time($createdIso) ?></span>
                                    </span>
                                </div>
                                <div class="comment-body markdown-body"><?= st_markdown($body) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <div id="discussion-end"></div>
            <?php endif; ?>

            <form id="discussion-composer" class="comment-composer mt-3" method="post" action="/admin/doc-comment.php">
                <?= csrfInputField() ?>
                <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                <div class="comment-composer__row">
                    <div class="comment-avatar-col">
                        <?= st_avatar_html($currentUser['username'] ?? '?') ?>
                    </div>
                    <div class="comment-composer__main">
                        <textarea class="form-control" name="comment" rows="3" maxlength="2000" data-mention="1" placeholder="Markdown supported: **bold**, *italic*, `code`, lists, links… Tag teammates with @username." required></textarea>
                        <div class="comment-composer__actions">
                            <span class="fine-print"><i class="bi bi-markdown me-1"></i>Markdown · max 2000 chars</span>
                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-send me-1"></i>Post comment</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

    </div>

    <div class="col-12 col-lg-3">
        <aside class="metadata-rail">
            <div class="metadata-rail__row metadata-rail__row--block">
                <label>Project</label>
                <?php if ($canManage): ?>
                <form method="post" action="/admin/doc-update.php" class="m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                    <select class="form-select form-select-sm" name="project_id" onchange="this.form.submit()">
                        <?php foreach ($accessibleProjects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$doc['project_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php else: ?>
                <div class="metadata-rail__value">
                    <a href="/admin/project.php?id=<?= (int)$doc['project_id'] ?>"><?= htmlspecialchars((string)$doc['project_name']) ?></a>
                </div>
                <?php endif; ?>
            </div>
            <div class="metadata-rail__row metadata-rail__row--block">
                <label>Directory</label>
                <?php if ($canManage): ?>
                <form method="post" action="/admin/doc-update.php" class="m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                    <input class="form-control form-control-sm" name="directory_path" maxlength="500" value="<?= htmlspecialchars((string)($doc['directory_path'] ?? '')) ?>" placeholder="optional/path">
                    <div class="mt-2 d-grid">
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Save directory</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="metadata-rail__value">
                    <span class="text-muted"><?= htmlspecialchars($docDir !== '' ? $docDir : '/') ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="metadata-rail__row metadata-rail__row--block">
                <label>Status</label>
                <div class="metadata-rail__value">
                    <span class="status-pill status-pill--<?= ($doc['status'] === 'archived') ? 'todo' : (($doc['status'] === 'trashed') ? 'blocked' : 'doing') ?>"><?= htmlspecialchars((string)$doc['status']) ?></span>
                </div>
            </div>
            <div class="metadata-rail__row metadata-rail__row--block">
                <label>Stats</label>
                <div class="metadata-rail__value">
                    <span class="icon-stats">
                        <span title="Comments"><i class="bi bi-chat-text"></i> <?= (int)$commentCount ?></span>
                        <span title="Body length"><i class="bi bi-file-text"></i> <?= mb_strlen((string)($doc['body'] ?? '')) ?> chars</span>
                    </span>
                </div>
            </div>
            <div class="metadata-rail__row metadata-rail__row--block">
                <label>Public sharing</label>
                <?php if ($canManage): ?>
                <form method="post" action="/admin/doc-update.php" class="m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                    <input type="hidden" name="doc_public_share_save" value="1">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="public_link_enabled" id="public_link_enabled"
                               value="1" <?= !empty($doc['public_link_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="public_link_enabled">Anyone with the link can read (no login)</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="rotate_public_link" id="rotate_public_link" value="1">
                        <label class="fine-print mb-0" for="rotate_public_link">Rotate the secret URL when saving (invalidates prior links).</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">Save public visibility</button>
                </form>
                <?php if ($documentPublicShareUrl): ?>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 js-copy-link"
                                data-copy-url="<?= htmlspecialchars($documentPublicShareUrl) ?>">
                            <i class="bi bi-link-45deg"></i> Copy public URL
                        </button>
                    </div>
                <?php endif; ?>
                <div class="fine-print mt-2 mb-0">Comments stay private. Embedded images hosted as task attachments (<code>/api/get-asset.php</code>) may still require a signed-in viewer.</div>
                <?php else: ?>
                    <div class="metadata-rail__value fine-print mb-0">
                        <?php if (!empty($doc['public_link_enabled'])): ?>
                            <span class="text-success"><i class="bi bi-globe"></i> Public viewing is enabled. Ask an editor for the audience link.</span>
                        <?php else: ?>
                            <span class="text-muted"><i class="bi bi-lock"></i> Restricted — sign in required.</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="metadata-rail__row metadata-rail__row--block">
                <label>Permalink</label>
                <div class="metadata-rail__value">
                    <code class="fine-print" style="word-break: break-all;">/admin/doc.php?id=<?= (int)$doc['id'] ?></code>
                    <div class="fine-print mt-1">Internal link — paste into task discussion or docs.</div>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
