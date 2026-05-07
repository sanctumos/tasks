<?php
/**
 * Renders public/docs/user-guide.md as HTML for logged-in users (in-app help).
 * Kept under public/ so multihost deploys that mirror WEB_ROOT include the file.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();

$guidePath = dirname(__DIR__) . '/docs/user-guide.md';
if (!is_readable($guidePath)) {
    http_response_code(500);
    die('Documentation file is missing. Expected public/docs/user-guide.md');
}

$raw = (string)file_get_contents($guidePath);
require_once __DIR__ . '/../includes/lib/Parsedown.php';
$pd = new Parsedown();
$pd->setSafeMode(true);
$pd->setMarkupEscaped(true);
$pd->setBreaksEnabled(true);
$pd->setUrlsLinked(true);

$htmlBody = $pd->text($raw);
$htmlBody = st_doc_inject_heading_ids($htmlBody);

$pageTitle = 'Help';
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
    ['label' => 'Help'],
];
require __DIR__ . '/_layout_top.php';
?>

<div class="page-header page-header--docs">
    <div class="page-header__title">
        <h1><i class="bi bi-journal-text me-2 text-muted"></i>Help</h1>
        <div class="subtitle">Sanctum Tasks — how the admin UI fits together.</div>
    </div>
    <div class="page-header__actions">
        <a class="btn btn-sm btn-outline-secondary" href="https://github.com/sanctumos/tasks/tree/main/public/docs" target="_blank" rel="noopener"><i class="bi bi-folder2-open me-1"></i>User guide (repo)</a>
    </div>
</div>

<div class="surface surface-pad documentation-content markdown-body">
    <?= $htmlBody ?>
</div>

<p class="text-muted small mt-3 px-1">Tip: use the <strong>?</strong> icons in the admin UI to jump to the right section here.</p>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
