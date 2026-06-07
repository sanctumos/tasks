<?php
/**
 * Schedule aggregation — due_at tasks grouped for calendar-style views (Phase 4.1).
 */

declare(strict_types=1);

/**
 * @return 'mine'|'project'|'all'|null
 */
function normalizeScheduleScope(?string $scope): ?string
{
    $s = strtolower(trim((string)$scope));
    if (in_array($s, ['mine', 'project', 'all'], true)) {
        return $s;
    }
    return null;
}

/**
 * @param array{
 *   scope?:string,
 *   project_id?:int,
 *   due_after?:string|null,
 *   due_before?:string|null,
 *   include_done?:bool,
 *   include_overdue?:bool,
 *   limit?:int
 * } $options
 * @return array{entries:array<int,array<string,mixed>>,count:int,scope:string,due_after:string,due_before:string,grouped_by_date:array<int,array<string,mixed>>}
 */
function listScheduleForViewer(array $viewerRow, array $options = []): array
{
    $scope = normalizeScheduleScope($options['scope'] ?? 'mine') ?? 'mine';
    $includeDone = !empty($options['include_done']);
    $includeOverdue = !array_key_exists('include_overdue', $options) || !empty($options['include_overdue']);
    $limit = max(1, min(500, (int)($options['limit'] ?? 200)));

    $utc = new DateTimeZone('UTC');
    $today = new DateTime('today', $utc);

    $dueAfter = parseDateTimeOrNull($options['due_after'] ?? null);
    $dueBefore = parseDateTimeOrNull($options['due_before'] ?? null);

    if ($dueBefore === null) {
        $dueBefore = (clone $today)->modify('+30 days')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    }
    if ($dueAfter === null) {
        if ($includeOverdue) {
            $dueAfter = (clone $today)->modify('-90 days')->format('Y-m-d 00:00:00');
        } else {
            $dueAfter = $today->format('Y-m-d 00:00:00');
        }
    }

    $filters = [
        'due_after' => $dueAfter,
        'due_before' => $dueBefore,
        'sort_by' => 'due_at',
        'sort_dir' => 'ASC',
        'limit' => $limit,
        'offset' => 0,
    ];

    if ($scope === 'mine') {
        $filters['assigned_to_user_id'] = (int)$viewerRow['id'];
    } elseif ($scope === 'project') {
        $pid = (int)($options['project_id'] ?? 0);
        if ($pid <= 0) {
            return [
                'entries' => [],
                'count' => 0,
                'scope' => $scope,
                'due_after' => $dueAfter,
                'due_before' => $dueBefore,
                'grouped_by_date' => [],
                'error' => 'project_id is required for project scope',
            ];
        }
        $proj = getDirectoryProjectById($pid);
        if (!$proj || !userCanAccessDirectoryProject($viewerRow, $proj)) {
            return [
                'entries' => [],
                'count' => 0,
                'scope' => $scope,
                'due_after' => $dueAfter,
                'due_before' => $dueBefore,
                'grouped_by_date' => [],
                'error' => 'Project not found or not accessible',
            ];
        }
        $filters['project_id'] = $pid;
    }

    $result = listTasks($filters, true, null, $viewerRow);
    $tasks = $result['tasks'] ?? [];

    $now = new DateTime('now', $utc);
    $todayStr = $today->format('Y-m-d');
    $entries = [];

    foreach ($tasks as $t) {
        if (empty($t['due_at'])) {
            continue;
        }
        if (!$includeDone && !empty((int)($t['status_is_done'] ?? 0))) {
            continue;
        }

        try {
            $dueDt = new DateTime((string)$t['due_at'], $utc);
        } catch (Exception $e) {
            continue;
        }

        $dueDate = $dueDt->format('Y-m-d');
        $isDone = !empty((int)($t['status_is_done'] ?? 0));
        $isOverdue = !$isDone && $dueDt < $now;

        $entries[] = [
            'task_id' => (int)$t['id'],
            'title' => (string)$t['title'],
            'due_at' => (string)$t['due_at'],
            'due_date' => $dueDate,
            'status' => (string)$t['status'],
            'status_label' => (string)($t['status_label'] ?? $t['status']),
            'priority' => (string)($t['priority'] ?? 'normal'),
            'project_id' => isset($t['project_id']) ? (int)$t['project_id'] : null,
            'project_name' => $t['directory_project_name'] ?? $t['project'] ?? null,
            'assigned_to_user_id' => isset($t['assigned_to_user_id']) ? (int)$t['assigned_to_user_id'] : null,
            'assigned_to_username' => $t['assigned_to_username'] ?? null,
            'is_overdue' => $isOverdue,
            'is_due_today' => $dueDate === $todayStr,
        ];
    }

    return [
        'entries' => $entries,
        'count' => count($entries),
        'scope' => $scope,
        'due_after' => $dueAfter,
        'due_before' => $dueBefore,
        'grouped_by_date' => groupScheduleEntriesByDate($entries),
    ];
}

/**
 * @param array<int,array<string,mixed>> $entries
 * @return array<int,array{date:string,entries:array<int,array<string,mixed>>,count:int}>
 */
function groupScheduleEntriesByDate(array $entries): array
{
    $byDate = [];
    foreach ($entries as $entry) {
        $d = (string)($entry['due_date'] ?? '');
        if ($d === '') {
            continue;
        }
        $byDate[$d][] = $entry;
    }
    ksort($byDate);
    $out = [];
    foreach ($byDate as $date => $items) {
        $out[] = [
            'date' => $date,
            'entries' => $items,
            'count' => count($items),
        ];
    }
    return $out;
}
