<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();
$uid = (int)$user['id'];

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;
$beforeId = $beforeId > 0 ? $beforeId : null;
$unreadOnly = isset($_GET['unread_only']) && ($_GET['unread_only'] === '1' || strtolower((string)$_GET['unread_only']) === 'true');

$bundle = listNotificationsForUser($uid, $limit, $beforeId, $unreadOnly);
$unread = countUnreadNotifications($uid);

apiSuccess([
    'notifications' => $bundle['notifications'],
    'next_before_id' => $bundle['next_before_id'],
    'unread_count' => $unread,
]);
