<?php

/**
 * User notification feed — assignments, @mentions, activity on watched/assigned tasks
 * and document threads. Persisted in user_notifications (see applySanctumSchemaMigrations).
 */

/** @return list<string> */
function tasksExtractMentionUsernamesFromText(string $text): array {
    if ($text === '' || strpos($text, '@') === false) {
        return [];
    }
    if (!preg_match_all('/(^|[\s(>])@([A-Za-z0-9][A-Za-z0-9_.\-]{1,31})(?![A-Za-z0-9_.\-])/u', $text, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[2] as $u) {
        $norm = normalizeUsername((string)$u);
        if ($norm !== '') {
            $out[$norm] = true;
        }
    }
    return array_keys($out);
}

/**
 * @param string|null $excludeUsername skip @self in document/task author context
 * @return array<int, array{username:string}> userId indexed
 */
function tasksMentionRecipientUsers(string $text, int $excludeUserId, ?string $excludeUsername = null): array {
    $excludeUsername = normalizeUsername((string)$excludeUsername);
    $map = [];
    foreach (tasksExtractMentionUsernamesFromText($text) as $n) {
        if ($excludeUsername !== '' && normalizeUsername($n) === $excludeUsername) {
            continue;
        }
        $row = getUserByUsername($n, false);
        if (!$row || (int)$row['is_active'] !== 1) {
            continue;
        }
        $uid = (int)$row['id'];
        if ($uid === $excludeUserId) {
            continue;
        }
        $map[$uid] = ['username' => (string)$row['username']];
    }
    return $map;
}

function notificationActorPayload(?int $actorUserId): array {
    if ($actorUserId === null || $actorUserId <= 0) {
        return ['actor_user_id' => null, 'actor_username' => null];
    }
    $a = getUserById((int)$actorUserId, false);
    return [
        'actor_user_id' => (int)$actorUserId,
        'actor_username' => $a ? (string)$a['username'] : null,
    ];
}

function notificationsTaskHref(int $taskId, ?int $commentId = null): string {
    $h = '/admin/view.php?id=' . $taskId;
    if ($commentId !== null && $commentId > 0) {
        $h .= '#comment-' . $commentId;
    }
    return $h;
}

function notificationsDocHref(int $documentId, ?int $commentId = null): string {
    $h = '/admin/doc.php?id=' . $documentId;
    if ($commentId !== null && $commentId > 0) {
        $h .= '#comment-' . $commentId;
    }
    return $h;
}

function notificationsInsertRow(
    int $recipientUserId,
    ?int $actorUserId,
    string $kind,
    ?int $taskId,
    ?int $documentId,
    ?int $taskCommentId,
    ?int $documentCommentId,
    array $payload,
    ?string $dedupeKey
): void {
    $db = getDbConnection();
    if (!tableExists($db, 'user_notifications')) {
        return;
    }
    if ($recipientUserId <= 0) {
        return;
    }
    if ($actorUserId !== null && $actorUserId > 0 && $recipientUserId === $actorUserId) {
        return;
    }
    $recipient = getUserById($recipientUserId, false);
    if (!$recipient || (int)$recipient['is_active'] !== 1) {
        return;
    }

    $taskTitle = isset($payload['title']) ? (string)$payload['title'] : '';
    unset($payload['title']);
    $payloadJson = json_encode(
        ['title' => $taskTitle] + notificationActorPayload($actorUserId) + $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($dedupeKey !== null && $dedupeKey !== '') {
        $stmt = $db->prepare('INSERT OR IGNORE INTO user_notifications
            (user_id, actor_user_id, kind, task_id, document_id, task_comment_id, document_comment_id, payload_json, dedupe_key)
            VALUES (:uid, :aid, :kind, :tid, :did, :tcid, :dcid, :pj, :dk)');
        $stmt->bindValue(':dk', $dedupeKey, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('INSERT INTO user_notifications
            (user_id, actor_user_id, kind, task_id, document_id, task_comment_id, document_comment_id, payload_json, dedupe_key)
            VALUES (:uid, :aid, :kind, :tid, :did, :tcid, :dcid, :pj, NULL)');
    }
    $stmt->bindValue(':uid', $recipientUserId, SQLITE3_INTEGER);
    if ($actorUserId === null || $actorUserId <= 0) {
        $stmt->bindValue(':aid', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':aid', $actorUserId, SQLITE3_INTEGER);
    }
    $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
    $stmt->bindValue(':tid', $taskId === null ? null : $taskId, $taskId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':did', $documentId === null ? null : $documentId, $documentId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':tcid', $taskCommentId === null ? null : $taskCommentId, $taskCommentId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':dcid', $documentCommentId === null ? null : $documentCommentId, $documentCommentId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':pj', $payloadJson, SQLITE3_TEXT);
    $stmt->execute();
}

function notificationsAfterTaskAssigned(?int $actorUserId, array $task, ?int $oldAssigneeId, ?int $newAssigneeId): void {
    if ($newAssigneeId === null || $newAssigneeId <= 0) {
        return;
    }
    if ($oldAssigneeId !== null && (int)$oldAssigneeId === (int)$newAssigneeId) {
        return;
    }
    $viewer = getUserById((int)$newAssigneeId, false);
    if (!$viewer || !userCanAccessTaskForViewer($viewer, $task)) {
        return;
    }
    $tid = (int)$task['id'];
    notificationsInsertRow(
        (int)$newAssigneeId,
        $actorUserId,
        'task_assigned',
        $tid,
        null,
        null,
        null,
        [
            'title' => (string)($task['title'] ?? ''),
            'label' => 'You were assigned this task.',
            'href' => notificationsTaskHref($tid),
        ],
        null
    );
}

function notificationsTaskBodyMentions(?int $actorUserId, array $task, ?string $oldBody, string $newBody): void {
    $oldBody = $oldBody === null ? '' : (string)$oldBody;
    $oldUsers = tasksMentionRecipientUsers($oldBody, (int)$actorUserId);
    $newUsers = tasksMentionRecipientUsers($newBody, (int)$actorUserId);
    foreach ($newUsers as $recipientId => $_) {
        if (isset($oldUsers[$recipientId])) {
            continue;
        }
        $viewer = getUserById((int)$recipientId, false);
        if (!$viewer || !userCanAccessTaskForViewer($viewer, $task)) {
            continue;
        }
        $tid = (int)$task['id'];
        notificationsInsertRow(
            (int)$recipientId,
            $actorUserId,
            'task_mention',
            $tid,
            null,
            null,
            null,
            [
                'title' => (string)($task['title'] ?? ''),
                'label' => '@mentioned you in task description.',
                'href' => notificationsTaskHref($tid),
                'snippet' => truncateString($newBody, 180),
            ],
            'mention:task_body:' . $tid . ':' . (int)$recipientId . ':' . hash('crc32b', $newBody)
        );
    }
}

function notificationsAfterTaskComment(array $task, int $commentId, int $authorUserId, string $commentText): void {
    $taskId = (int)$task['id'];
    $title = (string)($task['title'] ?? '');

    $mentioned = tasksMentionRecipientUsers($commentText, $authorUserId);
    foreach ($mentioned as $recipientId => $_) {
        $viewer = getUserById((int)$recipientId, false);
        if (!$viewer || !userCanAccessTaskForViewer($viewer, $task)) {
            continue;
        }
        notificationsInsertRow(
            (int)$recipientId,
            $authorUserId,
            'task_comment_mention',
            $taskId,
            null,
            $commentId,
            null,
            [
                'title' => $title,
                'label' => '@mentioned you in a comment.',
                'href' => notificationsTaskHref($taskId, $commentId),
                'snippet' => truncateString($commentText, 180),
            ],
            'mention:task_comment:' . $commentId . ':' . $recipientId
        );
    }

    $mentionIds = array_map('intval', array_keys($mentioned));
    $assigneeId = (int)($task['assigned_to_user_id'] ?? 0);
    $recipientPool = [];

    foreach (listTaskWatchers($taskId) as $w) {
        $wid = (int)($w['user_id'] ?? 0);
        if ($wid > 0 && $wid !== $authorUserId) {
            $recipientPool[$wid] = true;
        }
    }
    if ($assigneeId > 0 && $assigneeId !== $authorUserId) {
        $recipientPool[$assigneeId] = true;
    }

    foreach (array_keys($recipientPool) as $rid) {
        if ($rid === $authorUserId) {
            continue;
        }
        if (in_array((int)$rid, $mentionIds, true)) {
            continue;
        }
        $viewer = getUserById((int)$rid, false);
        if (!$viewer || !userCanAccessTaskForViewer($viewer, $task)) {
            continue;
        }
        notificationsInsertRow(
            (int)$rid,
            $authorUserId,
            'task_comment_activity',
            $taskId,
            null,
            $commentId,
            null,
            [
                'title' => $title,
                'label' => 'New comment on a task you follow.',
                'href' => notificationsTaskHref($taskId, $commentId),
                'snippet' => truncateString($commentText, 180),
            ],
            'activity:task_comment:' . $commentId . ':' . $rid
        );
    }
}

function notificationsDocumentBodyMentions(?int $actorUserId, array $doc, ?string $oldBody, string $newBody): void {
    $oldBody = $oldBody === null ? '' : (string)$oldBody;
    $creator = getUserById((int)($doc['created_by_user_id'] ?? 0), false);
    $creatorName = $creator ? (string)$creator['username'] : '';
    $oldUsers = tasksMentionRecipientUsers($oldBody, (int)$actorUserId, $creatorName);
    $newUsers = tasksMentionRecipientUsers($newBody, (int)$actorUserId, $creatorName);
    foreach ($newUsers as $recipientId => $_) {
        if (isset($oldUsers[$recipientId])) {
            continue;
        }
        $viewer = getUserById((int)$recipientId, false);
        if (!$viewer || !userCanAccessDocument($viewer, $doc)) {
            continue;
        }
        $did = (int)$doc['id'];
        notificationsInsertRow(
            (int)$recipientId,
            $actorUserId,
            'document_mention',
            null,
            $did,
            null,
            null,
            [
                'title' => (string)($doc['title'] ?? ''),
                'label' => '@mentioned you in document body.',
                'href' => notificationsDocHref($did),
                'snippet' => truncateString($newBody, 180),
            ],
            'mention:doc_body:' . $did . ':' . (int)$recipientId . ':' . hash('crc32b', $newBody)
        );
    }
}

function notificationsAfterDocumentComment(array $doc, int $commentId, int $authorUserId, string $commentText): void {
    $docId = (int)$doc['id'];
    $title = (string)($doc['title'] ?? '');
    $creatorId = (int)($doc['created_by_user_id'] ?? 0);

    $mentioned = tasksMentionRecipientUsers($commentText, $authorUserId);
    foreach ($mentioned as $recipientId => $_) {
        $viewer = getUserById((int)$recipientId, false);
        if (!$viewer || !userCanAccessDocument($viewer, $doc)) {
            continue;
        }
        notificationsInsertRow(
            (int)$recipientId,
            $authorUserId,
            'document_comment_mention',
            null,
            $docId,
            null,
            $commentId,
            [
                'title' => $title,
                'label' => '@mentioned you on a document.',
                'href' => notificationsDocHref($docId, $commentId),
                'snippet' => truncateString($commentText, 180),
            ],
            'mention:doc_comment:' . $commentId . ':' . $recipientId
        );
    }

    $mentionIds = array_map('intval', array_keys($mentioned));
    if ($creatorId > 0 && $creatorId !== $authorUserId && !in_array($creatorId, $mentionIds, true)) {
        $viewer = getUserById($creatorId, false);
        if ($viewer && userCanAccessDocument($viewer, $doc)) {
            notificationsInsertRow(
                $creatorId,
                $authorUserId,
                'document_comment_activity',
                null,
                $docId,
                null,
                $commentId,
                [
                    'title' => $title,
                    'label' => 'New comment on your document.',
                    'href' => notificationsDocHref($docId, $commentId),
                    'snippet' => truncateString($commentText, 180),
                ],
                'activity:doc_comment:' . $commentId . ':' . $creatorId
            );
        }
    }
}

/**
 * @return array{notifications: array<int, array<string,mixed>>, next_before_id: ?int}
 */
function listNotificationsForUser(int $userId, int $limit = 50, ?int $beforeId = null, bool $unreadOnly = false): array {
    $limit = max(1, min(100, $limit));
    $db = getDbConnection();
    if (!tableExists($db, 'user_notifications')) {
        return ['notifications' => [], 'next_before_id' => null];
    }

    $where = ['user_id = :uid'];
    $bind = [':uid' => [$userId, SQLITE3_INTEGER]];
    if ($beforeId !== null && $beforeId > 0) {
        $where[] = 'id < :before';
        $bind[':before'] = [$beforeId, SQLITE3_INTEGER];
    }
    if ($unreadOnly) {
        $where[] = 'read_at IS NULL';
    }
    $sql = 'SELECT * FROM user_notifications WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT :lim';

    $stmt = $db->prepare($sql);
    foreach ($bind as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $stmt->bindValue(':lim', $limit + 1, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }
    $nextBefore = null;
    if ($hasMore && count($rows) > 0) {
        $nextBefore = (int)$rows[count($rows) - 1]['id'];
    }

    $out = [];
    foreach ($rows as $row) {
        $payload = [];
        $pj = (string)($row['payload_json'] ?? '');
        if ($pj !== '') {
            $decoded = json_decode($pj, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $out[] = [
            'id' => (int)$row['id'],
            'kind' => (string)$row['kind'],
            'created_at' => (string)$row['created_at'],
            'read_at' => $row['read_at'] !== null ? (string)$row['read_at'] : null,
            'task_id' => $row['task_id'] !== null ? (int)$row['task_id'] : null,
            'document_id' => $row['document_id'] !== null ? (int)$row['document_id'] : null,
            'task_comment_id' => $row['task_comment_id'] !== null ? (int)$row['task_comment_id'] : null,
            'document_comment_id' => $row['document_comment_id'] !== null ? (int)$row['document_comment_id'] : null,
            'label' => (string)($payload['label'] ?? ''),
            'title' => (string)($payload['title'] ?? ''),
            'href' => (string)($payload['href'] ?? ''),
            'snippet' => isset($payload['snippet']) ? (string)$payload['snippet'] : null,
            'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
            'actor_username' => isset($payload['actor_username']) ? (string)$payload['actor_username'] : null,
        ];
    }

    return ['notifications' => $out, 'next_before_id' => $nextBefore];
}

function countUnreadNotifications(int $userId): int {
    $db = getDbConnection();
    if (!tableExists($db, 'user_notifications')) {
        return 0;
    }
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM user_notifications WHERE user_id = :u AND read_at IS NULL');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return (int)($row['c'] ?? 0);
}

function markNotificationsRead(int $userId, array $ids): int {
    if ($ids === []) {
        return 0;
    }
    $db = getDbConnection();
    if (!tableExists($db, 'user_notifications')) {
        return 0;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) {
        return 0;
    }
    $ph = implode(', ', array_fill(0, count($ids), '?'));
    $sql = 'UPDATE user_notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND read_at IS NULL AND id IN (' . $ph . ')';
    $stmt = $db->prepare($sql);
    $i = 1;
    $stmt->bindValue($i++, $userId, SQLITE3_INTEGER);
    foreach ($ids as $id) {
        $stmt->bindValue($i++, $id, SQLITE3_INTEGER);
    }
    $stmt->execute();
    return method_exists($db, 'changes') ? (int)$db->changes() : 0;
}

function markAllNotificationsRead(int $userId): void {
    $db = getDbConnection();
    if (!tableExists($db, 'user_notifications')) {
        return;
    }
    $stmt = $db->prepare('UPDATE user_notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = :u AND read_at IS NULL');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}
