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

$error = null;
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match';
    } else {
        $result = changePassword((int)$currentUser['id'], $currentPassword, $newPassword);
        if ($result['success']) {
            $success = 'Password changed successfully';
            $_SESSION['must_change_password'] = 0;
        } else {
            $error = $result['error'] ?? 'Failed to change password';
        }
    }
}

$pageTitle = 'Change password';
require __DIR__ . '/_layout_top.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5">
        <?= st_back_link('/admin/', 'Tasks') ?>
        <div class="surface surface-pad">
            <h1 class="h4 mb-1">Change password</h1>
            <p class="fine-print mb-3">Set a new password for <code><?= htmlspecialchars($currentUser['username']) ?></code>.</p>

            <?php if (!empty($_SESSION['must_change_password'])): ?>
                <div class="alert alert-warning"><i class="bi bi-shield-exclamation me-1"></i>You must change your password before continuing.</div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="post" action="/admin/change-password.php">
                <?= csrfInputField() ?>
                <div class="mb-3">
                    <label class="form-label">Current password</label>
                    <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label">New password</label>
                    <input class="form-control" type="password" name="new_password" required autocomplete="new-password">
                    <div class="form-text">At least <?= (int)PASSWORD_MIN_LENGTH ?> characters, with uppercase, lowercase, and a number.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm new password</label>
                    <input class="form-control" type="password" name="confirm_password" required autocomplete="new-password">
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Change password</button>
                    <a class="btn btn-outline-secondary" href="/admin/">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
