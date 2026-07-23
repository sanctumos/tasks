<?php
/**
 * In-app documentation: URLs and anchors for public/docs/*.md (must stay in sync
 * with topic headings). Used by /admin/documentation.php and st_doc_help().
 */

if (!function_exists('st_doc_heading_slug')) {
    function st_doc_heading_slug(string $text): string {
        $text = strtolower(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $s = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $s = trim((string)$s, '-');
        return $s !== '' ? $s : 'section';
    }
}

if (!function_exists('st_doc_section_titles')) {
    /**
     * Help pages available in /admin/documentation.php?page=...
     */
    function st_doc_pages(): array {
        return [
            'start' => [
                'label' => 'Start here',
                'file' => 'user-guide.md',
                'deck' => 'The map of the system: what belongs where, and why it is built that way.',
            ],
            'work' => [
                'label' => 'Doing the work',
                'file' => 'help/work.md',
                'deck' => 'Home, all tasks, task detail, comments, mentions, filters, and the daily loop.',
            ],
            'projects' => [
                'label' => 'Projects and archives',
                'file' => 'help/projects-and-archives.md',
                'deck' => 'Projects, lists, archived boards, ZIP downloads, schedule, activity, and doors.',
            ],
            'docs-files' => [
                'label' => 'Docs and files',
                'file' => 'help/docs-and-files.md',
                'deck' => 'Documents, markdown, images, attachments, public document links, and proof.',
            ],
            'access-settings' => [
                'label' => 'Access and settings',
                'file' => 'help/access-and-settings.md',
                'deck' => 'Users, organizations, project membership, Settings, MFA, API keys, audit, and Ask Q limits.',
            ],
            'automation' => [
                'label' => 'Automation',
                'file' => 'help/automation.md',
                'deck' => 'API, SDK, SMCP/MCP, Q, agent rules, and the line between browser work and automation.',
            ],
        ];
    }

    /**
     * Logical keys for st_doc_help() → page + exact ## line text.
     */
    function st_doc_sections(): array {
        return [
            'overview' => ['page' => 'start', 'title' => 'Start here: what Tasks is'],
            'home' => ['page' => 'work', 'title' => 'Home: current work, not storage'],
            'projects' => ['page' => 'projects', 'title' => 'Projects: the container for real work'],
            'project-lists' => ['page' => 'projects', 'title' => 'Lists, tasks, schedule, doors, docs'],
            'task-detail' => ['page' => 'work', 'title' => 'Task detail: the record of work'],
            'documents' => ['page' => 'docs-files', 'title' => 'Documents: the long shelf'],
            'images-attachments' => ['page' => 'docs-files', 'title' => 'Files, images, and inline proof'],
            'mentions-markdown' => ['page' => 'work', 'title' => 'Markdown, mentions, and diagrams'],
            'filters' => ['page' => 'work', 'title' => 'Search, filters, and all tasks'],
            'users-access' => ['page' => 'access-settings', 'title' => 'Users, organizations, and access'],
            'settings-more' => ['page' => 'access-settings', 'title' => 'Settings: the controls behind the work'],
            'schedule' => ['page' => 'projects', 'title' => 'Schedule, activity, and doors'],
            'api-sdk' => ['page' => 'automation', 'title' => 'API, SDK, SMCP, and Q'],
            'archives' => ['page' => 'projects', 'title' => 'Archived boards and ZIP downloads'],
        ];
    }
}

if (!function_exists('st_doc_section_titles')) {
    function st_doc_section_titles(): array {
        $out = [];
        foreach (st_doc_sections() as $key => $row) {
            $out[$key] = $row['title'];
        }
        return $out;
    }
}

if (!function_exists('st_doc_anchor')) {
    function st_doc_anchor(string $key): ?string {
        $sections = st_doc_sections();
        if (!isset($sections[$key])) {
            return null;
        }
        return st_doc_heading_slug($sections[$key]['title']);
    }
}

if (!function_exists('st_doc_url')) {
    function st_doc_url(?string $key = null): string {
        $base = '/admin/documentation.php';
        if ($key === null || $key === '') {
            return $base;
        }
        $sections = st_doc_sections();
        $page = $sections[$key]['page'] ?? 'start';
        $frag = st_doc_anchor($key);
        $url = $page === 'start' ? $base : $base . '?page=' . urlencode($page);
        return $frag !== null ? $url . '#' . $frag : $url;
    }
}

/**
 * Inject stable ids on h2/h3 in Parsedown HTML (same slug algorithm as st_doc_anchor).
 */
if (!function_exists('st_doc_inject_heading_ids')) {
function st_doc_inject_heading_ids(string $html): string {
    $used = [];
    return preg_replace_callback('/<h([23])>([^<]+)<\/h\\1>/', function ($m) use (&$used) {
        $level = $m[1];
        $text = $m[2];
        $slug = st_doc_heading_slug($text);
        $base = $slug;
        $n = 2;
        while (isset($used[$slug])) {
            $slug = $base . '-' . $n;
            $n++;
        }
        $used[$slug] = true;
        return '<h' . $level . ' id="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">' . $text . '</h' . $level . '>';
    }, $html) ?? $html;
}
}

if (!function_exists('st_doc_tooltip_for_key')) {
    function st_doc_tooltip_for_key(string $key): string {
        $titles = st_doc_section_titles();
        if (isset($titles[$key])) {
            return 'Help: ' . $titles[$key];
        }
        return 'Open documentation';
    }
}

if (!function_exists('st_doc_help')) {
    /**
     * Inline help control: links to /admin/documentation.php#anchor with tooltip.
     */
    function st_doc_help(string $key, ?string $tooltip = null): string {
        $url = htmlspecialchars(st_doc_url($key), ENT_QUOTES, 'UTF-8');
        $tip = htmlspecialchars($tooltip ?? st_doc_tooltip_for_key($key), ENT_QUOTES, 'UTF-8');
        return '<a class="st-doc-help" href="' . $url . '" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $tip
            . '" aria-label="' . $tip . '"><i class="bi bi-question-circle-fill" aria-hidden="true"></i></a>';
    }
}
