<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

/**
 * HTTP coverage for archived-board ZIP export APIs (request / list / download / unchanged reuse).
 */
final class BoardExportHttpTest extends TestCase
{
    private ?PhpBuiltInServer $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
        parent::tearDown();
    }

    public function testRequestListDownloadAndUnchangedReuse(): void
    {
        $exportDir = sys_get_temp_dir() . '/st-board-exp-' . uniqid('', true);
        mkdir($exportDir, 0700, true);
        $this->server = PhpBuiltInServer::start([
            'TASKS_BOARD_EXPORT_DIR' => $exportDir,
            'TASKS_REPO_ROOT' => dirname(__DIR__, 3),
        ]);
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => ['X-API-Key' => $this->server->apiKey],
        ]);

        $p = $c->post('/api/create-directory-project.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['name' => 'Export HTTP project', 'all_access' => true], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $p->getStatusCode(), (string)$p->getBody());
        $projId = (int)(json_decode((string)$p->getBody(), true)['data']['project']['id'] ?? 0);
        $this->assertGreaterThan(0, $projId);

        $listsResp = $c->get('/api/list-todo-lists.php', ['query' => ['project_id' => $projId]]);
        $listId = (int)((json_decode((string)$listsResp->getBody(), true)['data']['todo_lists'][0]['id'] ?? 0));
        $this->assertGreaterThan(0, $listId);

        $create = $c->post('/api/create-task.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'title' => 'Export HTTP task',
                'status' => 'todo',
                'project_id' => $projId,
                'list_id' => $listId,
                'body' => 'snapshot body',
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $create->getStatusCode(), (string)$create->getBody());

        // Active project cannot export
        $deny = $c->post('/api/request-board-export.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['project_id' => $projId], JSON_THROW_ON_ERROR),
        ]);
        $this->assertContains($deny->getStatusCode(), [400, 403, 404], (string)$deny->getBody());

        $upd = $c->post('/api/update-directory-project.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['id' => $projId, 'status' => 'archived'], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $upd->getStatusCode(), (string)$upd->getBody());

        $req = $c->post('/api/request-board-export.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['project_id' => $projId], JSON_THROW_ON_ERROR),
        ]);
        $this->assertContains($req->getStatusCode(), [200, 201], (string)$req->getBody());
        $reqJson = json_decode((string)$req->getBody(), true);
        $jobId = (int)($reqJson['data']['job_id'] ?? 0);
        $this->assertGreaterThan(0, $jobId);

        $ready = null;
        for ($i = 0; $i < 40; $i++) {
            $list = $c->get('/api/list-board-exports.php', ['query' => ['project_id' => $projId]]);
            $this->assertSame(200, $list->getStatusCode(), (string)$list->getBody());
            $jobs = json_decode((string)$list->getBody(), true)['data']['exports']
                ?? json_decode((string)$list->getBody(), true)['data']['jobs']
                ?? json_decode((string)$list->getBody(), true)['data']
                ?? [];
            if (isset($jobs['exports'])) {
                $jobs = $jobs['exports'];
            }
            if (!is_array($jobs)) {
                $jobs = [];
            }
            foreach ($jobs as $job) {
                if ((int)($job['id'] ?? 0) === $jobId && ($job['status'] ?? '') === 'ready') {
                    $ready = $job;
                    break 2;
                }
            }
            // Fallback: process via CLI if spawn lagging
            if ($i === 5) {
                $worker = dirname(__DIR__, 3) . '/tools/board-export-worker.php';
                if (is_file($worker)) {
                    $env = [
                        'TASKS_DB_PATH' => $this->server->dbPath,
                        'TASKS_BOARD_EXPORT_DIR' => $exportDir,
                    ];
                    $cmd = 'TASKS_DB_PATH=' . escapeshellarg($env['TASKS_DB_PATH'])
                        . ' TASKS_BOARD_EXPORT_DIR=' . escapeshellarg($env['TASKS_BOARD_EXPORT_DIR'])
                        . ' php ' . escapeshellarg($worker) . ' ' . (int)$jobId;
                    exec($cmd . ' 2>&1', $out, $code);
                }
            }
            usleep(150000);
        }
        $this->assertNotNull($ready, 'export job should become ready');

        $dl = $c->get('/api/download-board-export.php', ['query' => ['id' => $jobId]]);
        $this->assertSame(200, $dl->getStatusCode(), (string)$dl->getBody());
        $this->assertStringContainsString('zip', strtolower($dl->getHeaderLine('Content-Type')));
        $this->assertGreaterThan(50, strlen((string)$dl->getBody()));

        $reuse = $c->post('/api/request-board-export.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['project_id' => $projId], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $reuse->getStatusCode(), (string)$reuse->getBody());
        $reuseJson = json_decode((string)$reuse->getBody(), true);
        $this->assertTrue(!empty($reuseJson['data']['unchanged']), (string)$reuse->getBody());
        $this->assertSame($jobId, (int)($reuseJson['data']['job_id'] ?? 0));
    }
}
