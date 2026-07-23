<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Board archive ZIP: ACL gates + worker fixture with local attachment embed.
 */
final class BoardExportTest extends TestCase
{
    private string $exportDir;
    private string $assetDir;

    protected function setUp(): void
    {
        $this->exportDir = rtrim((string)TASKS_BOARD_EXPORT_DIR, '/');
        $this->assetDir = rtrim((string)TASKS_ASSET_STORAGE_DIR, '/');
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0777, true);
        }
        if (!is_dir($this->assetDir)) {
            mkdir($this->assetDir, 0777, true);
        }
    }

    public function testActiveProjectRejectsExportRequest(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $user = createUser("exp_a_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($user['success']);
        $uid = (int)$user['id'];
        $proj = createDirectoryProject($uid, "ActiveExp {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];

        $req = requestBoardExportJob($uid, $pid);
        $this->assertFalse($req['success']);
        $this->assertStringContainsString('archived', (string)($req['error'] ?? ''));
    }

    public function testNonMemberDeniedAccessGate(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $owner = createUser("exp_o_{$suffix}", 'MemberPass123456', 'member', false);
        $outsider = createUser("exp_x_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($owner['success'] && $outsider['success']);
        $oid = (int)$owner['id'];
        $xid = (int)$outsider['id'];

        $proj = createDirectoryProject($oid, "PrivExp {$suffix}", null, false, false);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        updateDirectoryProject($oid, $pid, ['status' => 'archived']);
        $project = getDirectoryProjectById($pid);
        $this->assertNotNull($project);

        $outUser = getUserById($xid, false);
        $gate = boardExportAccessGate($outUser, $project);
        $this->assertFalse($gate['ok'] ?? true);
        $this->assertSame(404, (int)($gate['http'] ?? 0));
    }

    public function testMemberOnArchivedCanRequestAndWorkerBuildsZipWithAsset(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $owner = createUser("exp_m_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($owner['success']);
        $uid = (int)$owner['id'];
        $full = getUserById($uid, false);

        $proj = createDirectoryProject($uid, "ZipExp {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);
        $this->assertNotNull($lid);

        $rel = 'fixture-' . $suffix . '.png';
        $absAsset = rtrim($this->assetDir, '/') . '/' . $rel;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->assertNotFalse($png);
        file_put_contents($absAsset, $png);

        $task = createTask("Export task {$suffix}", 'todo', $uid, null, "See ![img](/api/get-asset.php?id=PLACEHOLDER)", [
            'priority' => 'normal',
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $this->assertTrue($task['success'], (string)($task['error'] ?? ''));
        $tid = (int)$task['id'];

        $att = addTaskAttachment($tid, $uid, 'pixel.png', '/api/get-asset.php?id=0', 'image/png', strlen($png), [
            'storage_kind' => 'local',
            'storage_rel_path' => $rel,
        ]);
        $this->assertTrue($att['success'], (string)($att['error'] ?? ''));
        $aid = (int)$att['id'];

        $db = getDbConnection();
        $upd = $db->prepare('UPDATE tasks SET body = :b WHERE id = :id');
        $upd->bindValue(':b', "See ![img](/api/get-asset.php?id={$aid})", SQLITE3_TEXT);
        $upd->bindValue(':id', $tid, SQLITE3_INTEGER);
        $upd->execute();
        $afu = $db->prepare('UPDATE task_attachments SET file_url = :u WHERE id = :id');
        $afu->bindValue(':u', "/api/get-asset.php?id={$aid}", SQLITE3_TEXT);
        $afu->bindValue(':id', $aid, SQLITE3_INTEGER);
        $afu->execute();

        $archive = updateDirectoryProject($uid, $pid, ['status' => 'archived']);
        $this->assertTrue($archive['success']);
        $project = getDirectoryProjectById($pid);
        $gate = boardExportAccessGate($full, $project);
        $this->assertTrue($gate['ok'] ?? false);

        // Insert pending job without spawning background worker (tests call process directly).
        $ins = $db->prepare("
            INSERT INTO project_board_exports (project_id, requested_by_user_id, status, created_at)
            VALUES (:pid, :uid, 'pending', CURRENT_TIMESTAMP)
        ");
        $ins->bindValue(':pid', $pid, SQLITE3_INTEGER);
        $ins->bindValue(':uid', $uid, SQLITE3_INTEGER);
        $ins->execute();
        $jobId = (int)$db->lastInsertRowID();

        $proc = processBoardExportJob($jobId);
        $this->assertTrue($proc['success'], (string)($proc['error'] ?? 'process failed'));

        $job = getBoardExportJobById($jobId);
        $this->assertNotNull($job);
        $this->assertSame('ready', $job['status']);
        $this->assertNotEmpty($job['storage_rel_path']);

        $zipPath = boardExportAbsolutePath((string)$job['storage_rel_path']);
        $this->assertNotNull($zipPath);
        $this->assertFileExists($zipPath);

        $entries = boardExportTestReadZipEntries($zipPath);
        $this->assertArrayHasKey('index.html', $entries);
        $this->assertStringContainsString("Export task {$suffix}", $entries['index.html']);
        $this->assertArrayHasKey("task-{$tid}.html", $entries);
        $this->assertStringContainsString('assets/' . $aid . '-', $entries["task-{$tid}.html"]);

        $foundAsset = false;
        foreach ($entries as $name => $bytes) {
            if (str_starts_with($name, 'assets/' . $aid . '-')) {
                $this->assertSame($png, $bytes);
                $foundAsset = true;
                break;
            }
        }
        $this->assertTrue($foundAsset, 'ZIP should include attachment bytes under assets/');

        // Second export creates a new job row
        $ins2 = $db->prepare("
            INSERT INTO project_board_exports (project_id, requested_by_user_id, status, created_at)
            VALUES (:pid, :uid, 'pending', CURRENT_TIMESTAMP)
        ");
        $ins2->bindValue(':pid', $pid, SQLITE3_INTEGER);
        $ins2->bindValue(':uid', $uid, SQLITE3_INTEGER);
        $ins2->execute();
        $jobId2 = (int)$db->lastInsertRowID();
        $this->assertNotSame($jobId, $jobId2);
        $proc2 = processBoardExportJob($jobId2);
        $this->assertTrue($proc2['success'], (string)($proc2['error'] ?? ''));
        $listed = listBoardExportJobsForProject($pid, 10);
        $this->assertGreaterThanOrEqual(2, count($listed));
    }
}

/**
 * Minimal store-method ZIP reader for assertions without ext-zip.
 *
 * @return array<string,string>
 */
function boardExportTestReadZipEntries(string $zipPath): array
{
    if (class_exists(\ZipArchive::class)) {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) === true) {
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || str_ends_with($name, '/')) {
                    continue;
                }
                $data = $zip->getFromIndex($i);
                if ($data !== false) {
                    $out[$name] = $data;
                }
            }
            $zip->close();
            return $out;
        }
    }

    $bin = file_get_contents($zipPath);
    if ($bin === false) {
        return [];
    }
    $out = [];
    $pos = 0;
    $len = strlen($bin);
    while ($pos + 30 <= $len) {
        $sig = unpack('V', substr($bin, $pos, 4))[1] ?? 0;
        if ($sig !== 0x04034b50) {
            break;
        }
        $hdr = unpack('vver/vflag/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vnamelen/vexlen', substr($bin, $pos + 4, 26));
        $nameLen = (int)$hdr['namelen'];
        $exLen = (int)$hdr['exlen'];
        $comp = (int)$hdr['comp'];
        $method = (int)$hdr['method'];
        $name = substr($bin, $pos + 30, $nameLen);
        $dataStart = $pos + 30 + $nameLen + $exLen;
        $data = substr($bin, $dataStart, $comp);
        if ($method === 0) {
            $out[$name] = $data;
        } elseif ($method === 8 && function_exists('gzinflate')) {
            $inflated = @gzinflate($data);
            if ($inflated !== false) {
                $out[$name] = $inflated;
            }
        }
        $pos = $dataStart + $comp;
    }
    return $out;
}
