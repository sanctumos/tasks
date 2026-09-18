<?php
/**
 * Boot config for companion shell (no inline script / CSP-safe).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../q-bridge/includes/connection_config.php';
require_once __DIR__ . '/../q-bridge/includes/page_context.php';

requireAuth();

if (!q_bridge_is_ui_enabled()) {
    http_response_code(403);
    header('Content-Type: application/javascript; charset=utf-8');
    echo 'window.__COMPANION_BOOT__ = { error: "disabled" };';
    exit;
}

$conn = q_bridge_get_connection_config();
$qTitle = trim((string)($conn['agent_label'] ?? '')) !== ''
    ? (string)$conn['agent_label']
    : 'Q. Vernal';
$layoutUser = getCurrentUser();
$pageContext = $layoutUser
    ? q_bridge_enrich_page_context(q_bridge_detect_admin_page_context(), $layoutUser)
    : [];

$boot = [
    'apiBase' => '/q-bridge/api/v1/',
    'csrfToken' => getCsrfToken(),
    'theme' => 'dark',
    'greeting' => 'Hi — I\'m ' . $qTitle . '. This is the fullscreen companion shell.',
    'pageContext' => $pageContext,
];

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');
echo 'window.__COMPANION_BOOT__ = ' . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
