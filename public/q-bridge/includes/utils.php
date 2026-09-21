<?php
/**
 * Utility functions for Web Chat Bridge
 */

/**
 * Get the base URL for the current request
 * 
 * @return string Base URL (e.g., https://example.com)
 */
function get_base_url(): string {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $port = $_SERVER['SERVER_PORT'] ?? '';
    
    // Don't include port 80 for HTTP or 443 for HTTPS
    if (($protocol === 'http' && $port === '80') || ($protocol === 'https' && $port === '443')) {
        $port = '';
    }
    
    $port_str = $port ? ":$port" : '';
    
    return "{$protocol}://{$host}{$port_str}";
}

/**
 * Generate a unique 16-character hex UID for web chat users
 * 
 * @return string 16-character hex UID
 */
function generate_web_chat_uid(): string {
    // Generate a 16-character hex UID
    return bin2hex(random_bytes(8));
}

/**
 * Get or create a web chat user UID for a session
 * 
 * @param string $session_id The session ID
 * @param string|null $ip_address The IP address (optional)
 * @return array Array with 'uid' and 'is_new' keys
 */
function get_or_create_web_chat_user($session_id, $ip_address = null): array {
    $pdo = get_db_connection();
    
    // Check if session already has a UID
    $stmt = $pdo->prepare("SELECT uid FROM web_chat_sessions WHERE id = ?");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch();
    
    if ($session && $session['uid']) {
        return ['uid' => $session['uid'], 'is_new' => false];
    }
    
    // Generate new UID for this session
    $uid = generate_web_chat_uid();
    
    // Update session with UID and IP address
    $stmt = $pdo->prepare("UPDATE web_chat_sessions SET uid = ?, ip_address = ? WHERE id = ?");
    $stmt->execute([$uid, $ip_address, $session_id]);
    
    return ['uid' => $uid, 'is_new' => true];
}



/**
 * Validate a UID format
 * 
 * @param string $uid The UID to validate
 * @return bool True if valid, false otherwise
 */
function validate_uid($uid): bool {
    // UID should be exactly 16 characters and hexadecimal
    return preg_match('/^[a-f0-9]{16}$/', $uid) === 1;
}

/**
 * Clean up inactive sessions
 * This function should be called periodically to archive inactive sessions
 * 
 * @return int Number of sessions cleaned up
 */
function cleanup_inactive_sessions(): int {
    try {
        $pdo = get_db_connection();
        
        // Find sessions that have been inactive for more than SESSION_TIMEOUT
        $stmt = $pdo->prepare("
            SELECT id, uid, created_at, last_active, ip_address, metadata
            FROM web_chat_sessions 
            WHERE last_active < datetime('now', '-' || ? || ' seconds')
        ");
        $stmt->execute([SESSION_TIMEOUT]);
        $inactive_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($inactive_sessions)) {
            return 0;
        }
        
        $session_ids = array_column($inactive_sessions, 'id');
        $placeholders = str_repeat('?,', count($session_ids) - 1) . '?';

        // Never delete sessions that still have chat history — FK failure was
        // accidentally protecting transcripts. Only remove empty abandoned sessions.
        $stmt = $pdo->prepare("
            SELECT DISTINCT session_id FROM web_chat_messages
            WHERE session_id IN ({$placeholders})
            UNION
            SELECT DISTINCT session_id FROM web_chat_responses
            WHERE session_id IN ({$placeholders})
        ");
        $stmt->execute(array_merge($session_ids, $session_ids));
        $keep = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'session_id');
        $delete_ids = array_values(array_diff($session_ids, $keep));
        if (empty($delete_ids)) {
            return 0;
        }
        $placeholders = str_repeat('?,', count($delete_ids) - 1) . '?';

        $stmt = $pdo->prepare("
            DELETE FROM web_chat_ui_events
            WHERE session_id IN ({$placeholders})
        ");
        $stmt->execute($delete_ids);
        
        $stmt = $pdo->prepare("
            DELETE FROM web_chat_sessions 
            WHERE id IN ({$placeholders})
        ");
        $stmt->execute($delete_ids);
        
        log_message('INFO', 'Cleaned up inactive sessions', [
            'count' => count($delete_ids),
            'session_ids' => $delete_ids,
            'skipped_with_history' => count($keep)
        ]);
        
        return count($delete_ids);
        
    } catch (Exception $e) {
        log_message('ERROR', 'Failed to cleanup inactive sessions', [
            'error' => $e->getMessage()
        ]);
        return 0;
    }
}

function maybe_cleanup_inactive_sessions($probability = 0.1): int {
    // Only run cleanup with the specified probability
    if (mt_rand(1, 100) <= ($probability * 100)) {
        return cleanup_inactive_sessions();
    }
    return 0;
}

/**
 * Update configuration keys in the settings file
 * 
 * @param string $api_key New API key
 * @param string $admin_key New admin key
 * @return bool True if successful, false otherwise
 */
function update_config_keys($api_key, $admin_key): bool {
    unset($api_key, $admin_key);
    log_message('WARNING', 'update_config_keys() is disabled; configure keys out-of-band');
    return false;
} 