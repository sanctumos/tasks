<?php
/**
 * Legacy alias — some markdown used /admin/task.php?id=
 * Canonical: /admin/view.php?id=
 */
declare(strict_types=1);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$query = $id > 0 ? ('?id=' . $id) : '';
header('Location: /admin/view.php' . $query, true, 301);
exit;
