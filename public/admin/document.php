<?php
/**
 * Legacy alias — older task/doc markdown used /admin/document.php?id=
 * Canonical: /admin/doc.php?id=
 */
declare(strict_types=1);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$query = $id > 0 ? ('?id=' . $id) : '';
header('Location: /admin/doc.php' . $query, true, 301);
exit;
