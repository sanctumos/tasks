<?php
/**
 * Fullscreen Q companion shell (Track B).
 * Same-origin Tasks session → q-bridge pull wire.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../q-bridge/includes/connection_config.php';

requireLogin();

if (!q_bridge_is_ui_enabled()) {
    http_response_code(403);
    echo 'Ask Q is disabled.';
    exit;
}

$conn = q_bridge_get_connection_config();
$qTitle = trim((string)($conn['agent_label'] ?? '')) !== ''
    ? (string)$conn['agent_label']
    : 'Q. Vernal';

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'");
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= htmlspecialchars($qTitle, ENT_QUOTES, 'UTF-8') ?> · Companion</title>
  <link rel="stylesheet" href="/q-bridge/companion/assets/css/companion.css?v=1">
</head>
<body>
  <div id="companion-app"></div>
  <script src="/admin/companion-boot.php"></script>
  <script type="module" src="/q-bridge/companion/assets/js/tasks-bootstrap.js?v=1"></script>
</body>
</html>
