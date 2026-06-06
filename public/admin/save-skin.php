<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/skin-lab-env.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

requireAuth();
requireCsrfToken();

$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$slug = skinLabNormalizeSlug($_POST['skin_slug'] ?? '');
if ($slug === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid skin slug']);
    exit;
}

$db = getDbConnection();
$stmt = $db->prepare('UPDATE users SET skin_slug = :s WHERE id = :id');
$stmt->bindValue(':s', $slug, SQLITE3_TEXT);
$stmt->bindValue(':id', (int)$user['id'], SQLITE3_INTEGER);
$stmt->execute();

echo json_encode(['success' => true, 'skin_slug' => $slug]);
