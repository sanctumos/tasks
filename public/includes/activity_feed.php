<?php

declare(strict_types=1);

/**
 * Basecamp-style activity timeline: reads audit_logs scoped by directory project
 * or by actor across projects the viewer can access.
 */

/**
 * @return array<int, array<string, mixed>>
 */
function listDirectoryProjectActivity(int $projectId, int $limit, ?int $beforeId = null): array {
    $projectId = (int)$projectId;
    if ($projectId <= 0) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $beforeSql = '';
    $bind = [':pid' => [$projectId, SQLITE3_INTEGER], ':lim' => [$limit, SQLITE3_INTEGER]];
    if ($beforeId !== null && $beforeId > 0) {
        $beforeSql = ' AND a.id < :before ';
        $bind[':before'] = [(int)$beforeId, SQLITE3_INTEGER];
    }

    $where = directoryProjectActivityWhereClause(':pid');

    $sql = "
        SELECT
            a.id,
            a.actor_user_id,
            a.action,
            a.entity_type,
            a.entity_id,
            a.ip_address,
            a.metadata_json,
            a.created_at,
            u.username AS actor_username
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.actor_user_id
        WHERE ({$where})
        {$beforeSql}
        ORDER BY a.id DESC
        LIMIT :lim
    ";

    return activityFeedExecuteAndDecode($sql, $bind);
}

/**
 * Activity for a user, limited to directory projects the viewer can access.
 * Returns null if the viewer is not allowed to read this actor's feed.
 *
 * @return array<int, array<string, mixed>>|null
 */
function listUserActivityFeedForViewer(array $viewerRow, int $targetActorId, int $limit, ?int $beforeId = null): ?array {
    $targetActorId = (int)$targetActorId;
    if ($targetActorId <= 0) {
        return [];
    }
    $viewerId = (int)($viewerRow['id'] ?? 0);
    $self = $viewerId === $targetActorId;
    $unrestricted = userHasUnrestrictedOrgDirectoryAccess($viewerRow);
    $pk = normalizePersonKind($viewerRow['person_kind'] ?? 'team_member');
    $canViewOthers = $unrestricted && $pk !== 'client';
    if (!$self && !$canViewOthers) {
        return null;
    }

    $pids = getAccessibleDirectoryProjectIdsForUser($viewerRow);
    if ($pids === []) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $placeholders = [];
    $bind = [':actor' => [$targetActorId, SQLITE3_INTEGER], ':lim' => [$limit, SQLITE3_INTEGER]];
    foreach ($pids as $i => $pid) {
        $k = ':p' . $i;
        $placeholders[] = $k;
        $bind[$k] = [(int)$pid, SQLITE3_INTEGER];
    }
    $inList = implode(', ', $placeholders);
    $where = directoryProjectActivityWhereClauseInProjects($inList);

    $beforeSql = '';
    if ($beforeId !== null && $beforeId > 0) {
        $beforeSql = ' AND a.id < :before ';
        $bind[':before'] = [(int)$beforeId, SQLITE3_INTEGER];
    }

    $sql = "
        SELECT
            a.id,
            a.actor_user_id,
            a.action,
            a.entity_type,
            a.entity_id,
            a.ip_address,
            a.metadata_json,
            a.created_at,
            u.username AS actor_username
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.actor_user_id
        WHERE a.actor_user_id = :actor
          AND ({$where})
        {$beforeSql}
        ORDER BY a.id DESC
        LIMIT :lim
    ";

    return activityFeedExecuteAndDecode($sql, $bind);
}

function directoryProjectActivityWhereClause(string $paramName): string {
    // $paramName e.g. :pid — same bound value repeated (SQLite).
    return implode(' OR ', [
        "(a.entity_type = 'task' AND EXISTS (SELECT 1 FROM tasks t WHERE t.id = CAST(a.entity_id AS INTEGER) AND t.project_id = {$paramName}))",
        "(a.entity_type = 'task_comment' AND EXISTS (SELECT 1 FROM task_comments tc INNER JOIN tasks t ON t.id = tc.task_id WHERE tc.id = CAST(a.entity_id AS INTEGER) AND t.project_id = {$paramName}))",
        "(a.entity_type = 'task_attachment' AND EXISTS (SELECT 1 FROM task_attachments ta INNER JOIN tasks t ON t.id = ta.task_id WHERE ta.id = CAST(a.entity_id AS INTEGER) AND t.project_id = {$paramName}))",
        "(a.entity_type IN ('task.watch_add', 'task.watch_remove') AND EXISTS (SELECT 1 FROM tasks t WHERE t.id = CAST(a.entity_id AS INTEGER) AND t.project_id = {$paramName}))",
        "(a.entity_type IN ('document.create', 'document.update', 'document.delete', 'document.public_link') AND EXISTS (SELECT 1 FROM documents d WHERE d.id = CAST(a.entity_id AS INTEGER) AND d.project_id = {$paramName}))",
        "(a.entity_type = 'document_comment' AND EXISTS (SELECT 1 FROM document_comments dc INNER JOIN documents d ON d.id = dc.document_id WHERE dc.id = CAST(a.entity_id AS INTEGER) AND d.project_id = {$paramName}))",
        "(a.entity_type IN ('project.member_add', 'project.member_remove') AND CAST(a.entity_id AS INTEGER) = {$paramName})",
        "(a.entity_type = 'project.update' AND CAST(a.entity_id AS INTEGER) = {$paramName})",
        "(a.action = 'todo_list.create' AND a.entity_type = 'todo_list' AND CAST(json_extract(a.metadata_json, '$.project_id') AS INTEGER) = {$paramName})",
        "(a.action = 'task.delete' AND CAST(json_extract(a.metadata_json, '$.project_id') AS INTEGER) = {$paramName})",
    ]);
}

function directoryProjectActivityWhereClauseInProjects(string $inList): string {
    // $inList = :p0, :p1, ...
    return implode(' OR ', [
        "(a.entity_type = 'task' AND EXISTS (SELECT 1 FROM tasks t WHERE t.id = CAST(a.entity_id AS INTEGER) AND t.project_id IN ({$inList})))",
        "(a.entity_type = 'task_comment' AND EXISTS (SELECT 1 FROM task_comments tc INNER JOIN tasks t ON t.id = tc.task_id WHERE tc.id = CAST(a.entity_id AS INTEGER) AND t.project_id IN ({$inList})))",
        "(a.entity_type = 'task_attachment' AND EXISTS (SELECT 1 FROM task_attachments ta INNER JOIN tasks t ON t.id = ta.task_id WHERE ta.id = CAST(a.entity_id AS INTEGER) AND t.project_id IN ({$inList})))",
        "(a.entity_type IN ('task.watch_add', 'task.watch_remove') AND EXISTS (SELECT 1 FROM tasks t WHERE t.id = CAST(a.entity_id AS INTEGER) AND t.project_id IN ({$inList})))",
        "(a.entity_type IN ('document.create', 'document.update', 'document.delete', 'document.public_link') AND EXISTS (SELECT 1 FROM documents d WHERE d.id = CAST(a.entity_id AS INTEGER) AND d.project_id IN ({$inList})))",
        "(a.entity_type = 'document_comment' AND EXISTS (SELECT 1 FROM document_comments dc INNER JOIN documents d ON d.id = dc.document_id WHERE dc.id = CAST(a.entity_id AS INTEGER) AND d.project_id IN ({$inList})))",
        "(a.entity_type IN ('project.member_add', 'project.member_remove') AND CAST(a.entity_id AS INTEGER) IN ({$inList}))",
        "(a.entity_type = 'project.update' AND CAST(a.entity_id AS INTEGER) IN ({$inList}))",
        "(a.action = 'todo_list.create' AND a.entity_type = 'todo_list' AND CAST(json_extract(a.metadata_json, '$.project_id') AS INTEGER) IN ({$inList}))",
        "(a.action = 'task.delete' AND CAST(json_extract(a.metadata_json, '$.project_id') AS INTEGER) IN ({$inList}))",
    ]);
}

/**
 * @param array<string, array{0: mixed, 1: int}> $bind
 * @return array<int, array<string, mixed>>
 */
function activityFeedExecuteAndDecode(string $sql, array $bind): array {
    $db = getDbConnection();
    $stmt = $db->prepare($sql);
    foreach ($bind as $k => $pair) {
        $stmt->bindValue($k, $pair[0], $pair[1]);
    }
    $res = $stmt->execute();
    $items = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['metadata'] = $row['metadata_json'] ? (json_decode((string)$row['metadata_json'], true) ?: []) : [];
        unset($row['metadata_json']);
        $items[] = $row;
    }
    return enrichActivityFeedRows($items);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function enrichActivityFeedRows(array $rows): array {
    if ($rows === []) {
        return [];
    }
    $taskIds = [];
    $docIds = [];
    foreach ($rows as $r) {
        $tid = activityFeedResolveTaskId($r);
        if ($tid !== null) {
            $taskIds[$tid] = true;
        }
        $did = activityFeedResolveDocumentId($r);
        if ($did !== null) {
            $docIds[$did] = true;
        }
    }
    $taskTitles = activityFeedLoadTaskTitles(array_keys($taskIds));
    $docTitles = activityFeedLoadDocumentTitles(array_keys($docIds));

    $out = [];
    foreach ($rows as $r) {
        $meta = isset($r['metadata']) && is_array($r['metadata']) ? $r['metadata'] : [];
        $action = (string)($r['action'] ?? '');
        $actor = (string)($r['actor_username'] ?? '');
        if ($actor === '') {
            $actor = 'System';
        }
        $tid = activityFeedResolveTaskId($r);
        $did = activityFeedResolveDocumentId($r);
        $taskTitle = null;
        if ($tid !== null) {
            $taskTitle = $taskTitles[$tid] ?? null;
        }
        if ($taskTitle === null && $action === 'task.delete' && !empty($meta['title'])) {
            $taskTitle = (string)$meta['title'];
        }
        if ($taskTitle === null && $tid !== null) {
            $taskTitle = 'Task #' . $tid;
        }
        $docTitle = $did !== null ? ($docTitles[$did] ?? ('Document #' . $did)) : null;

        $summary = activityFeedBuildSummary($action, $actor, $taskTitle, $docTitle, $meta, $r);
        $href = activityFeedBuildHref($action, $r, $meta, $tid, $did);
        $icon = activityFeedIconClass($action);

        $r['summary'] = $summary;
        $r['href'] = $href;
        $r['icon'] = $icon;
        $r['task_title'] = $taskTitle;
        $r['document_title'] = $docTitle;
        $out[] = $r;
    }
    return $out;
}

/**
 * @param array<string, mixed> $row
 */
function activityFeedResolveTaskId(array $row): ?int {
    $et = (string)($row['entity_type'] ?? '');
    $eid = (int)($row['entity_id'] ?? 0);
    if ($eid <= 0) {
        return null;
    }
    if ($et === 'task' || $et === 'task.watch_add' || $et === 'task.watch_remove') {
        return $eid;
    }
    if ($et === 'task_comment' || $et === 'task_attachment') {
        $meta = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [];
        $tid = (int)($meta['task_id'] ?? 0);
        return $tid > 0 ? $tid : null;
    }
    return null;
}

/**
 * @param array<string, mixed> $row
 */
function activityFeedResolveDocumentId(array $row): ?int {
    $et = (string)($row['entity_type'] ?? '');
    $eid = (int)($row['entity_id'] ?? 0);
    if ($eid <= 0) {
        return null;
    }
    if ($et === 'document_comment') {
        $meta = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [];
        $did = (int)($meta['document_id'] ?? 0);
        return $did > 0 ? $did : null;
    }
    if ($et === 'document') {
        return $eid;
    }
    return null;
}

/**
 * @param list<int> $ids
 * @return array<int, string>
 */
function activityFeedLoadTaskTitles(array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $x) => $x > 0));
    if ($ids === []) {
        return [];
    }
    $db = getDbConnection();
    $in = implode(',', $ids);
    $res = $db->query('SELECT id, title FROM tasks WHERE id IN (' . $in . ')');
    $map = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $map[(int)$row['id']] = (string)$row['title'];
    }
    return $map;
}

/**
 * @param list<int> $ids
 * @return array<int, string>
 */
function activityFeedLoadDocumentTitles(array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $x) => $x > 0));
    if ($ids === []) {
        return [];
    }
    $db = getDbConnection();
    $in = implode(',', $ids);
    $res = $db->query('SELECT id, title FROM documents WHERE id IN (' . $in . ')');
    $map = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $map[(int)$row['id']] = (string)$row['title'];
    }
    return $map;
}

/**
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $row
 */
function activityFeedBuildSummary(string $action, string $actor, ?string $taskTitle, ?string $docTitle, array $meta, array $row): string {
    $t = $taskTitle ?? 'a task';
    $d = $docTitle ?? 'a document';
    return match ($action) {
        'task.create' => "{$actor} created task “{$t}”.",
        'task.update' => "{$actor} updated task “{$t}”.",
        'task.delete' => "{$actor} deleted task “{$t}”.",
        'task.comment_add' => "{$actor} commented on “{$t}”.",
        'task.attachment_add' => "{$actor} attached a file to “{$t}”.",
        'task.watch_add' => "{$actor} started watching “{$t}”.",
        'task.watch_remove' => "{$actor} stopped watching “{$t}”.",
        'document.create' => "{$actor} created document “{$d}”.",
        'document.update' => "{$actor} updated document “{$d}”.",
        'document.delete' => "{$actor} deleted document “{$d}”.",
        'document.public_link' => "{$actor} changed public link settings on “{$d}”.",
        'document.comment_add' => "{$actor} commented on “{$d}”.",
        'project.member_add' => "{$actor} added a project member.",
        'project.member_remove' => "{$actor} removed a project member.",
        'project.update' => "{$actor} updated project settings.",
        'todo_list.create' => "{$actor} created list “" . (string)($meta['name'] ?? 'list') . "”.",
        default => "{$actor} — " . ($action !== '' ? $action : (string)($row['entity_type'] ?? 'event')) . '.',
    };
}

/**
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $row
 */
function activityFeedBuildHref(string $action, array $row, array $meta, ?int $taskId, ?int $docId): string {
    if ($action === 'task.delete') {
        $pid = (int)($meta['project_id'] ?? 0);
        if ($pid > 0) {
            return '/admin/project.php?id=' . $pid . '&tab=activity';
        }
    }
    if ($taskId !== null && str_starts_with($action, 'task')) {
        return '/admin/view.php?id=' . $taskId;
    }
    if ($docId !== null && str_starts_with($action, 'document')) {
        return '/admin/doc.php?id=' . $docId;
    }
    if ($action === 'project.member_add' || $action === 'project.member_remove' || $action === 'project.update') {
        $pid = (int)($row['entity_id'] ?? 0);
        if ($pid > 0) {
            return '/admin/project.php?id=' . $pid . '&tab=members';
        }
    }
    if ($action === 'todo_list.create') {
        $pid = (int)($meta['project_id'] ?? 0);
        if ($pid > 0) {
            return '/admin/project.php?id=' . $pid . '&tab=lists';
        }
    }
    return '/admin/';
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function activityFeedStripForApi(array $row): array {
    unset($row['ip_address']);
    return $row;
}

function activityFeedIconClass(string $action): string {
    return match (true) {
        str_starts_with($action, 'task.comment') => 'bi-chat-text',
        str_starts_with($action, 'task.attachment') => 'bi-paperclip',
        str_starts_with($action, 'task.watch') => 'bi-eye',
        str_starts_with($action, 'task.') => 'bi-check2-square',
        str_starts_with($action, 'document.comment') => 'bi-chat-square-text',
        str_starts_with($action, 'document.') => 'bi-journal-text',
        str_starts_with($action, 'project.') => 'bi-people',
        str_starts_with($action, 'todo_list.') => 'bi-card-checklist',
        default => 'bi-activity',
    };
}
