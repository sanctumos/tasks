<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAdmin();
$currentUser = getCurrentUser();

$message = null;
$messageType = 'success';
$newApiKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    requireCsrfToken();
    $keyName = trim($_POST['key_name'] ?? 'Unnamed Key');
    if ($keyName === '') {
        $keyName = 'Unnamed Key';
    }
    $newApiKey = createApiKeyForUser((int)$currentUser['id'], $keyName, (int)$currentUser['id']);
    $message = 'API key created.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireCsrfToken();
    if (isset($_POST['id'])) {
        if (revokeApiKey((int)$_POST['id'])) {
            $message = 'API key revoked.';
        } else {
            $message = 'Key not found or already revoked.';
            $messageType = 'danger';
        }
    }
}

$apiKeys = getAllApiKeys();

$pageTitle = 'API keys';
require __DIR__ . '/_layout_top.php';
?>

<?= st_back_link('/admin/', 'Tasks') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1>API keys</h1>
        <div class="subtitle"><?= count($apiKeys) ?> issued</div>
    </div>
    <div class="page-header__actions">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#newKeyModal"><i class="bi bi-key me-1"></i>New key</button>
    </div>
</div>

<?php if ($newApiKey): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <strong><i class="bi bi-check-circle-fill me-1"></i>API key created.</strong>
        <div class="mt-2 small">Save this now — it won't be shown again.</div>
        <pre class="bg-dark text-light p-3 rounded font-monospace small mt-2 mb-0" style="white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($newApiKey) ?></pre>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php elseif ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show"><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="surface">
    <table class="task-table">
        <thead>
            <tr>
                <th>Key name</th>
                <th>User</th>
                <th>Preview</th>
                <th>Created</th>
                <th>Last used</th>
                <th style="text-align: right; width: 100px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($apiKeys as $key): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($key['key_name']) ?></strong></td>
                    <td class="small"><?= htmlspecialchars($key['user_username'] ?? '') ?></td>
                    <td class="font-monospace small text-muted"><?= htmlspecialchars($key['api_key_preview'] ?? '') ?>…</td>
                    <td class="small text-muted"><?= htmlspecialchars($key['created_at']) ?></td>
                    <td class="small text-muted"><?= $key['last_used'] ? htmlspecialchars($key['last_used']) : '<span class="text-muted">Never</span>' ?></td>
                    <td class="task-actions">
                        <form method="post" class="d-inline m-0" onsubmit="return confirm('Revoke this API key? This cannot be undone.');">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$key['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($apiKeys)): ?>
                <tr><td colspan="6" class="text-muted text-center py-4">No API keys yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php /* New key modal */ ?>
<div class="modal fade" id="newKeyModal" tabindex="-1" aria-labelledby="newKeyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title" id="newKeyModalLabel">New API key</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="create">
                    <label class="form-label">Key name</label>
                    <input type="text" class="form-control form-control-lg" name="key_name" placeholder="e.g. Otto agent" required autofocus>
                    <p class="fine-print mt-2 mb-0">The full key will only be shown once after creation. Treat it like a password.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Create key</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
