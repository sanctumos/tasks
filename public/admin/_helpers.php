<?php
/**
 * Shared admin view helpers (UI-only, no DB).
 */

if (!function_exists('st_status_kind')) {
    /**
     * Map a task_statuses row (or just slug+is_done) to a CSS modifier we use
     * for status pills and swimlanes.
     */
    function st_status_kind(array $status): string {
        if ((int)($status['is_done'] ?? 0) === 1) {
            return 'done';
        }
        $slug = strtolower((string)($status['slug'] ?? ''));
        if ($slug === '') return 'default';
        if (preg_match('/(progress|doing|active|review)/', $slug)) return 'doing';
        if (preg_match('/block/', $slug)) return 'blocked';
        if (preg_match('/(todo|to_do|backlog|new|open|pending)/', $slug)) return 'todo';
        return 'default';
    }
}

if (!function_exists('st_status_pill_html')) {
    /**
     * Render a status pill for a task row. Accepts the task array
     * (which has status, status_label, status_is_done) and the full status map
     * keyed by slug (for fallback labels and is_done).
     */
    function st_status_pill_html(array $task, array $statusMap = []): string {
        $slug = (string)($task['status'] ?? '');
        $label = (string)($task['status_label'] ?? $statusMap[$slug]['label'] ?? $slug);
        $isDone = (int)($task['status_is_done'] ?? ($statusMap[$slug]['is_done'] ?? 0));
        $kind = st_status_kind(['slug' => $slug, 'is_done' => $isDone]);
        return '<span class="status-pill status-pill--' . htmlspecialchars($kind) . '">' . htmlspecialchars($label ?: $slug) . '</span>';
    }
}

if (!function_exists('st_priority_chip_html')) {
    function st_priority_chip_html(string $priority): string {
        $p = strtolower(trim($priority)) ?: 'normal';
        $allowed = ['low', 'normal', 'high', 'urgent'];
        if (!in_array($p, $allowed, true)) $p = 'normal';
        $icon = [
            'low' => 'bi-arrow-down',
            'normal' => 'bi-dash',
            'high' => 'bi-arrow-up',
            'urgent' => 'bi-exclamation',
        ][$p];
        return '<span class="priority-chip priority-chip--' . $p . '"><i class="bi ' . $icon . '"></i>' . $p . '</span>';
    }
}

if (!function_exists('st_signal_icons_html')) {
    function st_signal_icons_html(array $task): string {
        $c = (int)($task['comment_count'] ?? 0);
        $a = (int)($task['attachment_count'] ?? 0);
        $w = (int)($task['watcher_count'] ?? 0);
        if ($c === 0 && $a === 0 && $w === 0) {
            return '';
        }
        $parts = ['<span class="icon-stats">'];
        if ($c > 0) $parts[] = '<span title="Comments"><i class="bi bi-chat-text"></i>' . $c . '</span>';
        if ($a > 0) $parts[] = '<span title="Attachments"><i class="bi bi-paperclip"></i>' . $a . '</span>';
        if ($w > 0) $parts[] = '<span title="Watchers"><i class="bi bi-eye"></i>' . $w . '</span>';
        $parts[] = '</span>';
        return implode('', $parts);
    }
}

if (!function_exists('st_relative_time')) {
    function st_relative_time(?string $iso): string {
        if (!$iso) return '';
        try {
            $dt = new DateTime($iso, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return htmlspecialchars((string)$iso);
        }
        $diff = time() - $dt->getTimestamp();
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return (int)floor($diff / 60) . 'm ago';
        if ($diff < 86400) return (int)floor($diff / 3600) . 'h ago';
        if ($diff < 86400 * 7) return (int)floor($diff / 86400) . 'd ago';
        return $dt->format('M j');
    }
}

if (!function_exists('st_is_ajax')) {
    function st_is_ajax(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest') return true;
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return strpos($accept, 'application/json') !== false;
    }
}

if (!function_exists('st_back_link')) {
    function st_back_link(string $href, string $label): string {
        return '<a class="page-back" href="' . htmlspecialchars($href) . '"><i class="bi bi-chevron-left"></i><span>' . htmlspecialchars($label) . '</span></a>';
    }
}

if (!function_exists('st_initials')) {
    /**
     * Two-letter avatar initials for a username (or "?" fallback).
     */
    function st_initials(?string $username): string {
        $u = trim((string)$username);
        if ($u === '') return '?';
        $parts = preg_split('/[\s._\-]+/u', $u, -1, PREG_SPLIT_NO_EMPTY) ?: [$u];
        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
}

if (!function_exists('st_avatar_color')) {
    /**
     * Stable hue (0-359) derived from username so each user has a distinct
     * but pastel avatar bubble. Used by the comment thread + watcher chips.
     */
    function st_avatar_color(?string $username): int {
        $u = (string)$username;
        if ($u === '') return 220;
        $hash = 0;
        for ($i = 0, $n = strlen($u); $i < $n; $i++) {
            $hash = (int)((($hash << 5) - $hash) + ord($u[$i])) & 0xffffffff;
        }
        return abs($hash) % 360;
    }
}

if (!function_exists('st_avatar_html')) {
    function st_avatar_html(?string $username, string $sizeClass = ''): string {
        $hue = st_avatar_color($username);
        $initials = st_initials($username);
        $cls = 'st-avatar' . ($sizeClass !== '' ? ' ' . $sizeClass : '');
        $style = "background:hsl({$hue}deg 60% 88%);color:hsl({$hue}deg 45% 28%);";
        return '<span class="' . $cls . '" style="' . $style . '" aria-hidden="true">' . htmlspecialchars($initials) . '</span>';
    }
}

if (!function_exists('st_format_comment_body')) {
    /**
     * Escape user input, preserve newlines, and auto-link bare URLs.
     * No raw HTML is ever inserted from the comment body.
     */
    function st_format_comment_body(string $raw): string {
        $escaped = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        $linked = preg_replace_callback(
            '~(?<![">])\b((?:https?://|www\.)[^\s<]+[^\s<.,;:!?\'")\]])~i',
            function ($m) {
                $url = $m[1];
                $href = (stripos($url, 'http') === 0) ? $url : 'http://' . $url;
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>';
            },
            $escaped
        );
        return nl2br($linked, false);
    }
}

if (!function_exists('st_absolute_time_attr')) {
    /**
     * Title attribute string showing the absolute UTC timestamp for hover.
     */
    function st_absolute_time_attr(?string $iso): string {
        if (!$iso) return '';
        try {
            $dt = new DateTime($iso, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i') . ' UTC';
        } catch (Exception $e) {
            return (string)$iso;
        }
    }
}

if (!function_exists('st_admin_breadcrumbs')) {
    /**
     * Bootstrap-style breadcrumb trail for admin UI.
     * Each item: ['label' => string, 'href' => optional string]. Omit href on the current page.
     */
    function st_admin_breadcrumbs(array $items): string {
        if ($items === []) {
            return '';
        }
        $parts = [
            '<nav class="admin-breadcrumb-nav" aria-label="Breadcrumb">',
            '<ol class="breadcrumb admin-breadcrumb mb-3">',
        ];
        $n = count($items);
        $i = 0;
        foreach ($items as $it) {
            $i++;
            $label = htmlspecialchars((string)($it['label'] ?? ''));
            $href = isset($it['href']) ? trim((string)$it['href']) : '';
            $isLast = $i === $n;
            if ($isLast) {
                $parts[] = '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
            } elseif ($href !== '') {
                $parts[] = '<li class="breadcrumb-item"><a href="' . htmlspecialchars($href) . '">' . $label . '</a></li>';
            } else {
                $parts[] = '<li class="breadcrumb-item">' . $label . '</li>';
            }
        }
        $parts[] = '</ol></nav>';
        return implode('', $parts);
    }
}
