<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$pins = listUserProjectPinsForUser($user, $limit);
apiSuccess(['pins' => $pins]);
