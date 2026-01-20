<?php
require_once __DIR__ . '/config.php';

// Ensure tables exist
initializeDatabase();

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: /admin/login.php');
        exit();
    }
}

function login($username, $password) {
    $db = getDbConnection();

    $stmt = $db->prepare("SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();
        return ['success' => true];
    }

    return ['success' => false, 'error' => 'Invalid username or password'];
}

function logout() {
    session_destroy();
    $_SESSION = [];
    return true;
}

function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id, username, role, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', (int)$_SESSION['user_id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC) ?: null;
}

function changePassword($userId, $currentPassword, $newPassword) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', (int)$userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Current password is incorrect'];
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
    $update = $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
    $update->bindValue(':hash', $newHash, SQLITE3_TEXT);
    $update->bindValue(':id', (int)$userId, SQLITE3_INTEGER);
    $update->execute();

    return ['success' => true];
}

