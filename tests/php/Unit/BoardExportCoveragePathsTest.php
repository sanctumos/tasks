<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Extra board-export paths: docs/comments, pending reuse, claim races, missing assets, helpers.
 */
final class BoardExportCoveragePathsTest extends TestCase
{
    private function archivedProjectWithExtras(string $suffix): array
    {
        $owner = createUser("bxc_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($owner['success']);
        $uid = (int)$owner['id'];

        $proj = createDirectoryProject($uid, "BxCov {$suffix}", "Desc for {$suffix}", false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);
        $this->assertNotNull($lid);

        $task = createTask("Cov task {$suffix}", 'todo', $uid, $uid, "Body with no asset yet", [
            'priority' => 'high',
            'project_id' => $pid,
            'list_id' => $lid,
            'tags' => ['export', 'cov'],
        ]);
        $this->assertTrue($task['success'], (string)($task['error'] ?? ''));
        $tid = (int)$task['id'];

        $c = addTaskComment($tid, $uid, "Comment for fingerprint {$suffix}");
        $this->assertTrue($c['success'], (string)($c['error'] ?? ''));

        // Remote-looking attachment that will become .MISSING.txt in the ZIP.
        $att = addTaskAttachment($tid, $uid, 'gone.png', 'https://example.invalid/nope.png', 'image/png', 12, [
            'storage_kind' => 'remote',
            'storage_rel_path' => null,
        ]);
        $this->assertTrue($att['success'], (string)($att['error'] ?? ''));

        $doc = createDocument($uid, $pid, "Doc {$suffix}", "Doc body ![x](/api/get-asset.php?id=999999)\n\nMore.", 'specs');
        $this->assertTrue($doc['success'], (string)($doc['error'] ?? ''));
        $did = (int)$doc['id'];
        $dc = addDocumentComment($did, $uid, "Doc comment {$suffix}");
        $this->assertTrue($dc['success'], (string)($dc['error'] ?? ''));

        $archive = updateDirectoryProject($uid, $pid, ['status' => 'archived']);
        $this->assertTrue($archive['success']);

        return compact('uid', 'pid', 'tid', 'did', 'lid');
    }

    public function testFingerprintIncludesDocsCommentsAndExportBuildsThem(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $ctx = $this->archivedProjectWithExtras($suffix);
        $hash = boardExportContentFingerprint((int)$ctx['pid']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        $this->assertSame($hash, boardExportContentFingerprint((int)$ctx['pid']));

        $missing = boardExportContentFingerprint(99999999);
        $this->assertNotSame($hash, $missing);

        $db = getDbConnection();
        $ins = $db->prepare("
            INSERT INTO project_board_exports (project_id, requested_by_user_id, status, created_at)
            VALUES (:pid, :uid, 'pending', CURRENT_TIMESTAMP)
        ");
        $ins->bindValue(':pid', (int)$ctx['pid'], SQLITE3_INTEGER);
        $ins->bindValue(':uid', (int)$ctx['uid'], SQLITE3_INTEGER);
        $ins->execute();
        $jobId = (int)$db->lastInsertRowID();

        $proc = processBoardExportJob($jobId);
        $this->assertTrue($proc['success'], (string)($proc['error'] ?? ''));
        $job = getBoardExportJobById($jobId);
        $this->assertSame('ready', $job['status'] ?? null);
        $zipPath = boardExportAbsolutePath((string)$job['storage_rel_path']);
        $this->assertNotNull($zipPath);
        $entries = boardExportTestReadZipEntries($zipPath);
        $this->assertArrayHasKey('index.html', $entries);
        $this->assertStringContainsString((string)$ctx['tid'], $entries['index.html']);
        $this->assertArrayHasKey('doc-' . $ctx['did'] . '.html', $entries);
        $this->assertStringContainsString("Doc {$suffix}", $entries['doc-' . $ctx['did'] . '.html']);
        $this->assertStringContainsString('Comment for fingerprint', $entries['task-' . $ctx['tid'] . '.html']);

        $foundMissing = false;
        foreach ($entries as $name => $_) {
            if (str_contains($name, '.MISSING.txt')) {
                $foundMissing = true;
                break;
            }
        }
        $this->assertTrue($foundMissing, 'remote fail should leave MISSING note');
    }

    public function testPendingJobReusedAndClaimRaces(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $ctx = $this->archivedProjectWithExtras($suffix);
        $db = getDbConnection();

        $ins = $db->prepare("
            INSERT INTO project_board_exports (project_id, requested_by_user_id, status, created_at)
            VALUES (:pid, :uid, 'pending', CURRENT_TIMESTAMP)
        ");
        $ins->bindValue(':pid', (int)$ctx['pid'], SQLITE3_INTEGER);
        $ins->bindValue(':uid', (int)$ctx['uid'], SQLITE3_INTEGER);
        $ins->execute();
        $jobId = (int)$db->lastInsertRowID();

        $reuse = requestBoardExportJob((int)$ctx['uid'], (int)$ctx['pid']);
        $this->assertTrue($reuse['success']);
        $this->assertTrue(!empty($reuse['reused']));
        $this->assertSame($jobId, (int)$reuse['id']);
        $this->assertEmpty($reuse['unchanged'] ?? null);

        $proc = processBoardExportJob($jobId);
        $this->assertTrue($proc['success']);
        // Second process: already ready → success no-op
        $again = processBoardExportJob($jobId);
        $this->assertTrue($again['success']);

        $missing = processBoardExportJob(99999999);
        $this->assertFalse($missing['success']);

        // Running claim race
        $ins2 = $db->prepare("
            INSERT INTO project_board_exports (project_id, requested_by_user_id, status, started_at, created_at)
            VALUES (:pid, :uid, 'running', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $ins2->bindValue(':pid', (int)$ctx['pid'], SQLITE3_INTEGER);
        $ins2->bindValue(':uid', (int)$ctx['uid'], SQLITE3_INTEGER);
        $ins2->execute();
        $runningId = (int)$db->lastInsertRowID();
        $race = processBoardExportJob($runningId);
        $this->assertFalse($race['success']);
        $this->assertStringContainsString('running', (string)($race['error'] ?? ''));
    }

    public function testPathGuardsAndMarkdownHelpers(): void
    {
        $this->assertNull(boardExportAbsolutePath(''));
        $this->assertNull(boardExportAbsolutePath('../escape.zip'));
        $this->assertNull(boardExportAbsolutePath('nope/../../x.zip'));

        $this->assertSame('<p class="meta">(empty)</p>', boardExportMarkdownToHtml(''));
        $html = boardExportMarkdownToHtml('**bold** and text');
        $this->assertNotSame('', $html);

        $map = [42 => 'assets/42-x.png'];
        $rewritten = boardExportRewriteAssetUrls('see ![a](/api/get-asset.php?id=42) and ![b](/api/get-asset.php?id=99)', $map);
        $this->assertStringContainsString('assets/42-x.png', $rewritten);
        $this->assertStringContainsString('get-asset.php?id=99', $rewritten);

        $gate = boardExportAccessGate(['id' => 1, 'role' => 'admin'], null);
        $this->assertFalse($gate['ok'] ?? true);

        $bin = boardExportPhpCliBinary();
        $this->assertNotSame('', $bin);

        putenv('TASKS_REPO_ROOT=' . dirname(__DIR__, 3));
        boardExportSpawnWorker(1); // should find worker; may spawn briefly
        putenv('TASKS_REPO_ROOT');
    }

    public function testProcessFailsWhenProjectNotArchived(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $owner = createUser("bxna_{$suffix}", 'MemberPass123456', 'admin', false);
        $uid = (int)$owner['id'];
        $proj = createDirectoryProject($uid, "Active {$suffix}", null, false, true);
        $pid = (int)$proj['id'];
        $db = getDbConnection();
        $ins = $db->prepare("
            INSERT INTO project_board_exports (project_id, requested_by_user_id, status, created_at)
            VALUES (:pid, :uid, 'pending', CURRENT_TIMESTAMP)
        ");
        $ins->bindValue(':pid', $pid, SQLITE3_INTEGER);
        $ins->bindValue(':uid', $uid, SQLITE3_INTEGER);
        $ins->execute();
        $jobId = (int)$db->lastInsertRowID();
        $proc = processBoardExportJob($jobId);
        $this->assertFalse($proc['success']);
        $job = getBoardExportJobById($jobId);
        $this->assertSame('failed', $job['status'] ?? null);
    }

    public function testRequestRejectsUnknownUser(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $owner = createUser("bxu_{$suffix}", 'MemberPass123456', 'admin', false);
        $uid = (int)$owner['id'];
        $proj = createDirectoryProject($uid, "U {$suffix}", null, false, true);
        $pid = (int)$proj['id'];
        updateDirectoryProject($uid, $pid, ['status' => 'archived']);
        $bad = requestBoardExportJob(99999999, $pid);
        $this->assertFalse($bad['success']);
        $this->assertStringContainsString('User', (string)($bad['error'] ?? ''));
    }

    public function testLatestReadySkipsMissingFileAndFailedRows(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $ctx = $this->archivedProjectWithExtras($suffix);
        $db = getDbConnection();

        $insFail = $db->prepare("
            INSERT INTO project_board_exports (project_id, requested_by_user_id, status, created_at)
            VALUES (:pid, :uid, 'failed', CURRENT_TIMESTAMP)
        ");
        $insFail->bindValue(':pid', (int)$ctx['pid'], SQLITE3_INTEGER);
        $insFail->bindValue(':uid', (int)$ctx['uid'], SQLITE3_INTEGER);
        $insFail->execute();

        $insReadyEmpty = $db->prepare("
            INSERT INTO project_board_exports
                (project_id, requested_by_user_id, status, storage_rel_path, content_hash, created_at, completed_at)
            VALUES (:pid, :uid, 'ready', '', 'deadbeef', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insReadyEmpty->bindValue(':pid', (int)$ctx['pid'], SQLITE3_INTEGER);
        $insReadyEmpty->bindValue(':uid', (int)$ctx['uid'], SQLITE3_INTEGER);
        $insReadyEmpty->execute();

        $insGhost = $db->prepare("
            INSERT INTO project_board_exports
                (project_id, requested_by_user_id, status, storage_rel_path, content_hash, created_at, completed_at)
            VALUES (:pid, :uid, 'ready', :rel, 'abc', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insGhost->bindValue(':pid', (int)$ctx['pid'], SQLITE3_INTEGER);
        $insGhost->bindValue(':uid', (int)$ctx['uid'], SQLITE3_INTEGER);
        $insGhost->bindValue(':rel', 'ghost-' . $suffix . '.zip', SQLITE3_TEXT);
        $insGhost->execute();

        $this->assertNull(getLatestReadyBoardExportWithFile((int)$ctx['pid']));
    }
}
