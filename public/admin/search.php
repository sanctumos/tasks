<?php
/**
 * Full-text search results (shareable ?q=). Per-group pagination: 25/page.
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

$PAGE_SIZE = 25;
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$pageTitle = $q !== '' ? ('Search: ' . $q) : 'Search';

$offsets = [
    'tasks' => max(0, (int)($_GET['tasks_offset'] ?? 0)),
    'documents' => max(0, (int)($_GET['documents_offset'] ?? 0)),
    'users' => max(0, (int)($_GET['users_offset'] ?? 0)),
    'projects' => max(0, (int)($_GET['projects_offset'] ?? 0)),
];

$payload = null;
$error = null;
if ($q !== '' && strlen($q) < 2) {
    $error = 'Type at least 2 characters to search.';
} elseif ($q !== '') {
    $payload = searchOmniboxForUser($currentUser, $q, $PAGE_SIZE, $offsets);
    if (isset($payload['success']) && $payload['success'] === false) {
        $error = (string)($payload['error'] ?? 'Search failed');
        $payload = null;
    }
}

$groupLabels = [
    'tasks' => 'Tasks',
    'documents' => 'Documents',
    'users' => 'People',
    'projects' => 'Boards',
];

if (!function_exists('st_search_highlight')) {
    function st_search_highlight(string $title, string $q): string
    {
        $safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $needle = trim($q);
        if (strlen($needle) < 2) {
            return $safe;
        }
        $pos = mb_stripos($title, $needle);
        if ($pos === false) {
            return $safe;
        }
        $len = mb_strlen($needle);
        $before = htmlspecialchars(mb_substr($title, 0, $pos), ENT_QUOTES, 'UTF-8');
        $mid = htmlspecialchars(mb_substr($title, $pos, $len), ENT_QUOTES, 'UTF-8');
        $after = htmlspecialchars(mb_substr($title, $pos + $len), ENT_QUOTES, 'UTF-8');
        return $before . '<mark class="st-hl">' . $mid . '</mark>' . $after;
    }
}

$buildSearchUrl = static function (string $q, array $offsets, string $bumpKey, int $bumpValue) use ($PAGE_SIZE): string {
    $params = ['q' => $q];
    foreach (['tasks', 'documents', 'users', 'projects'] as $k) {
        $off = ($k === $bumpKey) ? $bumpValue : (int)($offsets[$k] ?? 0);
        if ($off > 0) {
            $params[$k . '_offset'] = $off;
        }
    }
    return '/admin/search.php?' . http_build_query($params);
};

require __DIR__ . '/_layout_top.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">Search</h1>
        <p class="text-muted mb-0 small">Tasks, documents, people, and boards you can access.</p>
    </div>
</div>

<form method="get" action="/admin/search.php" class="mb-4" role="search">
    <div class="input-group" style="max-width: 40rem;">
        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
        <input class="form-control" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Search everything…" aria-label="Search query" autofocus>
        <button class="btn btn-primary" type="submit">Search</button>
    </div>
</form>

<?php if ($error !== null): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php elseif ($q === ''): ?>
    <div class="st-search-empty text-muted">Enter a query to see results across the workspace.</div>
<?php elseif ($payload === null): ?>
    <div class="st-search-empty text-muted">No results.</div>
<?php else: ?>
    <?php
    $any = false;
    foreach ($groupLabels as $key => $label):
        $items = $payload['groups'][$key] ?? [];
        $count = (int)($payload['counts'][$key] ?? 0);
        $off = (int)($offsets[$key] ?? 0);
        if ($count === 0 && $items === []) {
            continue;
        }
        $any = true;
        ?>
        <section class="st-search-group" data-group="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
            <h2 class="st-search-group__title"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                <span class="text-muted fw-normal">(<?= $count ?>)</span>
            </h2>
            <?php if ($items === []): ?>
                <div class="text-muted small mb-3">No more <?= htmlspecialchars(strtolower($label), ENT_QUOTES, 'UTF-8') ?> on this page.</div>
            <?php else: ?>
                <?php foreach ($items as $item):
                    $title = (string)($item['title'] ?? $item['name'] ?? '');
                    $url = (string)($item['url'] ?? '#');
                    $subParts = [];
                    if (!empty($item['project_name'])) {
                        $subParts[] = (string)$item['project_name'];
                    }
                    if (!empty($item['directory_path'])) {
                        $subParts[] = (string)$item['directory_path'] . '/';
                    }
                    if (!empty($item['status'])) {
                        $subParts[] = (string)$item['status'];
                    }
                    if (!empty($item['role'])) {
                        $subParts[] = (string)$item['role'];
                    }
                    if (($item['entity'] ?? '') === 'task' && !empty($item['id'])) {
                        $subParts[] = '#' . (int)$item['id'];
                    }
                    $sub = implode(' · ', $subParts);
                    ?>
                    <a class="st-search-row" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
                        <span class="st-search-results flex-grow-1 min-w-0">
                            <span class="fw-semibold d-block"><?= st_search_highlight($title, $q) ?></span>
                            <?php if ($sub !== ''): ?>
                                <span class="small text-muted"><?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($count > $PAGE_SIZE): ?>
                <div class="d-flex gap-2 flex-wrap mt-2 mb-3">
                    <?php if ($off > 0): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($buildSearchUrl($q, $offsets, $key, max(0, $off - $PAGE_SIZE)), ENT_QUOTES, 'UTF-8') ?>">Previous <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                    <?php if ($off + $PAGE_SIZE < $count): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($buildSearchUrl($q, $offsets, $key, $off + $PAGE_SIZE), ENT_QUOTES, 'UTF-8') ?>">More <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <?php if (!$any): ?>
        <div class="st-search-empty text-muted">No results for “<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>”.</div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
