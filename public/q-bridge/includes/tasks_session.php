<?php
/**
 * Bind Q bridge widget requests to logged-in Tasks admin session.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

/**
 * @return int Tasks user id from PHP session
 */
function require_tasks_logged_in_user_id(): int {
    if (!isLoggedIn()) {
        send_unauthorized_response('Tasks login required');
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        send_unauthorized_response('Tasks login required');
    }
    return $uid;
}
