<?php
/**
 * Ask Q Vernal — floating webchat bubble (logged-in admin only).
 */
if (!isLoggedIn() || !defined('TASKS_Q_BRIDGE_ENABLED') || !TASKS_Q_BRIDGE_ENABLED) {
    return;
}
require_once dirname(__DIR__) . '/q-bridge/includes/page_context.php';

$qTitle = 'Q. Vernal';
$qColor = '#4a5568';
$qChatterUsername = trim((string)($_SESSION['username'] ?? ''));
$askQPageContext = [];
$layoutUser = getCurrentUser();
if ($layoutUser) {
    $askQPageContext = q_bridge_enrich_page_context(
        q_bridge_detect_admin_page_context(),
        $layoutUser
    );
}
?>
<link rel="stylesheet" href="/q-bridge/widget/assets/css/widget.css?v=5">
<script src="/q-bridge/widget/assets/js/markdown-lite.js?v=1"></script>
<script src="/q-bridge/widget/assets/js/chat-widget.js?v=13"></script>
<script>
window.TASKS_ASK_Q_PAGE = <?= json_encode($askQPageContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('DOMContentLoaded', function () {
    if (typeof SanctumChat === 'undefined') {
        return;
    }
    try {
        SanctumChat.init({
            apiBase: '/q-bridge/api/v1/',
            useSessionAuth: true,
            apiKey: 'session',
            position: 'bottom-right',
            theme: 'light',
            title: <?= json_encode($qTitle, JSON_UNESCAPED_UNICODE) ?>,
            chatterUsername: <?= json_encode($qChatterUsername, JSON_UNESCAPED_UNICODE) ?>,
            primaryColor: <?= json_encode($qColor) ?>,
            greeting: 'Hi — I\'m Q. Ask me anything about your tasks.',
            persistSession: true,
            historyLimit: 6,
            autoOpen: false,
            pageContext: window.TASKS_ASK_Q_PAGE || null
        });
    } catch (e) {
        console.warn('Ask Q widget failed to init', e);
    }
});
</script>
