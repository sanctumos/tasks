<?php
/**
 * Q bridge — Tasks logged-in user identity (username, first contact).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/functions.php';

/**
 * @return array{tasks_user_id:int,tasks_username:string,tasks_display_name:string}|null
 */
function q_bridge_chatter_from_tasks_user(int $tasksUserId): ?array {
    if ($tasksUserId <= 0) {
        return null;
    }
    $user = getUserById($tasksUserId);
    if (!$user || empty($user['username'])) {
        return null;
    }
    $username = (string)$user['username'];
    return [
        'tasks_user_id' => $tasksUserId,
        'tasks_username' => $username,
        'tasks_display_name' => $username,
    ];
}

/**
 * Messages already sent by this Tasks user (any session) before the current insert.
 */
function q_bridge_prior_message_count_for_tasks_user(int $tasksUserId): int {
    if ($tasksUserId <= 0) {
        return 0;
    }
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM web_chat_messages m
        INNER JOIN web_chat_sessions s ON s.id = m.session_id
        WHERE CAST(json_extract(s.metadata, '$.tasks_user_id') AS INTEGER) = ?
    ");
    $stmt->execute([$tasksUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['c'] ?? 0);
}

/**
 * @param array<string, mixed> $sessionMeta
 * @return array{session_meta: array<string, mixed>, is_first_contact: bool}
 */
function q_bridge_prepare_chatter_context(int $tasksUserId, array $sessionMeta = []): array {
    $chatter = q_bridge_chatter_from_tasks_user($tasksUserId);
    if ($chatter === null) {
        return ['session_meta' => $sessionMeta, 'is_first_contact' => false];
    }
    $prior = q_bridge_prior_message_count_for_tasks_user($tasksUserId);
    $isFirst = $prior === 0;
    $sessionMeta = array_merge($sessionMeta, $chatter);
    return ['session_meta' => $sessionMeta, 'is_first_contact' => $isFirst];
}

/** True when this inbox row is the earliest Q message from that Tasks user. */
function q_bridge_is_first_contact_for_inbox_row(int $tasksUserId, int $messageId): bool {
    if ($tasksUserId <= 0 || $messageId <= 0) {
        return false;
    }
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT MIN(m.id) AS min_id
        FROM web_chat_messages m
        INNER JOIN web_chat_sessions s ON s.id = m.session_id
        WHERE CAST(json_extract(s.metadata, '$.tasks_user_id') AS INTEGER) = ?
    ");
    $stmt->execute([$tasksUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['min_id'] ?? 0) === $messageId;
}

/** Session must belong to the logged-in Tasks user. */
function q_bridge_session_belongs_to_tasks_user(string $sessionId, int $tasksUserId): bool {
    if ($sessionId === '' || $tasksUserId <= 0) {
        return false;
    }
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT metadata FROM web_chat_sessions WHERE id = ? LIMIT 1');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $meta = json_decode((string)($row['metadata'] ?? ''), true);
    if (!is_array($meta)) {
        return false;
    }
    return (int)($meta['tasks_user_id'] ?? 0) === $tasksUserId;
}

/**
 * Recent chat turns for widget restore (user + Q), merged by time.
 *
 * @return array{items: list<array{role:string,text:string,timestamp:string,id:string}>, latest_response_at: ?string}
 */
function q_bridge_fetch_recent_history(string $sessionId, int $limit = 6): array {
    $limit = max(1, min(20, $limit));
    $pdo = get_db_connection();

    $stmt = $pdo->prepare("
        SELECT 'user' AS role, message AS text, timestamp, id
        FROM web_chat_messages
        WHERE session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT 'assistant' AS role, response AS text, timestamp, id
        FROM web_chat_responses
        WHERE session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = array_merge($items, $responses);

    usort($items, static function ($a, $b) {
        return strcmp((string)$a['timestamp'], (string)$b['timestamp']);
    });

    if (count($items) > $limit) {
        $items = array_slice($items, -$limit);
    }

    $out = [];
    $latestResponseAt = null;
    foreach ($items as $row) {
        $role = (string)($row['role'] ?? '');
        $prefix = $role === 'user' ? 'm' : 'r';
        $out[] = [
            'role' => $role,
            'text' => (string)($row['text'] ?? ''),
            'timestamp' => (string)($row['timestamp'] ?? ''),
            'id' => $prefix . '-' . (string)($row['id'] ?? ''),
        ];
        if ($role === 'assistant' && !empty($row['timestamp'])) {
            $latestResponseAt = (string)$row['timestamp'];
        }
    }

    return ['items' => $out, 'latest_response_at' => $latestResponseAt];
}

/** Stable session id per Tasks user (same thread on desktop + phone). */
function q_bridge_canonical_session_id(int $tasksUserId): string {
    return 'session_tasks_' . $tasksUserId;
}

/**
 * Ensure the canonical session exists for this Tasks user.
 *
 * @return array{session_id: string, session_meta: array<string, mixed>}
 */
function q_bridge_ensure_user_session(int $tasksUserId): array {
    $sessionId = q_bridge_canonical_session_id($tasksUserId);
    $ctx = q_bridge_prepare_chatter_context($tasksUserId, ['tasks_user_id' => $tasksUserId]);
    $sessionMeta = $ctx['session_meta'];

    if (!is_session_active($sessionId)) {
        if (!create_session($sessionId, $sessionMeta)) {
            $pdo = get_db_connection();
            $upd = $pdo->prepare('UPDATE web_chat_sessions SET metadata = ?, last_active = CURRENT_TIMESTAMP WHERE id = ?');
            $upd->execute([json_encode($sessionMeta), $sessionId]);
        }
    } else {
        $pdo = get_db_connection();
        $upd = $pdo->prepare('UPDATE web_chat_sessions SET metadata = ?, last_active = CURRENT_TIMESTAMP WHERE id = ?');
        $upd->execute([json_encode($sessionMeta), $sessionId]);
    }

    return ['session_id' => $sessionId, 'session_meta' => $sessionMeta];
}

/**
 * Last N turns across all Ask Q sessions for this Tasks user (cross-device).
 *
 * @return array{items: list<array{role:string,text:string,timestamp:string,id:string}>, latest_response_at: ?string}
 */
function q_bridge_fetch_user_recent_history(int $tasksUserId, int $limit = 6): array {
    if ($tasksUserId <= 0) {
        return ['items' => [], 'latest_response_at' => null];
    }
    $limit = max(1, min(20, $limit));
    $pdo = get_db_connection();

    $stmt = $pdo->prepare("
        SELECT 'user' AS role, m.message AS text, m.timestamp, m.id, m.session_id
        FROM web_chat_messages m
        INNER JOIN web_chat_sessions s ON s.id = m.session_id
        WHERE CAST(json_extract(s.metadata, '$.tasks_user_id') AS INTEGER) = ?
    ");
    $stmt->execute([$tasksUserId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT 'assistant' AS role, r.response AS text, r.timestamp, r.id, r.session_id
        FROM web_chat_responses r
        INNER JOIN web_chat_sessions s ON s.id = r.session_id
        WHERE CAST(json_extract(s.metadata, '$.tasks_user_id') AS INTEGER) = ?
    ");
    $stmt->execute([$tasksUserId]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = array_merge($items, $responses);

    usort($items, static function ($a, $b) {
        return strcmp((string)$a['timestamp'], (string)$b['timestamp']);
    });

    if (count($items) > $limit) {
        $items = array_slice($items, -$limit);
    }

    $out = [];
    $latestResponseAt = null;
    foreach ($items as $row) {
        $role = (string)($row['role'] ?? '');
        $prefix = $role === 'user' ? 'm' : 'r';
        $sid = (string)($row['session_id'] ?? '');
        $out[] = [
            'role' => $role,
            'text' => (string)($row['text'] ?? ''),
            'timestamp' => (string)($row['timestamp'] ?? ''),
            'id' => $prefix . '-' . $sid . '-' . (string)($row['id'] ?? ''),
        ];
        if ($role === 'assistant' && !empty($row['timestamp'])) {
            $latestResponseAt = (string)$row['timestamp'];
        }
    }

    return ['items' => $out, 'latest_response_at' => $latestResponseAt];
}
