<?php
/**
 * In-app documentation: URLs and anchors for public/docs/user-guide.md (must stay in sync
 * with ## headings in that file). Used by /admin/documentation.php and st_doc_help().
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
     * Logical keys for st_doc_help() → exact ## line text in public/docs/user-guide.md.
     */
    function st_doc_section_titles(): array {
        return [
            'overview' => 'Overview',
            'home' => 'Home and cross-project tasks',
            'projects' => 'Projects and workspace',
            'project-lists' => 'Lists and to-do lists',
            'task-detail' => 'Task detail page',
            'documents' => 'Documents (per project)',
            'images-attachments' => 'Images and attachments',
            'mentions-markdown' => 'Mentions and markdown',
            'filters' => 'Search and filters',
            'users-access' => 'Users organizations and access',
            'settings-more' => 'Settings password MFA and API keys',
            'api-sdk' => 'API SDK and integrations',
        ];
    }
}

if (!function_exists('st_doc_anchor')) {
    function st_doc_anchor(string $key): ?string {
        $titles = st_doc_section_titles();
        if (!isset($titles[$key])) {
            return null;
        }
        return st_doc_heading_slug($titles[$key]);
    }
}

if (!function_exists('st_doc_url')) {
    function st_doc_url(?string $key = null): string {
        $base = '/admin/documentation.php';
        if ($key === null || $key === '') {
            return $base;
        }
        $frag = st_doc_anchor($key);
        return $frag !== null ? $base . '#' . $frag : $base;
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
