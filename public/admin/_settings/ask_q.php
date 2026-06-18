<?php
/**
 * Settings tab: Ask Q / q-bridge rate limits (admin only).
 */
require_once __DIR__ . '/../../q-bridge/includes/rate_limit_config.php';

$askq_error = null;
$askq_success = null;
$cfg = q_bridge_get_rate_limit_config();
$userEp = $cfg['user_endpoints'] ?? [];
$rlDefaults = q_bridge_rate_limit_defaults();
$rlUserEp = $rlDefaults['user_endpoints'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['settings_action'] ?? '') === 'save_ask_q_limits') {
    requireCsrfToken();
    if (!$isAdmin) {
        $askq_error = 'Admin role required.';
    } else {
        $result = q_bridge_save_rate_limit_config([
            'messages' => $_POST['rl_messages'] ?? null,
            'responses' => $_POST['rl_responses'] ?? null,
            'history' => $_POST['rl_history'] ?? null,
            'user_session' => $_POST['rl_user_session'] ?? null,
            'user_max_requests' => $_POST['rl_user_max'] ?? null,
            'ip_max_requests' => $_POST['rl_ip_max'] ?? null,
        ], (int)$currentUser['id']);
        if ($result['success']) {
            $askq_success = 'Ask Q rate limits saved. Changes apply immediately.';
            q_bridge_clear_rate_limit_config_cache();
            $cfg = q_bridge_get_rate_limit_config();
            $userEp = $cfg['user_endpoints'] ?? [];
        } else {
            $askq_error = $result['error'] ?? 'Could not save rate limits.';
        }
    }
}
?>

<div class="surface surface-pad">
    <div class="section-title"><i class="bi bi-chat-dots"></i> Ask Q rate limits</div>
    <p class="fine-print mb-3">
        Per-user caps for the Ask Q webchat widget (1-hour rolling window). Defaults are tuned for
        all-day internal use (open chat panel, admin navigation). Broca <code>inbox</code> /
        <code>outbox</code> use separate per-server IP caps in code.
    </p>

    <?php if ($askq_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($askq_error) ?></div>
    <?php endif; ?>
    <?php if ($askq_success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($askq_success) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/settings.php?tab=ask-q" style="max-width: 520px;">
        <?= csrfInputField() ?>
        <input type="hidden" name="settings_action" value="save_ask_q_limits">

        <div class="mb-3">
            <label class="form-label" for="rl_messages">Messages sent per user / hour</label>
            <input class="form-control" type="number" min="1" max="100000" name="rl_messages" id="rl_messages"
                value="<?= (int)($userEp['/api/messages'] ?? $rlUserEp['/api/messages'] ?? 300) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="rl_responses">Response polls per user / hour</label>
            <input class="form-control" type="number" min="1" max="100000" name="rl_responses" id="rl_responses"
                value="<?= (int)($userEp['/api/responses'] ?? $rlUserEp['/api/responses'] ?? 7200) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="rl_history">History fetches per user / hour</label>
            <input class="form-control" type="number" min="1" max="100000" name="rl_history" id="rl_history"
                value="<?= (int)($userEp['/api/history'] ?? $rlUserEp['/api/history'] ?? 600) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="rl_user_session">Session lookups per user / hour</label>
            <input class="form-control" type="number" min="1" max="100000" name="rl_user_session" id="rl_user_session"
                value="<?= (int)($userEp['/api/user_session'] ?? $rlUserEp['/api/user_session'] ?? 3000) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="rl_user_max">Overall cap per user / hour (all widget routes)</label>
            <input class="form-control" type="number" min="1" max="100000" name="rl_user_max" id="rl_user_max"
                value="<?= (int)($cfg['user_max_requests'] ?? $rlDefaults['user_max_requests'] ?? 20000) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="rl_ip_max">Overall cap per IP / hour (poll + legacy)</label>
            <input class="form-control" type="number" min="1" max="100000" name="rl_ip_max" id="rl_ip_max"
                value="<?= (int)($cfg['ip_max_requests'] ?? $rlDefaults['ip_max_requests'] ?? 25000) ?>" required>
        </div>

        <button type="submit" class="btn btn-primary">Save Ask Q limits</button>
    </form>
</div>
