<?php
/**
 * Archived directory-project board ZIP export (async jobs + Tasks-style HTML snapshot).
 * Snapshot index matches the live Lists tab (checklists by to-do list); tasks.html
 * matches the status swimlanes; docs.html matches the Docs tab.
 */

require_once __DIR__ . '/config.php';

/** Bump when the snapshot HTML renderer changes so old flat ZIPs are not reused. */
const BOARD_EXPORT_SNAPSHOT_VERSION = '3-lists-open';

function boardExportStorageRoot(): string
{
    $root = rtrim((string)TASKS_BOARD_EXPORT_DIR, '/\\');
    ensureDirExists($root);
    return $root;
}

function boardExportAbsolutePath(string $storageRelPath): ?string
{
    $rel = ltrim(str_replace('\\', '/', $storageRelPath), '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return null;
    }
    $root = boardExportStorageRoot();
    $rootReal = realpath($root);
    if ($rootReal === false) {
        return null;
    }
    $abs = $root . '/' . $rel;
    // Parent may not exist yet for a new export file — create then re-check containment.
    ensureDirExists(dirname($abs));
    $dirReal = realpath(dirname($abs));
    if ($dirReal === false || !str_starts_with($dirReal, $rootReal)) {
        return null;
    }
    return $abs;
}

/**
 * Access + archived gate for board export features.
 *
 * @return array{ok:true}|array{ok:false,error:string,http:int}
 */
function boardExportAccessGate(array $userRow, ?array $project): array
{
    if (!$project) {
        return ['ok' => false, 'error' => 'Project not found', 'http' => 404];
    }
    if (!userCanAccessDirectoryProject($userRow, $project)) {
        return ['ok' => false, 'error' => 'Project not found', 'http' => 404];
    }
    if (($project['status'] ?? '') !== 'archived') {
        return ['ok' => false, 'error' => 'Board exports are only available after the project is archived', 'http' => 400];
    }
    return ['ok' => true];
}

function getBoardExportJobById(int $jobId): ?array
{
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT * FROM project_board_exports WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $jobId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function listBoardExportJobsForProject(int $projectId, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT e.*, u.username AS requested_by_username
        FROM project_board_exports e
        JOIN users u ON u.id = e.requested_by_user_id
        WHERE e.project_id = :pid
        ORDER BY e.id DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Fingerprint of board content that would land in a ZIP snapshot.
 * Same hash ⇒ no need to build another archive file.
 */
function boardExportContentFingerprint(int $projectId): string
{
    $db = getDbConnection();
    $parts = [];
    $parts[] = 'snapshot|' . BOARD_EXPORT_SNAPSHOT_VERSION;

    $proj = $db->prepare('SELECT id, name, description, status, updated_at FROM projects WHERE id = :id LIMIT 1');
    $proj->bindValue(':id', $projectId, SQLITE3_INTEGER);
    $prow = $proj->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$prow) {
        return hash('sha256', 'missing-project:' . $projectId);
    }
    $parts[] = 'project|' . implode('|', [
        (string)$prow['id'],
        (string)$prow['name'],
        (string)($prow['description'] ?? ''),
        (string)$prow['status'],
        (string)($prow['updated_at'] ?? ''),
    ]);

    $lists = $db->prepare('SELECT id, name, sort_order, created_at FROM todo_lists WHERE project_id = :p ORDER BY id ASC');
    $lists->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $lr = $lists->execute();
    while ($row = $lr->fetchArray(SQLITE3_ASSOC)) {
        $parts[] = 'list|' . implode('|', [
            (string)$row['id'],
            (string)$row['name'],
            (string)$row['sort_order'],
            (string)($row['created_at'] ?? ''),
        ]);
    }

    $tasks = $db->prepare("
        SELECT id, title, body, status, priority, due_at, list_id, assigned_to_user_id,
               tags_json, rank, recurrence_rule, updated_at
        FROM tasks
        WHERE project_id = :p
        ORDER BY id ASC
    ");
    $tasks->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $tr = $tasks->execute();
    $taskIds = [];
    while ($row = $tr->fetchArray(SQLITE3_ASSOC)) {
        $taskIds[] = (int)$row['id'];
        $parts[] = 'task|' . implode('|', [
            (string)$row['id'],
            (string)$row['title'],
            (string)($row['body'] ?? ''),
            (string)$row['status'],
            (string)$row['priority'],
            (string)($row['due_at'] ?? ''),
            (string)($row['list_id'] ?? ''),
            (string)($row['assigned_to_user_id'] ?? ''),
            (string)($row['tags_json'] ?? ''),
            (string)$row['rank'],
            (string)($row['recurrence_rule'] ?? ''),
            (string)($row['updated_at'] ?? ''),
        ]);
    }

    if ($taskIds !== []) {
        $in = implode(',', array_map('intval', $taskIds));
        $cr = $db->query("
            SELECT id, task_id, comment, created_at
            FROM task_comments
            WHERE task_id IN ({$in})
            ORDER BY id ASC
        ");
        if ($cr) {
            while ($row = $cr->fetchArray(SQLITE3_ASSOC)) {
                $parts[] = 'tcomment|' . implode('|', [
                    (string)$row['id'],
                    (string)$row['task_id'],
                    (string)$row['comment'],
                    (string)($row['created_at'] ?? ''),
                ]);
            }
        }
        $ar = $db->query("
            SELECT id, task_id, file_name, file_url, mime_type, size_bytes, storage_kind, storage_rel_path, created_at
            FROM task_attachments
            WHERE task_id IN ({$in})
            ORDER BY id ASC
        ");
        if ($ar) {
            while ($row = $ar->fetchArray(SQLITE3_ASSOC)) {
                $parts[] = 'att|' . implode('|', [
                    (string)$row['id'],
                    (string)$row['task_id'],
                    (string)$row['file_name'],
                    (string)($row['file_url'] ?? ''),
                    (string)($row['mime_type'] ?? ''),
                    (string)($row['size_bytes'] ?? ''),
                    (string)($row['storage_kind'] ?? ''),
                    (string)($row['storage_rel_path'] ?? ''),
                    (string)($row['created_at'] ?? ''),
                ]);
            }
        }
    }

    $docs = $db->prepare("
        SELECT id, title, body, status, directory_path, updated_at
        FROM documents
        WHERE project_id = :p AND status != 'trashed'
        ORDER BY id ASC
    ");
    $docs->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $dr = $docs->execute();
    $docIds = [];
    while ($row = $dr->fetchArray(SQLITE3_ASSOC)) {
        $docIds[] = (int)$row['id'];
        $parts[] = 'doc|' . implode('|', [
            (string)$row['id'],
            (string)$row['title'],
            (string)($row['body'] ?? ''),
            (string)$row['status'],
            (string)($row['directory_path'] ?? ''),
            (string)($row['updated_at'] ?? ''),
        ]);
    }
    if ($docIds !== []) {
        $din = implode(',', array_map('intval', $docIds));
        $dcr = $db->query("
            SELECT id, document_id, comment, created_at
            FROM document_comments
            WHERE document_id IN ({$din})
            ORDER BY id ASC
        ");
        if ($dcr) {
            while ($row = $dcr->fetchArray(SQLITE3_ASSOC)) {
                $parts[] = 'dcomment|' . implode('|', [
                    (string)$row['id'],
                    (string)$row['document_id'],
                    (string)$row['comment'],
                    (string)($row['created_at'] ?? ''),
                ]);
            }
        }
    }

    return hash('sha256', implode("\n", $parts));
}

/**
 * Latest ready export whose ZIP file still exists on disk.
 */
function getLatestReadyBoardExportWithFile(int $projectId): ?array
{
    $jobs = listBoardExportJobsForProject($projectId, 50);
    foreach ($jobs as $job) {
        if (($job['status'] ?? '') !== 'ready') {
            continue;
        }
        $rel = (string)($job['storage_rel_path'] ?? '');
        if ($rel === '') {
            continue;
        }
        $abs = boardExportAbsolutePath($rel);
        if ($abs !== null && is_file($abs)) {
            return $job;
        }
    }
    return null;
}

/**
 * @return array{success:bool,id?:int,error?:string,reused?:bool,unchanged?:bool,status?:string}
 */
function requestBoardExportJob(int $actorUserId, int $projectId): array
{
    $user = getUserById($actorUserId, false);
    $project = getDirectoryProjectById($projectId);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }
    $gate = boardExportAccessGate($user, $project);
    if (empty($gate['ok'])) {
        return ['success' => false, 'error' => (string)($gate['error'] ?? 'Denied')];
    }

    // Avoid stacking identical pending/running jobs for the same requester.
    $db = getDbConnection();
    $chk = $db->prepare("
        SELECT id, status FROM project_board_exports
        WHERE project_id = :pid AND requested_by_user_id = :uid
          AND status IN ('pending', 'running')
        ORDER BY id DESC LIMIT 1
    ");
    $chk->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $chk->bindValue(':uid', $actorUserId, SQLITE3_INTEGER);
    $existing = $chk->execute()->fetchArray(SQLITE3_ASSOC);
    if ($existing) {
        return [
            'success' => true,
            'id' => (int)$existing['id'],
            'reused' => true,
            'status' => (string)$existing['status'],
        ];
    }

    $fingerprint = boardExportContentFingerprint($projectId);
    $latestReady = getLatestReadyBoardExportWithFile($projectId);
    if ($latestReady !== null) {
        $prevHash = (string)($latestReady['content_hash'] ?? '');
        if ($prevHash !== '' && hash_equals($prevHash, $fingerprint)) {
            createAuditLog($actorUserId, 'project.board_export_reuse_unchanged', 'project_board_export', (string)$latestReady['id'], [
                'project_id' => $projectId,
                'content_hash' => $fingerprint,
            ]);
            return [
                'success' => true,
                'id' => (int)$latestReady['id'],
                'reused' => true,
                'unchanged' => true,
                'status' => 'ready',
            ];
        }
    }

    $ins = $db->prepare("
        INSERT INTO project_board_exports (project_id, requested_by_user_id, status, content_hash, created_at)
        VALUES (:pid, :uid, 'pending', :hash, CURRENT_TIMESTAMP)
    ");
    $ins->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $ins->bindValue(':uid', $actorUserId, SQLITE3_INTEGER);
    $ins->bindValue(':hash', $fingerprint, SQLITE3_TEXT);
    $ins->execute();
    $jobId = (int)$db->lastInsertRowID();
    createAuditLog($actorUserId, 'project.board_export_request', 'project_board_export', (string)$jobId, [
        'project_id' => $projectId,
        'content_hash' => $fingerprint,
    ]);

    boardExportSpawnWorker($jobId);

    return ['success' => true, 'id' => $jobId, 'status' => 'pending'];
}

function boardExportPhpCliBinary(): string
{
    $candidates = [];
    $bin = (string)(PHP_BINARY ?: '');
    // Under php-fpm, PHP_BINARY is the FPM binary and cannot run CLI scripts.
    if ($bin !== '' && stripos($bin, 'fpm') === false && stripos($bin, 'cgi') === false) {
        $candidates[] = $bin;
    }
    foreach ([
        '/usr/bin/php8.3',
        '/usr/bin/php8.2',
        '/usr/bin/php8.1',
        '/usr/bin/php',
        'php',
    ] as $c) {
        $candidates[] = $c;
    }
    foreach ($candidates as $c) {
        if ($c === 'php') {
            return 'php';
        }
        if (is_executable($c)) {
            return $c;
        }
    }
    return 'php';
}

function boardExportSpawnWorker(int $jobId): void
{
    $candidates = [];
    $repoEnv = getenv('TASKS_REPO_ROOT');
    if (is_string($repoEnv) && $repoEnv !== '') {
        $candidates[] = rtrim($repoEnv, '/\\') . '/tools/board-export-worker.php';
        $candidates[] = rtrim($repoEnv, '/\\') . '/public/cli/board-export-worker.php';
    }
    // Repo checkout layout: public/includes → ../../tools
    $candidates[] = dirname(__DIR__, 2) . '/tools/board-export-worker.php';
    // Multihost WEB_ROOT is the public/ tree only — worker ships under public/cli/
    $candidates[] = dirname(__DIR__) . '/cli/board-export-worker.php';

    $worker = null;
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $worker = $path;
            break;
        }
    }
    if ($worker === null) {
        error_log('boardExportSpawnWorker: worker script missing; tried ' . implode(', ', $candidates));
        return;
    }
    $php = boardExportPhpCliBinary();
    $cmd = sprintf(
        'nohup %s %s %d >> %s 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($worker),
        $jobId,
        escapeshellarg(boardExportStorageRoot() . '/worker.log')
    );
    // Pass DB / export env into the worker process.
    $envPrefix = '';
    foreach (['TASKS_DB_PATH', 'TASKS_BOARD_EXPORT_DIR', 'TASKS_ASSET_STORAGE_DIR', 'TASKS_REPO_ROOT'] as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') {
            $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
        }
    }
    exec($envPrefix . $cmd);
}

/**
 * Claim job and build ZIP. Intended for CLI worker.
 *
 * @return array{success:bool,error?:string}
 */
function processBoardExportJob(int $jobId): array
{
    $db = getDbConnection();
    $claim = $db->prepare("
        UPDATE project_board_exports
        SET status = 'running', started_at = CURRENT_TIMESTAMP, error_message = NULL
        WHERE id = :id AND status = 'pending'
    ");
    $claim->bindValue(':id', $jobId, SQLITE3_INTEGER);
    $claim->execute();
    if ($db->changes() < 1) {
        $row = getBoardExportJobById($jobId);
        if (!$row) {
            return ['success' => false, 'error' => 'Job not found'];
        }
        if (($row['status'] ?? '') === 'ready') {
            return ['success' => true];
        }
        if (($row['status'] ?? '') === 'running') {
            // Allow reclaim if stuck — for simplicity continue only if we just claimed.
            return ['success' => false, 'error' => 'Job already running or finished'];
        }
        return ['success' => false, 'error' => 'Job not claimable (status=' . ($row['status'] ?? '') . ')'];
    }

    try {
        $job = getBoardExportJobById($jobId);
        if (!$job) {
            throw new RuntimeException('Job vanished after claim');
        }
        $projectId = (int)$job['project_id'];
        $project = getDirectoryProjectById($projectId);
        if (!$project || ($project['status'] ?? '') !== 'archived') {
            throw new RuntimeException('Project must be archived to export');
        }

        $built = boardExportBuildZipArchive($project, $jobId);
        $hash = (string)($job['content_hash'] ?? '');
        if ($hash === '') {
            $hash = boardExportContentFingerprint($projectId);
        }
        $upd = $db->prepare("
            UPDATE project_board_exports
            SET status = 'ready',
                storage_rel_path = :rel,
                byte_size = :sz,
                content_hash = :hash,
                completed_at = CURRENT_TIMESTAMP,
                error_message = NULL
            WHERE id = :id
        ");
        $upd->bindValue(':rel', $built['rel'], SQLITE3_TEXT);
        $upd->bindValue(':sz', $built['bytes'], SQLITE3_INTEGER);
        $upd->bindValue(':hash', $hash, SQLITE3_TEXT);
        $upd->bindValue(':id', $jobId, SQLITE3_INTEGER);
        $upd->execute();
        return ['success' => true];
    } catch (Throwable $e) {
        $fail = $db->prepare("
            UPDATE project_board_exports
            SET status = 'failed',
                error_message = :err,
                completed_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $fail->bindValue(':err', truncateString($e->getMessage(), 1900), SQLITE3_TEXT);
        $fail->bindValue(':id', $jobId, SQLITE3_INTEGER);
        $fail->execute();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @param array<string,mixed> $project
 * @return array{rel:string,bytes:int}
 */
function boardExportBuildZipArchive(array $project, int $jobId): array
{
    $projectId = (int)$project['id'];
    $slug = boardExportSafeSlug((string)$project['name']);
    $rel = sprintf('project-%d/export-%d-%s.zip', $projectId, $jobId, date('Ymd-His'));
    $abs = boardExportAbsolutePath($rel);
    if ($abs === null) {
        throw new RuntimeException('Invalid export path');
    }
    ensureDirExists(dirname($abs));

    $staging = boardExportStorageRoot() . '/staging-' . $jobId . '-' . bin2hex(random_bytes(4));
    ensureDirExists($staging);
    ensureDirExists($staging . '/assets');

    try {
        $assetMap = boardExportCollectAndCopyAssets($projectId, $staging . '/assets');
        boardExportWriteHtmlPages($project, $staging, $assetMap);

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $local = str_replace('\\', '/', substr($full, strlen($staging) + 1));
            $files[$full] = $local;
        }
        boardExportWriteZipFile($abs, $files);

        clearstatcache(true, $abs);
        $bytes = (int)filesize($abs);
        if ($bytes <= 0) {
            throw new RuntimeException('ZIP empty after build');
        }
        if ($bytes > (int)TASKS_BOARD_EXPORT_MAX_BYTES) {
            @unlink($abs);
            throw new RuntimeException('ZIP exceeds TASKS_BOARD_EXPORT_MAX_BYTES');
        }
        return ['rel' => $rel, 'bytes' => $bytes];
    } finally {
        boardExportRmTree($staging);
    }
}

/**
 * Create a ZIP at $absPath from map of absolute path => archive-local name.
 * Prefers ZipArchive; falls back to store-only pure PHP ZIP (no compression).
 *
 * @param array<string,string> $files
 */
function boardExportWriteZipFile(string $absPath, array $files): void
{
    if ($files === []) {
        throw new RuntimeException('No files to zip');
    }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $full => $local) {
                $zip->addFile($full, $local);
            }
            $zip->close();
            return;
        }
    }

    // Pure-PHP ZIP (stored / method 0) — works without ext-zip.
    $out = fopen($absPath, 'wb');
    if ($out === false) {
        throw new RuntimeException('Could not open ZIP for writing');
    }
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $full => $local) {
        $data = file_get_contents($full);
        if ($data === false) {
            fclose($out);
            throw new RuntimeException('Could not read file for ZIP: ' . $local);
        }
        $name = str_replace('\\', '/', $local);
        $nameLen = strlen($name);
        $size = strlen($data);
        $crc = crc32($data);
        $modTime = boardExportDosTime(filemtime($full) ?: time());

        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $modTime[0], $modTime[1], $crc, $size, $size, $nameLen, 0);
        fwrite($out, $localHeader);
        fwrite($out, $name);
        fwrite($out, $data);

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $modTime[0], $modTime[1], $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset);
        $central .= $name;
        $offset += 30 + $nameLen + $size;
        $count++;
    }
    $centralOffset = $offset;
    fwrite($out, $central);
    $centralSize = strlen($central);
    fwrite($out, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0));
    fclose($out);
}

/**
 * @return array{0:int,1:int} [dosTime, dosDate]
 */
function boardExportDosTime(int $timestamp): array
{
    $d = getdate($timestamp);
    $time = (($d['hours'] & 0x1f) << 11) | (($d['minutes'] & 0x3f) << 5) | (((int)floor($d['seconds'] / 2)) & 0x1f);
    $date = ((($d['year'] - 1980) & 0x7f) << 9) | (($d['mon'] & 0xf) << 5) | ($d['mday'] & 0x1f);
    return [$time, $date];
}

/**
 * @return array<int,string> attachment id => relative path inside ZIP (assets/...)
 */
function boardExportCollectAndCopyAssets(int $projectId, string $assetsDir): array
{
    $db = getDbConnection();
    $ids = [];

    $stmt = $db->prepare("
        SELECT a.id, a.file_name, a.file_url, a.mime_type, a.storage_kind, a.storage_rel_path, a.task_id
        FROM task_attachments a
        INNER JOIN tasks t ON t.id = a.task_id
        WHERE t.project_id = :pid
        ORDER BY a.id ASC
    ");
    $stmt->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[(int)$row['id']] = $row;
        $ids[(int)$row['id']] = true;
    }

    // Sweep markdown embeds in tasks, comments, documents.
    $bodies = [];
    $tq = $db->prepare('SELECT id, title, body FROM tasks WHERE project_id = :pid');
    $tq->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $tr = $tq->execute();
    while ($t = $tr->fetchArray(SQLITE3_ASSOC)) {
        $bodies[] = (string)($t['body'] ?? '');
        $cq = $db->prepare('SELECT comment FROM task_comments WHERE task_id = :tid');
        $cq->bindValue(':tid', (int)$t['id'], SQLITE3_INTEGER);
        $cr = $cq->execute();
        while ($c = $cr->fetchArray(SQLITE3_ASSOC)) {
            $bodies[] = (string)($c['comment'] ?? '');
        }
    }
    $dq = $db->prepare("SELECT id, body FROM documents WHERE project_id = :pid AND status != 'trashed'");
    $dq->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $dr = $dq->execute();
    while ($d = $dr->fetchArray(SQLITE3_ASSOC)) {
        $bodies[] = (string)($d['body'] ?? '');
        $dc = $db->prepare('SELECT comment FROM document_comments WHERE document_id = :did');
        $dc->bindValue(':did', (int)$d['id'], SQLITE3_INTEGER);
        $dcr = $dc->execute();
        while ($c = $dcr->fetchArray(SQLITE3_ASSOC)) {
            $bodies[] = (string)($c['comment'] ?? '');
        }
    }

    foreach ($bodies as $body) {
        if (preg_match_all('/get-asset\\.php\\?[^\\s"\'<>]*\\bid=(\\d+)/i', $body, $m)) {
            foreach ($m[1] as $rawId) {
                $aid = (int)$rawId;
                if ($aid > 0) {
                    $ids[$aid] = true;
                }
            }
        }
    }

    // Load any embed-only attachment rows not already selected via project join.
    foreach (array_keys($ids) as $aid) {
        if (isset($rows[$aid])) {
            continue;
        }
        $one = $db->prepare('SELECT id, file_name, file_url, mime_type, storage_kind, storage_rel_path, task_id FROM task_attachments WHERE id = :id');
        $one->bindValue(':id', $aid, SQLITE3_INTEGER);
        $got = $one->execute()->fetchArray(SQLITE3_ASSOC);
        if ($got) {
            $rows[$aid] = $got;
        }
    }

    $map = [];
    foreach ($rows as $aid => $att) {
        $safeName = boardExportSafeFileName((string)$att['file_name']);
        $ext = pathinfo($safeName, PATHINFO_EXTENSION);
        $base = pathinfo($safeName, PATHINFO_FILENAME);
        $outName = $aid . '-' . $base . ($ext !== '' ? '.' . $ext : '');
        $dest = $assetsDir . '/' . $outName;
        $ok = boardExportMaterializeAttachment($att, $dest);
        if ($ok) {
            $map[$aid] = 'assets/' . $outName;
        } else {
            $note = $dest . '.MISSING.txt';
            file_put_contents(
                $note,
                "Could not include attachment #{$aid} ({$att['file_name']}).\n"
                . 'kind=' . ($att['storage_kind'] ?? '') . "\n"
                . 'url=' . ($att['file_url'] ?? '') . "\n"
            );
            $map[$aid] = 'assets/' . $outName . '.MISSING.txt';
        }
    }
    return $map;
}

/**
 * @param array<string,mixed> $att
 */
function boardExportMaterializeAttachment(array $att, string $destPath): bool
{
    $kind = (string)($att['storage_kind'] ?? 'remote');
    if ($kind === 'local') {
        $rel = (string)($att['storage_rel_path'] ?? '');
        $src = taskAttachmentAbsolutePath($rel);
        if ($src === null || !is_file($src)) {
            return false;
        }
        return @copy($src, $destPath);
    }

    $url = trim((string)($att['file_url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'follow_location' => 1, 'user_agent' => 'SanctumTasksBoardExport/1.0'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || $data === '') {
        return false;
    }
    return file_put_contents($destPath, $data) !== false;
}

/**
 * @param array<string,mixed> $project
 * @param array<int,string> $assetMap
 */
function boardExportSnapshotCss(): string
{
    return <<<'CSS'
:root{
  --st-bg-app:#f4f6fa;--st-bg-surface:#fff;--st-bg-soft:#f8f9fc;
  --st-border-subtle:#e4e7ee;--st-border-strong:#cfd4de;
  --st-text-primary:#14161a;--st-text-secondary:#4a5260;--st-text-muted:#8a93a3;
  --st-accent:#2c5cff;--st-accent-soft:#e9efff;
  --st-radius:10px;--st-radius-sm:6px;--st-gap-stack:.85rem;
  --st-status-todo:#4a5260;--st-status-todo-soft:#eef0f4;
  --st-status-doing:#b45309;--st-status-doing-soft:#fef4d6;
  --st-status-done:#15803d;--st-status-done-soft:#dcfce7;
  --st-status-blocked:#b91c1c;--st-status-blocked-soft:#fee2e2;
  --st-pri-low:#6b7280;--st-pri-low-soft:#eef0f3;
  --st-pri-normal:#2c5cff;--st-pri-normal-soft:#e9efff;
  --st-pri-high:#b45309;--st-pri-high-soft:#fef4d6;
  --st-pri-urgent:#b91c1c;--st-pri-urgent-soft:#fee2e2;
}
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:var(--st-bg-app);color:var(--st-text-primary);line-height:1.45}
.wrap{max-width:1100px;margin:0 auto;padding:1.25rem 1.35rem 2.5rem}
h1{font-size:1.55rem;margin:.2rem 0 .35rem;letter-spacing:-.02em}
h2.h5{font-size:1.05rem;font-weight:650}
.subtitle,.meta{color:var(--st-text-muted);font-size:.9rem}
.page-header{margin-bottom:1rem}
.tabbar{display:flex;flex-wrap:wrap;gap:.25rem;border-bottom:1px solid var(--st-border-subtle);margin-bottom:1rem}
.tabbar a{padding:.55rem .9rem;color:var(--st-text-secondary);text-decoration:none;border-bottom:2px solid transparent;font-weight:500;font-size:.92rem}
.tabbar a:hover{color:var(--st-text-primary)}
.tabbar a.active{color:var(--st-accent);border-bottom-color:var(--st-accent);font-weight:600}
.tabbar a .count{background:var(--st-bg-soft);border:1px solid var(--st-border-subtle);color:var(--st-text-muted);border-radius:999px;padding:0 .45rem;font-size:.7rem;margin-left:.35rem}
.status-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:999px;font-size:.75rem;font-weight:600;border:1px solid transparent}
.status-pill--todo{color:var(--st-status-todo);background:var(--st-status-todo-soft)}
.status-pill--doing{color:var(--st-status-doing);background:var(--st-status-doing-soft)}
.status-pill--done{color:var(--st-status-done);background:var(--st-status-done-soft)}
.status-pill--blocked{color:var(--st-status-blocked);background:var(--st-status-blocked-soft)}
.priority-chip{display:inline-flex;padding:.12rem .45rem;border-radius:var(--st-radius-sm);font-size:.7rem;font-weight:600}
.priority-chip--low{color:var(--st-pri-low);background:var(--st-pri-low-soft)}
.priority-chip--normal{color:var(--st-pri-normal);background:var(--st-pri-normal-soft)}
.priority-chip--high{color:var(--st-pri-high);background:var(--st-pri-high-soft)}
.priority-chip--urgent{color:var(--st-pri-urgent);background:var(--st-pri-urgent-soft)}
.todolist-toolbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem}
.section-title{font-weight:650}
.todo-list{background:var(--st-bg-surface);border:1px solid var(--st-border-subtle);border-radius:var(--st-radius);padding:.9rem 1rem;margin-bottom:var(--st-gap-stack)}
.todo-list__header{display:flex;align-items:center;justify-content:space-between;gap:.75rem;border-bottom:1px solid var(--st-border-subtle);padding-bottom:.55rem;margin-bottom:.65rem}
.todo-list__title{display:flex;align-items:center;gap:.5rem}
.todo-list__progress-pill{background:var(--st-bg-soft);border:1px solid var(--st-border-subtle);color:var(--st-text-secondary);border-radius:999px;padding:.1rem .55rem;font-size:.74rem;font-weight:500}
.todo-list--unfiled,.todo-list--archived{border-style:dashed}
.todo-list__items{list-style:none;padding:0;margin:0 0 .5rem}
.todo-row{display:flex;gap:.65rem;align-items:flex-start;padding:.48rem .35rem;border-top:1px solid var(--st-border-subtle)}
.todo-row:first-child{border-top:0}
.todo-row__body{flex:1;min-width:0}
.todo-row__title{color:var(--st-text-primary);text-decoration:none;font-weight:500}
.todo-row__title:hover{color:var(--st-accent);text-decoration:underline}
.todo-row__meta{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;font-size:.78rem;color:var(--st-text-muted);margin-top:.2rem}
.todo-row--done .todo-row__title{color:var(--st-text-muted);text-decoration:line-through}
.todo-checkbox{width:1.15rem;height:1.15rem;border-radius:50%;border:1.5px solid #94a3b8;background:#fff;flex:0 0 auto;margin-top:.15rem;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;color:#fff}
.todo-checkbox--done{background:var(--st-accent);border-color:var(--st-accent)}
details.todo-list>summary{cursor:pointer;list-style:none}
details.todo-list>summary::-webkit-details-marker{display:none}
.board{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:.9rem;align-items:start}
.swimlane{background:var(--st-bg-soft);border:1px solid var(--st-border-subtle);border-radius:var(--st-radius);padding:.75rem}
.swimlane__header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;padding-bottom:.5rem;border-bottom:1px solid var(--st-border-subtle)}
.swimlane__count{font-size:.75rem;color:var(--st-text-muted);font-weight:500}
.swimlane__body{display:flex;flex-direction:column;gap:.5rem}
.swimlane__empty{color:var(--st-text-muted);font-size:.82rem}
.task-card{display:block;background:var(--st-bg-surface);border:1px solid var(--st-border-subtle);border-radius:var(--st-radius-sm);padding:.78rem .92rem}
.task-card__title{font-weight:600;color:var(--st-text-primary);text-decoration:none;display:block;margin-bottom:.3rem}
.task-card__title:hover{color:var(--st-accent)}
.task-card__meta,.task-card__footer{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;font-size:.78rem;color:var(--st-text-muted)}
.task-card__footer{justify-content:space-between;margin-top:.4rem}
.doc-card{background:var(--st-bg-surface);border:1px solid var(--st-border-subtle);border-radius:var(--st-radius);padding:.85rem 1rem;margin-bottom:.65rem}
.doc-card a{color:var(--st-text-primary);font-weight:600;text-decoration:none}
.doc-card a:hover{color:var(--st-accent)}
.card{border:1px solid var(--st-border-subtle);background:var(--st-bg-surface);padding:1rem;margin:.75rem 0;border-radius:var(--st-radius)}
pre,code{background:var(--st-bg-soft)} img{max-width:100%}
pre.doc-body{white-space:pre-wrap;overflow-wrap:anywhere;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:.92rem;line-height:1.5;padding:.7rem .85rem;border-radius:var(--st-radius-sm);margin:.5rem 0}
.detail-nav{margin-bottom:1rem;font-size:.9rem}
.detail-nav a{color:var(--st-accent);margin-right:1rem}
.todo-list-archived-shelf{border:1px dashed var(--st-border-subtle);border-radius:var(--st-radius);padding:.75rem 1rem;margin-top:1rem;background:var(--st-bg-surface)}
.badge{display:inline-block;background:var(--st-status-done-soft);color:var(--st-status-done);border-radius:999px;padding:.05rem .5rem;font-size:.72rem;font-weight:650}
CSS;
}

function boardExportStatusKind(array $statusOrTask): string
{
    $slug = strtolower((string)($statusOrTask['slug'] ?? $statusOrTask['status'] ?? ''));
    if ((int)($statusOrTask['is_done'] ?? $statusOrTask['status_is_done'] ?? 0) === 1) {
        return 'done';
    }
    if (in_array($slug, ['doing', 'in_progress', 'inprogress'], true)) {
        return 'doing';
    }
    if ($slug === 'blocked') {
        return 'blocked';
    }
    return 'todo';
}

function boardExportWrapPage(string $title, string $css, string $body): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title><style>' . $css . '</style></head>'
        . '<body><div class="wrap">' . $body . '</div></body></html>';
}

function boardExportTabBar(string $active, int $listCount, int $taskCount, int $docCount): string
{
    $tabs = [
        'index.html' => ['Lists', $listCount],
        'tasks.html' => ['Tasks', $taskCount],
        'docs.html' => ['Docs', $docCount],
    ];
    $html = '<nav class="tabbar" aria-label="Project sections">';
    foreach ($tabs as $href => $pair) {
        $cls = $active === $href ? ' active' : '';
        $html .= '<a class="' . trim($cls) . '" href="' . $href . '">'
            . htmlspecialchars($pair[0])
            . '<span class="count">' . (int)$pair[1] . '</span></a>';
    }
    return $html . '</nav>';
}

function boardExportProjectHeader(array $project, string $exportedAt): string
{
    $html = '<header class="page-header"><h1>' . htmlspecialchars((string)$project['name']) . '</h1>';
    $html .= '<div class="subtitle">Archived board snapshot · exported '
        . htmlspecialchars($exportedAt)
        . ' · project_id ' . (int)$project['id'];
    $html .= ' · <span class="status-pill status-pill--todo">' . htmlspecialchars((string)($project['status'] ?? '')) . '</span></div>';
    if (!empty($project['description'])) {
        $html .= '<p class="meta">' . nl2br(htmlspecialchars((string)$project['description'])) . '</p>';
    }
    return $html . '</header>';
}

/**
 * @param list<array<string,mixed>> $tasks
 * @return array{by_list:array<int,list<array<string,mixed>>>,unfiled:list<array<string,mixed>>}
 */
function boardExportGroupTasksByList(array $tasks): array
{
    $byList = [];
    $unfiled = [];
    foreach ($tasks as $t) {
        $lid = (int)($t['list_id'] ?? 0);
        if ($lid <= 0) {
            $unfiled[] = $t;
            continue;
        }
        $byList[$lid][] = $t;
    }
    foreach ($byList as &$rows) {
        usort($rows, static function ($a, $b) {
            $aDone = (int)($a['status_is_done'] ?? 0);
            $bDone = (int)($b['status_is_done'] ?? 0);
            if ($aDone !== $bDone) {
                return $aDone <=> $bDone;
            }
            $ar = (int)($a['rank'] ?? 0);
            $br = (int)($b['rank'] ?? 0);
            if ($ar !== $br) {
                return $ar <=> $br;
            }
            return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });
    }
    unset($rows);
    return ['by_list' => $byList, 'unfiled' => $unfiled];
}

/**
 * @param list<array<string,mixed>> $rows
 */
function boardExportTodoListSectionHtml(array $tl, array $rows, bool $isArchivedShelf = false): string
{
    $listId = (int)$tl['id'];
    $total = count($rows);
    $done = 0;
    foreach ($rows as $r) {
        if ((int)($r['status_is_done'] ?? 0) === 1) {
            $done++;
        }
    }
    $remaining = max(0, $total - $done);
    $allDone = $total > 0 && $remaining === 0;
    $useCollapse = !$isArchivedShelf && $allDone;
    $cls = 'todo-list' . ($isArchivedShelf ? ' todo-list--archived' : '') . ($allDone ? ' todo-list--all-done' : '');
    $tag = $useCollapse ? 'details' : 'section';
    // Archived snapshot: every list starts open so the whole board is visible without clicking.
    $html = '<' . $tag . ' class="' . $cls . '" id="list-' . $listId . '"' . ($useCollapse ? ' open' : '') . '>';
    if ($useCollapse) {
        $html .= '<summary class="todo-list__header">';
    } else {
        $html .= '<header class="todo-list__header">';
    }
    $html .= '<div class="todo-list__title"><h2 class="h5">' . htmlspecialchars((string)$tl['name']) . '</h2>';
    if ($allDone && !$isArchivedShelf) {
        $html .= '<span class="badge">All done</span>';
    }
    $html .= '</div><div class="todo-list__progress">';
    if ($total === 0) {
        $html .= '<span class="meta">empty</span>';
    } else {
        $html .= '<span class="todo-list__progress-pill">' . $done . ' / ' . $total . ' done</span>';
        if ($remaining > 0) {
            $html .= '<span class="meta">' . $remaining . ' remaining</span>';
        }
    }
    $html .= '</div>' . ($useCollapse ? '</summary>' : '</header>');
    if ($total === 0) {
        $html .= '<p class="meta">No to-dos in this list yet.</p>';
    } else {
        $html .= '<ol class="todo-list__items">';
        foreach ($rows as $t) {
            $html .= boardExportTodoRowHtml($t);
        }
        $html .= '</ol>';
    }
    return $html . '</' . $tag . '>';
}

/**
 * @param array<string,mixed> $t
 */
function boardExportTodoRowHtml(array $t): string
{
    $tid = (int)$t['id'];
    $isDone = (int)($t['status_is_done'] ?? 0) === 1;
    $pri = strtolower((string)($t['priority'] ?? 'normal'));
    if (!in_array($pri, ['low', 'normal', 'high', 'urgent'], true)) {
        $pri = 'normal';
    }
    $html = '<li class="todo-row' . ($isDone ? ' todo-row--done' : '') . '">';
    $html .= '<span class="todo-checkbox' . ($isDone ? ' todo-checkbox--done' : '') . '" aria-hidden="true">'
        . ($isDone ? '✓' : '') . '</span>';
    $html .= '<div class="todo-row__body"><a class="todo-row__title" href="task-' . $tid . '.html">'
        . htmlspecialchars((string)$t['title']) . '</a><div class="todo-row__meta">';
    if (!$isDone) {
        $html .= '<span class="priority-chip priority-chip--' . htmlspecialchars($pri) . '">'
            . htmlspecialchars($pri) . '</span>';
    }
    if (!empty($t['due_at'])) {
        $html .= '<span>' . htmlspecialchars(substr((string)$t['due_at'], 0, 10)) . '</span>';
    }
    $assignee = trim((string)($t['assigned_to_username'] ?? ''));
    $html .= '<span>' . ($assignee !== '' ? htmlspecialchars($assignee) : 'Unassigned') . '</span>';
    $html .= '<span>#' . $tid . '</span></div></div></li>';
    return $html;
}

function boardExportWriteHtmlPages(array $project, string $staging, array $assetMap): void
{
    $projectId = (int)$project['id'];
    $userStub = ['id' => 0, 'role' => 'admin', 'person_kind' => 'team_member', 'org_id' => (int)$project['org_id'], 'limited_project_access' => 0];
    $admin = null;
    $db = getDbConnection();
    $ar = $db->query("SELECT * FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
    if ($ar) {
        $admin = $ar->fetchArray(SQLITE3_ASSOC) ?: null;
    }
    $viewer = $admin ?: $userStub;
    $docViewer = is_array($admin) ? $admin : ['id' => 1, 'role' => 'admin', 'person_kind' => 'team_member', 'org_id' => (int)$project['org_id'], 'limited_project_access' => 0];

    $allLists = listTodoListsForProject($viewer, $projectId, true);
    $activeLists = [];
    $archivedLists = [];
    foreach ($allLists as $list) {
        if (!empty($list['archived_at'])) {
            $archivedLists[] = $list;
        } else {
            $activeLists[] = $list;
        }
    }

    $taskResult = listTasks(['project_id' => $projectId], false, null, is_array($admin) ? $admin : null);
    $tasks = is_array($taskResult) ? $taskResult : [];
    if (isset($tasks['tasks']) && is_array($tasks['tasks'])) {
        $tasks = $tasks['tasks'];
    }

    $docs = listDocumentsForUser($docViewer, 500, $projectId);
    $liveDocs = [];
    foreach ($docs as $d) {
        if (($d['status'] ?? '') === 'trashed') {
            continue;
        }
        $full = getDocumentById((int)$d['id'], true);
        if ($full) {
            $liveDocs[] = $full;
        }
    }

    $css = boardExportSnapshotCss();
    $exportedAt = gmdate('Y-m-d H:i') . ' UTC';
    $grouped = boardExportGroupTasksByList($tasks);
    $header = boardExportProjectHeader($project, $exportedAt);
    $tabsLists = boardExportTabBar('index.html', count($activeLists), count($tasks), count($liveDocs));
    $tabsTasks = boardExportTabBar('tasks.html', count($activeLists), count($tasks), count($liveDocs));
    $tabsDocs = boardExportTabBar('docs.html', count($activeLists), count($tasks), count($liveDocs));

    $listsBody = $header . $tabsLists;
    $listsBody .= '<div class="todolist-toolbar"><div class="section-title">To-do lists <span class="todo-list__progress-pill">'
        . count($activeLists) . '</span></div></div>';
    if ($activeLists === [] && $grouped['unfiled'] === []) {
        $listsBody .= '<p class="meta">No lists yet.</p>';
    }
    foreach ($activeLists as $tl) {
        $listsBody .= boardExportTodoListSectionHtml($tl, $grouped['by_list'][(int)$tl['id']] ?? [], false);
    }
    if ($archivedLists !== []) {
        $listsBody .= '<details class="todo-list-archived-shelf" open><summary>Archived lists <span class="todo-list__progress-pill">'
            . count($archivedLists) . '</span></summary>';
        foreach ($archivedLists as $tl) {
            $listsBody .= boardExportTodoListSectionHtml($tl, $grouped['by_list'][(int)$tl['id']] ?? [], true);
        }
        $listsBody .= '</details>';
    }
    if ($grouped['unfiled'] !== []) {
        $unfiledDone = 0;
        foreach ($grouped['unfiled'] as $r) {
            if ((int)($r['status_is_done'] ?? 0) === 1) {
                $unfiledDone++;
            }
        }
        $unfiledTotal = count($grouped['unfiled']);
        $listsBody .= '<section class="todo-list todo-list--unfiled"><header class="todo-list__header">'
            . '<div class="todo-list__title"><h2 class="h5">Unfiled</h2></div>'
            . '<span class="todo-list__progress-pill">' . $unfiledDone . ' / ' . $unfiledTotal . ' done</span></header>'
            . '<ol class="todo-list__items">';
        foreach ($grouped['unfiled'] as $t) {
            $listsBody .= boardExportTodoRowHtml($t);
        }
        $listsBody .= '</ol></section>';
    }
    file_put_contents($staging . '/index.html', boardExportWrapPage((string)$project['name'], $css, $listsBody));

    $statuses = function_exists('listTaskStatuses') ? listTaskStatuses() : [
        ['slug' => 'todo', 'label' => 'To Do', 'is_done' => 0],
        ['slug' => 'doing', 'label' => 'In Progress', 'is_done' => 0],
        ['slug' => 'done', 'label' => 'Done', 'is_done' => 1],
    ];
    $byStatus = [];
    foreach ($statuses as $s) {
        $byStatus[(string)$s['slug']] = [];
    }
    foreach ($tasks as $t) {
        $slug = (string)($t['status'] ?? 'todo');
        if (!isset($byStatus[$slug])) {
            $byStatus[$slug] = [];
        }
        $byStatus[$slug][] = $t;
    }
    $tasksBody = $header . $tabsTasks . '<div class="board">';
    foreach ($statuses as $s) {
        $slug = (string)$s['slug'];
        $kind = boardExportStatusKind($s);
        $col = $byStatus[$slug] ?? [];
        $tasksBody .= '<div class="swimlane"><div class="swimlane__header">'
            . '<span class="status-pill status-pill--' . htmlspecialchars($kind) . '">'
            . htmlspecialchars((string)($s['label'] ?? $slug)) . '</span>'
            . '<span class="swimlane__count">' . count($col) . '</span></div><div class="swimlane__body">';
        if ($col === []) {
            $tasksBody .= '<div class="swimlane__empty">No tasks here.</div>';
        }
        foreach ($col as $t) {
            $tid = (int)$t['id'];
            $pri = strtolower((string)($t['priority'] ?? 'normal'));
            if (!in_array($pri, ['low', 'normal', 'high', 'urgent'], true)) {
                $pri = 'normal';
            }
            $assignee = trim((string)($t['assigned_to_username'] ?? ''));
            $tasksBody .= '<div class="task-card"><a class="task-card__title" href="task-' . $tid . '.html">'
                . htmlspecialchars((string)$t['title']) . '</a>'
                . '<div class="task-card__meta"><span class="priority-chip priority-chip--' . htmlspecialchars($pri) . '">'
                . htmlspecialchars($pri) . '</span></div>'
                . '<div class="task-card__footer"><span>'
                . ($assignee !== '' ? htmlspecialchars($assignee) : 'Unassigned')
                . '</span><span>#' . $tid . '</span></div></div>';
        }
        $tasksBody .= '</div></div>';
    }
    $tasksBody .= '</div>';
    file_put_contents($staging . '/tasks.html', boardExportWrapPage((string)$project['name'] . ' · Tasks', $css, $tasksBody));

    $docsBody = $header . $tabsDocs;
    if ($liveDocs === []) {
        $docsBody .= '<p class="meta">No documents on this board.</p>';
    }
    foreach ($liveDocs as $full) {
        $did = (int)$full['id'];
        $dir = trim((string)($full['directory_path'] ?? ''));
        $docsBody .= '<div class="doc-card"><a href="doc-' . $did . '.html">'
            . htmlspecialchars((string)$full['title']) . '</a>'
            . '<div class="meta">document #' . $did
            . ($dir !== '' ? ' · ' . htmlspecialchars($dir) : '')
            . '</div></div>';
        boardExportWriteDocHtml($full, $staging, $assetMap, $css);
    }
    file_put_contents($staging . '/docs.html', boardExportWrapPage((string)$project['name'] . ' · Docs', $css, $docsBody));

    foreach ($tasks as $t) {
        boardExportWriteTaskHtml($t, $staging, $assetMap, $css);
    }
}

/**
 * @param array<string,mixed> $task
 * @param array<int,string> $assetMap
 */
function boardExportWriteTaskHtml(array $task, string $staging, array $assetMap, string $css): void
{
    $tid = (int)$task['id'];
    $body = boardExportRewriteAssetUrls((string)($task['body'] ?? ''), $assetMap);
    $comments = listTaskComments($tid, 500, 0);
    $atts = listTaskAttachments($tid);

    $parts = [];
    $parts[] = '<nav class="detail-nav"><a href="index.html">← Lists</a><a href="tasks.html">Tasks</a><a href="docs.html">Docs</a></nav>';
    $parts[] = '<h1>#' . $tid . ' ' . htmlspecialchars((string)$task['title']) . '</h1>';
    $parts[] = '<p class="meta">status=' . htmlspecialchars((string)($task['status'] ?? ''))
        . ' · priority=' . htmlspecialchars((string)($task['priority'] ?? ''))
        . ' · assignee=' . htmlspecialchars((string)($task['assigned_to_username'] ?? '—'))
        . '</p>';
    $parts[] = '<div class="card">' . boardExportMarkdownToHtml($body) . '</div>';

    $parts[] = '<h2>Comments</h2>';
    if ($comments === []) {
        $parts[] = '<p class="meta">No comments.</p>';
    }
    foreach ($comments as $c) {
        $ct = boardExportRewriteAssetUrls((string)$c['comment'], $assetMap);
        $parts[] = '<div class="card"><div class="meta">'
            . htmlspecialchars((string)$c['username']) . ' · '
            . htmlspecialchars((string)$c['created_at']) . '</div>'
            . boardExportMarkdownToHtml($ct) . '</div>';
    }

    $parts[] = '<h2>Attachments</h2><ul>';
    foreach ($atts as $a) {
        $aid = (int)$a['id'];
        $href = $assetMap[$aid] ?? null;
        if ($href) {
            $parts[] = '<li><a href="' . htmlspecialchars($href) . '">'
                . htmlspecialchars((string)$a['file_name']) . '</a></li>';
        } else {
            $parts[] = '<li>' . htmlspecialchars((string)$a['file_name']) . ' (missing)</li>';
        }
    }
    $parts[] = '</ul>';

    file_put_contents(
        $staging . '/task-' . $tid . '.html',
        boardExportWrapPage('Task #' . $tid, $css, implode("\n", $parts))
    );
}

/**
 * @param array<string,mixed> $doc
 * @param array<int,string> $assetMap
 */
function boardExportWriteDocHtml(array $doc, string $staging, array $assetMap, string $css): void
{
    $did = (int)$doc['id'];
    $body = boardExportRewriteAssetUrls((string)($doc['body'] ?? ''), $assetMap);
    $parts = [];
    $parts[] = '<nav class="detail-nav"><a href="index.html">← Lists</a><a href="tasks.html">Tasks</a><a href="docs.html">Docs</a></nav>';
    $parts[] = '<h1>' . htmlspecialchars((string)$doc['title']) . '</h1>';
    $parts[] = '<p class="meta">document #' . $did . ' · '
        . htmlspecialchars((string)($doc['status'] ?? '')) . '</p>';
    $parts[] = '<div class="card">' . boardExportMarkdownToHtml($body) . '</div>';

    file_put_contents(
        $staging . '/doc-' . $did . '.html',
        boardExportWrapPage((string)$doc['title'], $css, implode("\n", $parts))
    );
}

/**
 * @param array<int,string> $assetMap
 */
function boardExportRewriteAssetUrls(string $text, array $assetMap): string
{
    return (string)preg_replace_callback(
        '/(?:\\/api\\/)?get-asset\\.php\\?([^)\\s"\'<>]*)/i',
        static function (array $m) use ($assetMap): string {
            $qs = $m[1] ?? '';
            parse_str($qs, $params);
            $id = isset($params['id']) ? (int)$params['id'] : 0;
            if ($id > 0 && isset($assetMap[$id])) {
                return $assetMap[$id];
            }
            return $m[0];
        },
        $text
    );
}

function boardExportMarkdownToHtml(string $md): string
{
    $md = trim($md);
    if ($md === '') {
        return '<p class="meta">(empty)</p>';
    }
    $parsedown = __DIR__ . '/lib/ParsedownTasks.php';
    if (is_file($parsedown)) {
        require_once $parsedown;
        if (class_exists('ParsedownTasks')) {
            $pd = new ParsedownTasks();
            $pd->setSafeMode(true);
            return $pd->text($md);
        }
    }
    return '<pre>' . htmlspecialchars($md) . '</pre>';
}

function boardExportSafeSlug(string $name): string
{
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? 'board');
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 60) : 'board';
}

function boardExportSafeFileName(string $name): string
{
    $base = basename(str_replace(["\0", '\\'], '', $name));
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base) ?? 'file';
    return $base !== '' ? $base : 'file';
}

function boardExportRmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * Stream a ready ZIP to the client (caller already authenticated + gated).
 */
function emitBoardExportDownload(array $job): void
{
    if (($job['status'] ?? '') !== 'ready') {
        http_response_code(404);
        echo 'Export not ready';
        exit;
    }
    $rel = (string)($job['storage_rel_path'] ?? '');
    $abs = boardExportAbsolutePath($rel);
    if ($abs === null || !is_file($abs)) {
        http_response_code(404);
        echo 'Export file missing';
        exit;
    }
    $name = 'board-export-' . (int)$job['project_id'] . '-' . (int)$job['id'] . '.zip';
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string)filesize($abs));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}
