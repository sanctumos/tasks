<?php
require_once __DIR__ . '/../includes/auth.php';

$returnAfterLogin = auth_resolve_intended_url(
    isset($_GET['return']) && is_string($_GET['return']) ? $_GET['return'] : null
);

if (isLoggedIn()) {
    auth_redirect_after_login($returnAfterLogin);
}

$error = null;
$lockoutSeconds = 0;
$mfaRequired = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $returnAfterLogin = auth_resolve_intended_url(
        isset($_POST['return']) && is_string($_POST['return']) ? $_POST['return'] : null
    );
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $mfaCode = $_POST['mfa_code'] ?? null;
    $result = login($username, $password, $mfaCode);
    if ($result['success']) {
        if (!empty($result['must_change_password'])) {
            if ($returnAfterLogin !== null) {
                auth_store_intended_url($returnAfterLogin);
            }
            header('Location: settings.php?tab=password');
            exit();
        }
        auth_redirect_after_login($returnAfterLogin);
    }
    $error = $result['error'] ?? 'Login failed';
    $lockoutSeconds = (int)($result['lockout_seconds'] ?? 0);
    $mfaRequired = !empty($result['mfa_required']);
}

$pageTitle = 'Sign in';
require __DIR__ . '/_layout_top.php';
?>

<div class="row justify-content-center" style="min-height: 70vh; align-items: center;">
    <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4">
        <div class="surface surface-pad">
            <div class="text-center mb-4">
                <div style="font-size: 2rem; color: var(--st-accent);"><i class="bi bi-stack"></i></div>
                <h1 class="h4 mb-1">Sign in</h1>
                <p class="fine-print mb-0">Sanctum Tasks admin</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($lockoutSeconds > 0): ?>
                <div class="alert alert-warning">Login locked for approximately <?= (int)$lockoutSeconds ?> seconds.</div>
            <?php endif; ?>

            <form method="post" action="/admin/login.php<?= $returnAfterLogin !== null ? '?return=' . rawurlencode($returnAfterLogin) : '' ?>">
                <?= csrfInputField() ?>
                <?php if ($returnAfterLogin !== null): ?>
                    <input type="hidden" name="return" value="<?= htmlspecialchars($returnAfterLogin, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input class="form-control" name="username" autocomplete="username" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input class="form-control" type="password" name="password" autocomplete="current-password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">MFA code <span class="fine-print">(if enabled)</span></label>
                    <input class="form-control" name="mfa_code" inputmode="numeric" pattern="[0-9]{6}" placeholder="123456">
                    <?php if ($mfaRequired): ?>
                        <div class="form-text text-danger">A valid 6-digit MFA code is required for this account.</div>
                    <?php endif; ?>
                </div>
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</button>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
