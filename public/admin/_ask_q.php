<?php
/**
 * Ask Q Vernal — floating webchat bubble (logged-in admin only).
 */
if (!isLoggedIn() || !defined('TASKS_Q_BRIDGE_ENABLED') || !TASKS_Q_BRIDGE_ENABLED) {
    return;
}
$qTitle = 'Q. Vernal';
$qColor = '#4a5568';
?>
<link rel="stylesheet" href="/q-bridge/widget/assets/css/widget.css?v=1">
<script src="/q-bridge/widget/assets/js/chat-widget.js?v=2"></script>
<script>
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
            primaryColor: <?= json_encode($qColor) ?>,
            greeting: 'Hi — I\'m Q. Ask me anything about your tasks.',
            persistSession: true,
            autoOpen: false
        });
    } catch (e) {
        console.warn('Ask Q widget failed to init', e);
    }
});
</script>
