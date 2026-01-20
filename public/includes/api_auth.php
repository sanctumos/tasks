<?php
require_once __DIR__ . '/functions.php';

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function readJsonBody() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded;
}

function getApiKeyFromRequest() {
    // Primary: X-API-Key
    if (isset($_SERVER['HTTP_X_API_KEY']) && trim($_SERVER['HTTP_X_API_KEY']) !== '') {
        return trim($_SERVER['HTTP_X_API_KEY']);
    }
    // Fallback (debug/local tooling): querystring
    if (isset($_GET['api_key']) && trim($_GET['api_key']) !== '') {
        return trim($_GET['api_key']);
    }
    return null;
}

function requireApiUser() {
    initializeDatabase();

    $apiKey = getApiKeyFromRequest();
    if (!$apiKey) {
        jsonResponse(['success' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    $user = validateApiKeyAndGetUser($apiKey);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    return $user; // {id, username, role, created_at}
}

