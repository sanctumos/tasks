<?php
/**
 * Save endpoint for documents. Accepts both create (no `id`) and update
 * (with `id`). Mirrors /admin/update.php for tasks. CSRF + session-auth.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/docs.php');
    exit;
}

requireCsrfToken();

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit;
}

if (!empty($_POST['doc_public_share_save'])) {
    $sid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($sid <= 0) {
        $_SESSION['admin_flash_error'] = 'Invalid document.';
        header('Location: /admin/docs.php');
        exit;
    }
    $existingShare = getDocumentById($sid, false);
    if (!$existingShare || !userCanAccessDocument($currentUser, $existingShare)) {
        $_SESSION['admin_flash_error'] = 'Document not found.';
        header('Location: /admin/docs.php');
        exit;
    }
    if (!userCanManageDocument($currentUser, $existingShare)) {
        $_SESSION['admin_flash_error'] = 'You do not have permission to change public sharing.';
        header('Location: /admin/doc.php?id=' . $sid);
        exit;
    }
    $wantEnabled = isset($_POST['public_link_enabled']);
    $rotate = isset($_POST['rotate_public_link']);
    $shareRes = documentSetPublicSharing($sid, (int)$currentUser['id'], $wantEnabled, $rotate && $wantEnabled);
    if (!empty($shareRes['success'])) {
        $_SESSION['admin_flash_success'] = $wantEnabled
            ? ('Public link ' . ($rotate ? 'saved (new link issued).' : 'enabled — copy the URL from the sidebar.'))
            : 'Public link disabled — the old URL no longer works.';
    } else {
        $_SESSION['admin_flash_error'] = $shareRes['error'] ?? 'Could not update public link.';
    }
    header('Location: /admin/doc.php?id=' . $sid);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$title = (string)($_POST['title'] ?? '');
$body = $_POST['body'] ?? null;
$directoryPath = (string)($_POST['directory_path'] ?? '');
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

if ($id > 0) {
    $existing = getDocumentById($id, false);
    if (!$existing || !userCanAccessDocument($currentUser, $existing)) {
        $_SESSION['admin_flash_error'] = 'Document not found.';
        header('Location: /admin/docs.php');
        exit;
    }
    if (!userCanManageDocument($currentUser, $existing)) {
        $_SESSION['admin_flash_error'] = 'You do not have permission to edit this document.';
        header('Location: /admin/doc.php?id=' . $id);
        exit;
    }
    $fields = [];
    if (array_key_exists('title', $_POST)) $fields['title'] = $title;
    if (array_key_exists('body', $_POST)) $fields['body'] = $body;
    if (array_key_exists('directory_path', $_POST)) $fields['directory_path'] = $directoryPath;
    if ($projectId > 0 && $projectId !== (int)$existing['project_id']) {
        $newProj = getDirectoryProjectById($projectId);
        if ($newProj && userCanAccessDirectoryProject($currentUser, $newProj)) {
            $fields['project_id'] = $projectId;
        }
    }
    $res = updateDocument($id, $fields, (int)$currentUser['id']);
    if (!empty($res['success'])) {
        $_SESSION['admin_flash_success'] = 'Document updated.';
    } else {
        $_SESSION['admin_flash_error'] = $res['error'] ?? 'Update failed.';
    }
    header('Location: /admin/doc.php?id=' . $id);
    exit;
}

if ($projectId <= 0) {
    $_SESSION['admin_flash_error'] = 'Pick a project for this document.';
    header('Location: /admin/doc-create.php');
    exit;
}

$enableSharing = isset($_POST['public_link_enabled']);
$res = createDocument((int)$currentUser['id'], $projectId, $title, is_string($body) ? $body : null, $directoryPath, $enableSharing);
if (!empty($res['success'])) {
    $_SESSION['admin_flash_success'] = 'Document created.';
    header('Location: /admin/doc.php?id=' . (int)$res['id']);
    exit;
}

$_SESSION['admin_flash_error'] = $res['error'] ?? 'Create failed.';
header('Location: /admin/doc-create.php' . ($projectId > 0 ? '?project_id=' . $projectId : ''));
exit;
