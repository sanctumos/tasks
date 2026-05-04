<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit();
}

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate') {
        $_SESSION['pending_mfa_secret'] = generateTotpSecret();
        $message = 'Generated a new MFA secret. Confirm with a code from your authenticator app.';
        $messageType = 'success';
    } elseif ($action === 'enable') {
        $secret = (string)($_SESSION['pending_mfa_secret'] ?? '');
        $code = trim((string)($_POST['code'] ?? ''));
        if ($secret === '') {
            $message = 'Generate an MFA secret first.';
            $messageType = 'danger';
        } elseif (!verifyTotpCode($secret, $code)) {
            $message = 'Invalid MFA code. Ensure your authenticator time is correct.';
            $messageType = 'danger';
        } else {
            $res = enableUserMfa((int)$currentUser['id'], $secret);
            if ($res['success']) {
                unset($_SESSION['pending_mfa_secret']);
                $message = 'MFA enabled successfully.';
                $currentUser = getCurrentUser();
            } else {
                $message = $res['error'] ?? 'Failed to enable MFA';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'disable') {
        $password = (string)($_POST['current_password'] ?? '');
        $fullUser = getUserById((int)$currentUser['id'], true);
        if (!$fullUser || !password_verify($password, (string)$fullUser['password_hash'])) {
            $message = 'Current password is incorrect.';
            $messageType = 'danger';
        } else {
            $res = disableUserMfa((int)$currentUser['id']);
            if ($res['success']) {
                unset($_SESSION['pending_mfa_secret']);
                $message = 'MFA disabled.';
                $currentUser = getCurrentUser();
            } else {
                $message = $res['error'] ?? 'Failed to disable MFA';
                $messageType = 'danger';
            }
        }
    }
}

$pendingSecret = (string)($_SESSION['pending_mfa_secret'] ?? '');
$otpauthUri = '';
if ($pendingSecret !== '') {
    $issuer = rawurlencode('Sanctum Tasks');
    $label = rawurlencode('Sanctum Tasks:' . (string)$currentUser['username']);
    $otpauthUri = "otpauth://totp/{$label}?secret={$pendingSecret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
}
$mfaEnabled = (int)$currentUser['mfa_enabled'] === 1;

$pageTitle = 'MFA';
require __DIR__ . '/_layout_top.php';
?>

<?= st_back_link('/admin/', 'Tasks') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Multi-factor auth</h1>
        <div class="subtitle">
            <?php if ($mfaEnabled): ?>
                <span class="status-pill status-pill--done"><i class="bi bi-shield-check"></i> Enabled</span> for <code><?= htmlspecialchars($currentUser['username']) ?></code>
            <?php else: ?>
                <span class="status-pill status-pill--blocked"><i class="bi bi-shield-exclamation"></i> Disabled</span> for <code><?= htmlspecialchars($currentUser['username']) ?></code>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show"><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$mfaEnabled): ?>
    <div class="surface surface-pad mb-3">
        <div class="section-title"><i class="bi bi-shield-lock"></i> Enable MFA (TOTP)</div>
        <p class="text-muted small">Generate a secret, scan it into your authenticator app (Google Authenticator, 1Password, Authy…), then confirm with a 6-digit code.</p>

        <?php if ($pendingSecret === ''): ?>
            <form method="post">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="generate">
                <button class="btn btn-primary" type="submit"><i class="bi bi-key me-1"></i>Generate new secret</button>
            </form>
        <?php else: ?>
            <div class="alert alert-warning mb-3">
                <p class="mb-2"><strong>Add this secret to your authenticator app:</strong></p>
                <div class="font-monospace bg-white border rounded p-2 mb-2" style="word-break: break-all;"><?= htmlspecialchars($pendingSecret) ?></div>
                <p class="mb-0 small text-muted">OTPAuth URI: <code style="word-break: break-all;"><?= htmlspecialchars($otpauthUri) ?></code></p>
            </div>
            <form method="post" class="row g-2 align-items-end">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="enable">
                <div class="col-12 col-md-4">
                    <label class="form-label">6-digit code</label>
                    <input class="form-control" name="code" inputmode="numeric" pattern="[0-9]{6}" required>
                </div>
                <div class="col-12 col-md-3">
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check-lg me-1"></i>Enable MFA</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="surface surface-pad">
        <div class="section-title text-danger"><i class="bi bi-shield-exclamation"></i> Disable MFA</div>
        <p class="text-muted small">You'll need to re-enroll if you disable. Confirm your current password to proceed.</p>
        <form method="post" class="row g-2 align-items-end">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="disable">
            <div class="col-12 col-md-4">
                <label class="form-label">Current password</label>
                <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-x-octagon me-1"></i>Disable MFA</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
