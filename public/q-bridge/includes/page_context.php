<?php
/**
 * Ask Q — page / project context for Broca (what screen the chatter is on).
 *
 * Sends IDs + short labels only — never document/task body text. Q loads full
 * content via get-document / get-task when needed.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/utils.php';

/**
 * Canonical site origin for admin UI links (no trailing slash).
 */
function q_bridge_admin_origin(): string {
    return rtrim(get_base_url(), '/');
}

/**
 * Build a path under /admin/ (relative, for Layer B + widget).
 */
function q_bridge_admin_path(string $script, array $query = []): string {
    $path = '/admin/' . ltrim($script, '/');
    if ($query === []) {
        return $path;
    }
    return $path . '?' . http_build_query($query);
}

function q_bridge_request_query_int(string $key): int {
    if (isset($_GET[$key])) {
        return max(0, (int)$_GET[$key]);
    }
    $qs = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if (is_string($qs) && $qs !== '') {
        $params = [];
        parse_str($qs, $params);
        return max(0, (int)($params[$key] ?? 0));
    }
    return 0;
}

function q_bridge_request_query_string(string $key, int $maxLen = 64): string {
    $val = '';
    if (isset($_GET[$key]) && is_string($_GET[$key])) {
        $val = $_GET[$key];
    } else {
        $qs = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            $params = [];
            parse_str($qs, $params);
            $val = (string)($params[$key] ?? '');
        }
    }
    return substr(trim($val), 0, $maxLen);
}

/**
 * @return array<string, mixed>
 */
function q_bridge_detect_admin_page_context(): array {
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $id = static fn (string $key): int => q_bridge_request_query_int($key);

    if (preg_match('#/admin/view\.php$#', $path)) {
        $taskId = $id('id');
        return $taskId > 0
            ? ['surface' => 'task', 'task_id' => $taskId]
            : ['surface' => 'tasks'];
    }
    if (preg_match('#/admin/(doc|document)\.php$#', $path)) {
        $docId = $id('id');
        return $docId > 0
            ? ['surface' => 'document', 'document_id' => $docId]
            : ['surface' => 'docs'];
    }
    if (preg_match('#/admin/project\.php$#', $path)) {
        $projectId = $id('id');
        $tab = q_bridge_request_query_string('tab', 32);
        $ctx = $projectId > 0
            ? ['surface' => 'project', 'project_id' => $projectId]
            : ['surface' => 'projects'];
        if ($tab !== '') {
            $ctx['tab'] = $tab;
        }
        return $ctx;
    }
    if (preg_match('#/admin/docs\.php$#', $path)) {
        $projectId = $id('project_id');
        $ctx = ['surface' => 'docs'];
        if ($projectId > 0) {
            $ctx['project_id'] = $projectId;
        }
        $dir = trim((string)($_GET['dir'] ?? ''));
        if ($dir !== '') {
            $ctx['directory_path'] = $dir;
        }
        return $ctx;
    }
    if (preg_match('#/admin/doc-create\.php$#', $path)) {
        $projectId = $id('project_id');
        $ctx = ['surface' => 'doc_create'];
        if ($projectId > 0) {
            $ctx['project_id'] = $projectId;
        }
        return $ctx;
    }
    if (preg_match('#/admin/doc-update\.php$#', $path)) {
        $docId = $id('id');
        if ($docId > 0) {
            return ['surface' => 'document', 'document_id' => $docId];
        }
        $projectId = $id('project_id');
        $ctx = ['surface' => 'doc_create'];
        if ($projectId > 0) {
            $ctx['project_id'] = $projectId;
        }
        return $ctx;
    }
    if (preg_match('#/admin/create\.php$#', $path)) {
        $projectId = $id('project_id');
        $listId = $id('list_id');
        $ctx = ['surface' => 'task_create'];
        if ($projectId > 0) {
            $ctx['project_id'] = $projectId;
        }
        if ($listId > 0) {
            $ctx['list_id'] = $listId;
        }
        return $ctx;
    }
    if (preg_match('#/admin/(index\.php)?$#', $path) || $path === '/admin' || $path === '/admin/') {
        $projectId = $id('project_id');
        if ($projectId > 0) {
            return ['surface' => 'project', 'project_id' => $projectId];
        }
        return ['surface' => 'home'];
    }
    if (preg_match('#/admin/activity\.php$#', $path)) {
        return ['surface' => 'activity'];
    }
    if (preg_match('#/admin/settings\.php$#', $path)) {
        return ['surface' => 'settings'];
    }
    if (preg_match('#/admin/workspace-projects\.php$#', $path)) {
        return ['surface' => 'projects'];
    }
    if (preg_match('#/admin/workspace-project\.php$#', $path)) {
        $projectId = $id('id');
        return $projectId > 0
            ? ['surface' => 'project', 'project_id' => $projectId]
            : ['surface' => 'projects'];
    }
    if (preg_match('#/admin/(users|organizations|api-keys|audit|notifications|mfa|documentation|user-projects)\.php$#', $path, $m)) {
        return ['surface' => 'admin', 'admin_page' => $m[1]];
    }
    if (str_starts_with($path, '/admin/')) {
        $base = basename($path, '.php');
        return ['surface' => 'admin', 'admin_page' => $base !== '' ? $base : 'admin'];
    }
    return ['surface' => 'unknown'];
}

/**
 * Parse /admin/* path + query from a relative URL (widget sends window.location).
 *
 * @return array<string, mixed>
 */
function q_bridge_page_context_from_url(string $url): array {
    $url = trim($url);
    if ($url === '') {
        return [];
    }
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?: '');
    if ($path === '') {
        return [];
    }
    $prevUri = $_SERVER['REQUEST_URI'] ?? '';
    $prevGet = $_GET;
    $_SERVER['REQUEST_URI'] = $path . ($query !== '' ? '?' . $query : '');
    $_GET = [];
    if ($query !== '') {
        parse_str($query, $_GET);
    }
    try {
        return q_bridge_detect_admin_page_context();
    } finally {
        $_SERVER['REQUEST_URI'] = $prevUri;
        $_GET = $prevGet;
    }
}

/**
 * @param array<string, mixed>|null $raw
 * @return array<string, mixed>
 */
function q_bridge_normalize_page_context(?array $raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    if (!empty($raw['surface']) && is_string($raw['surface'])) {
        $out['surface'] = substr(trim($raw['surface']), 0, 48);
    }
    foreach (['project_id', 'task_id', 'document_id', 'list_id', 'org_id'] as $key) {
        if (isset($raw[$key]) && (int)$raw[$key] > 0) {
            $out[$key] = (int)$raw[$key];
        }
    }
    if (!empty($raw['url']) && is_string($raw['url'])) {
        $out['url'] = substr(trim($raw['url']), 0, 512);
    }
    if (!empty($raw['page_title']) && is_string($raw['page_title'])) {
        $out['page_title'] = substr(trim($raw['page_title']), 0, 200);
    }
    if (!empty($raw['directory_path']) && is_string($raw['directory_path'])) {
        $out['directory_path'] = substr(trim($raw['directory_path']), 0, 256);
    }
    if (!empty($raw['tab']) && is_string($raw['tab'])) {
        $out['tab'] = substr(trim($raw['tab']), 0, 32);
    }
    if (!empty($raw['admin_page']) && is_string($raw['admin_page'])) {
        $out['admin_page'] = substr(trim($raw['admin_page']), 0, 48);
    }

    // Authoritative: current browser URL beats stale embed-time context.
    if (!empty($out['url']) && is_string($out['url'])) {
        $fromUrl = q_bridge_page_context_from_url($out['url']);
        foreach ([
            'surface', 'project_id', 'task_id', 'document_id', 'directory_path',
            'list_id', 'tab', 'admin_page',
        ] as $key) {
            if (isset($fromUrl[$key])) {
                $out[$key] = $fromUrl[$key];
            }
        }
    }

    return $out;
}

/**
 * Attach admin paths + absolute links for known entities.
 *
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function q_bridge_attach_context_links(array $ctx): array {
    $origin = (string)($ctx['admin_origin'] ?? q_bridge_admin_origin());
    if (!empty($ctx['task_id'])) {
        $rel = q_bridge_admin_path('view.php', ['id' => (int)$ctx['task_id']]);
        $ctx['task_path'] = $rel;
        $ctx['task_link'] = $origin . $rel;
    }
    if (!empty($ctx['document_id'])) {
        $rel = q_bridge_admin_path('doc.php', ['id' => (int)$ctx['document_id']]);
        $ctx['document_path'] = $rel;
        $ctx['document_link'] = $origin . $rel;
    }
    if (!empty($ctx['project_id'])) {
        $q = ['id' => (int)$ctx['project_id']];
        if (!empty($ctx['tab']) && is_string($ctx['tab'])) {
            $q['tab'] = $ctx['tab'];
        }
        $rel = q_bridge_admin_path('project.php', $q);
        $ctx['project_path'] = $rel;
        $ctx['project_link'] = $origin . $rel;
    }
    if (!empty($ctx['url']) && is_string($ctx['url']) && str_starts_with($ctx['url'], '/')) {
        $ctx['page_link'] = $origin . $ctx['url'];
    }
    return $ctx;
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function q_bridge_enrich_page_context(array $ctx, array $viewerUser): array {
    if ($ctx === []) {
        return [];
    }

    $surface = (string)($ctx['surface'] ?? 'unknown');

    if (!empty($ctx['task_id'])) {
        $taskId = (int)$ctx['task_id'];
        $task = getTaskById($taskId, false);
        if (!$task || !userCanAccessTaskForViewer($viewerUser, $task)) {
            unset($ctx['task_id'], $ctx['task_title'], $ctx['task_status'], $ctx['list_id']);
        } else {
            $ctx['task_id'] = $taskId;
            $ctx['task_title'] = (string)($task['title'] ?? '');
            $ctx['task_status'] = (string)($task['status'] ?? '');
            if (!empty($task['list_id'])) {
                $ctx['list_id'] = (int)$task['list_id'];
            }
            if (!empty($task['project_id'])) {
                $ctx['project_id'] = (int)$task['project_id'];
            }
            if (!empty($task['directory_project_name'])) {
                $ctx['project_name'] = (string)$task['directory_project_name'];
            }
            if (!empty($task['project'])) {
                $ctx['project_slug'] = (string)$task['project'];
            }
        }
    }

    if (!empty($ctx['project_id'])) {
        $projectId = (int)$ctx['project_id'];
        $proj = getDirectoryProjectById($projectId);
        if (!$proj || !userCanAccessDirectoryProject($viewerUser, $proj)) {
            unset($ctx['project_id'], $ctx['project_name']);
        } else {
            $ctx['project_id'] = $projectId;
            $ctx['project_name'] = (string)($proj['name'] ?? '');
            $ctx['org_id'] = (int)($proj['org_id'] ?? 0);
        }
    }

    if (!empty($ctx['document_id'])) {
        $docId = (int)$ctx['document_id'];
        $doc = getDocumentById($docId, false);
        if (!$doc || !userCanAccessDocument($viewerUser, $doc)) {
            unset($ctx['document_id'], $ctx['document_title']);
        } else {
            $ctx['document_id'] = $docId;
            $ctx['document_title'] = (string)($doc['title'] ?? '');
            if (!empty($doc['project_id']) && empty($ctx['project_id'])) {
                $pid = (int)$doc['project_id'];
                $proj = getDirectoryProjectById($pid);
                if ($proj && userCanAccessDirectoryProject($viewerUser, $proj)) {
                    $ctx['project_id'] = $pid;
                    $ctx['project_name'] = (string)($proj['name'] ?? '');
                }
            }
            if (!empty($doc['directory_path'])) {
                $ctx['directory_path'] = (string)$doc['directory_path'];
            }
        }
    }

    $ctx['surface'] = $surface;
    $ctx['admin_origin'] = q_bridge_admin_origin();

    return q_bridge_attach_context_links($ctx);
}

/**
 * Plain-text block prepended for Q (Broca → Letta). Not shown in widget UI.
 */
function q_bridge_format_chat_context_block(array $ctx): string {
    if ($ctx === []) {
        return '';
    }
    $lines = ['[Chat context — Sanctum Tasks UI]'];
    $lines[] = 'Note: IDs and titles only — use get-document / get-task tools to load full body when needed.';

    $origin = (string)($ctx['admin_origin'] ?? q_bridge_admin_origin());
    if ($origin !== '') {
        $lines[] = 'Admin origin: ' . $origin;
    }

    $surface = (string)($ctx['surface'] ?? 'unknown');
    $surfaceLabels = [
        'home' => 'Home (projects & tasks)',
        'project' => 'Project board',
        'task' => 'Task detail',
        'document' => 'Document',
        'docs' => 'Docs library',
        'doc_create' => 'New document form',
        'task_create' => 'New task form',
        'projects' => 'Projects list',
        'tasks' => 'Tasks',
        'activity' => 'Activity feed',
        'settings' => 'Settings',
        'admin' => 'Admin / settings page',
        'unknown' => 'Unknown page',
    ];
    $screen = $surfaceLabels[$surface] ?? $surface;
    if ($surface === 'admin' && !empty($ctx['admin_page'])) {
        $screen .= ' (' . (string)$ctx['admin_page'] . ')';
    }
    $lines[] = 'Screen: ' . $screen;

    if (!empty($ctx['project_id'])) {
        $name = (string)($ctx['project_name'] ?? 'Project');
        $lines[] = 'Project: ' . $name . ' (project_id=' . (int)$ctx['project_id'] . ')';
        if (!empty($ctx['project_link'])) {
            $lines[] = 'Project link: ' . (string)$ctx['project_link'];
        }
        if (!empty($ctx['tab'])) {
            $lines[] = 'Project tab: ' . (string)$ctx['tab'];
        }
    }
    if (!empty($ctx['list_id'])) {
        $lines[] = 'Todo list: list_id=' . (int)$ctx['list_id'];
    }
    if (!empty($ctx['task_id'])) {
        $title = (string)($ctx['task_title'] ?? 'Task');
        $status = (string)($ctx['task_status'] ?? '');
        $line = 'Task: task_id=' . (int)$ctx['task_id'] . ' — ' . $title;
        if ($status !== '') {
            $line .= ' [status=' . $status . ']';
        }
        $lines[] = $line;
        if (!empty($ctx['task_link'])) {
            $lines[] = 'Task link: ' . (string)$ctx['task_link'];
        }
        $lines[] = 'Tool: get-task --task-id ' . (int)$ctx['task_id'];
    }
    if (!empty($ctx['document_id'])) {
        $title = (string)($ctx['document_title'] ?? 'Document');
        $lines[] = 'Document: document_id=' . (int)$ctx['document_id'] . ' — ' . $title;
        if (!empty($ctx['document_link'])) {
            $lines[] = 'Document link: ' . (string)$ctx['document_link'];
        }
        $lines[] = 'Tool: get-document --id ' . (int)$ctx['document_id'];
    }
    if (!empty($ctx['directory_path'])) {
        $lines[] = 'Docs folder: ' . (string)$ctx['directory_path'];
    }
    if (!empty($ctx['page_title'])) {
        $lines[] = 'Page title: ' . (string)$ctx['page_title'];
    }
    if (!empty($ctx['url'])) {
        $lines[] = 'Browser path: ' . (string)$ctx['url'];
        if (!empty($ctx['page_link'])) {
            $lines[] = 'Page link: ' . (string)$ctx['page_link'];
        }
    }

    $lines[] = 'Prefer these ids over guessing. If the user means "this page", use the task_id / document_id / project_id above.';

    return implode("\n", $lines);
}
