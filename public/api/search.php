<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('method.not_allowed', 'Use GET for this endpoint', 405);
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

$result = searchOmniboxForUser($user, $q, $limit);
if (isset($result['success']) && $result['success'] === false) {
    $http = (int)($result['http'] ?? 400);
    apiError('validation.invalid_q', (string)($result['error'] ?? 'Invalid q'), $http);
}

apiSuccess($result);
