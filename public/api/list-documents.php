<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
if ($projectId !== null && $projectId <= 0) {
    apiError('validation.invalid_project_id', 'Invalid project_id', 400);
}
$directoryPath = isset($_GET['directory_path']) ? normalizeDocumentDirectoryPath((string)$_GET['directory_path']) : null;

$documents = listDocumentsForUser($user, $limit, $projectId);
$documents = array_values(array_filter(
    $documents,
    static function (array $d) use ($directoryPath): bool {
        if ($directoryPath === null) return true;
        return normalizeDocumentDirectoryPath((string)($d['directory_path'] ?? '')) === $directoryPath;
    }
));

$documents = array_map(
    static function (array $d): array {
        return sanitizeDocumentForApiPayload($d);
    },
    $documents
);

apiSuccess([
    'documents' => $documents,
    'count' => count($documents),
]);
