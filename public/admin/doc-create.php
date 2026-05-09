<?php
/**
 * New document form. Posts to /admin/doc-update.php which handles both
 * create and update. Pre-selects ?project_id=N when present.
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

$accessibleProjects = listDirectoryProjectsForUser($currentUser, 500);
if (empty($accessibleProjects)) {
    $_SESSION['admin_flash_error'] = 'You need access to at least one directory project before you can create a document.';
    header('Location: /admin/docs.php');
    exit;
}

$preselectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$preselectValid = false;
if ($preselectId > 0) {
    foreach ($accessibleProjects as $p) {
        if ((int)$p['id'] === $preselectId) {
            $preselectValid = true;
            break;
        }
    }
}

$pageTitle = 'New document';
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
    ['href' => '/admin/docs.php', 'label' => 'Docs'],
    ['label' => 'New'],
];
require __DIR__ . '/_layout_top.php';
?>

<div class="page-header">
    <div class="page-header__title">
        <h1><i class="bi bi-journal-plus me-2"></i>New document</h1>
        <div class="subtitle">Write a spec, decision record, runbook, or onboarding note. Each doc has its own discussion thread.</div>
    </div>
</div>

<div class="surface surface-pad" style="max-width: 920px;">
    <form method="post" action="/admin/doc-update.php">
        <?= csrfInputField() ?>
        <div class="row g-3">
            <div class="col-12 col-md-8">
                <label class="form-label">Title</label>
                <input class="form-control form-control-lg" name="title" required maxlength="200" autofocus placeholder="What is this document about?">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Project</label>
                <select class="form-select" name="project_id" required>
                    <?php if (!$preselectValid): ?>
                        <option value="" disabled selected>Select a project…</option>
                    <?php endif; ?>
                    <?php foreach ($accessibleProjects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= ($preselectValid && $preselectId === (int)$p['id']) ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Directory <span class="fine-print">(optional)</span></label>
                <input class="form-control" name="directory_path" maxlength="500" placeholder="examples: onboarding / vendor/acme / research/2026-q2">
                <div class="fine-print mt-1">Use slash-separated folders to organize large doc sets in the library.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Body <span class="fine-print">(Markdown)</span></label>
                <textarea class="form-control" name="body" rows="14" placeholder="# Overview&#10;&#10;Markdown supported: **bold**, *italic*, `code`, lists, links, code blocks, tables, &gt; blockquotes."></textarea>
                <div class="fine-print mt-1"><i class="bi bi-markdown me-1"></i>Renders on save. URLs auto-link.</div>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="public_link_enabled" id="doc_create_public" value="1">
                    <label class="form-check-label" for="doc_create_public">Enable public viewing link immediately (anonymous read-only HTML; no discussion thread)</label>
                </div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Create document</button>
            <a class="btn btn-outline-secondary" href="/admin/docs.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
