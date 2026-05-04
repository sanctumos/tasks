<?php
/**
 * Legacy redirect: workspace-project.php -> project.php (v2 with tabs).
 * Preserves bookmarks and existing links from index.php / api responses.
 */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$qs = '';
foreach ($_GET as $k => $v) {
    if ($k === 'id') continue;
    $qs .= '&' . urlencode((string)$k) . '=' . urlencode((string)$v);
}
header('Location: /admin/project.php?id=' . $id . $qs, true, 302);
exit;
