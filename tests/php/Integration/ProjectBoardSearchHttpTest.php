<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class ProjectBoardSearchHttpTest extends TestCase
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

    public function testProjectTasksTabSearchFilterMarkupAndServerPath(): void
    {
        $this->server = PhpBuiltInServer::start();
        $db = new \SQLite3($this->server->dbPath);
        $db->exec("UPDATE users SET must_change_password = 0 WHERE username = '" . $db->escapeString($this->server->adminUsername) . "'");
        $db->close();

        $jar = new CookieJar();
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'cookies' => $jar,
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $login = $c->post('/api/session-login.php', [
            'body' => json_encode([
                'username' => $this->server->adminUsername,
                'password' => $this->server->adminPassword,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $login->getStatusCode(), (string)$login->getBody());

        $api = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => [
                'X-API-Key' => $this->server->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
        $tag = bin2hex(random_bytes(3));
        $needle = "pbs{$tag}";
        $proj = $api->post('/api/create-directory-project.php', [
            'body' => json_encode(['name' => "PBS {$needle}", 'all_access' => true], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $proj->getStatusCode(), (string)$proj->getBody());
        $projectId = (int)(json_decode((string)$proj->getBody(), true)['data']['project']['id'] ?? 0);
        $lists = $api->get('/api/list-todo-lists.php', ['query' => ['project_id' => $projectId]]);
        $listId = (int)((json_decode((string)$lists->getBody(), true)['data']['todo_lists'][0]['id']
            ?? json_decode((string)$lists->getBody(), true)['todo_lists'][0]['id']
            ?? 0));
        $api->post('/api/create-task.php', [
            'body' => json_encode([
                'title' => "Hit {$needle}",
                'status' => 'todo',
                'project_id' => $projectId,
                'list_id' => $listId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $api->post('/api/create-task.php', [
            'body' => json_encode([
                'title' => "Miss other{$tag}",
                'status' => 'todo',
                'project_id' => $projectId,
                'list_id' => $listId,
            ], JSON_THROW_ON_ERROR),
        ]);

        $page = $c->get('/admin/project.php', [
            'query' => ['id' => $projectId, 'tab' => 'tasks'],
        ]);
        $this->assertSame(200, $page->getStatusCode());
        $html = (string)$page->getBody();
        $this->assertStringContainsString('id="st-board-filter"', $html);
        $this->assertStringContainsString('st-filter.js', $html);
        $this->assertStringContainsString('data-st-use-client="1"', $html);
        $this->assertStringContainsString("Hit {$needle}", $html);

        $filtered = $c->get('/admin/project.php', [
            'query' => ['id' => $projectId, 'tab' => 'tasks', 'q' => $needle],
        ]);
        $this->assertSame(200, $filtered->getStatusCode());
        $fhtml = (string)$filtered->getBody();
        $this->assertStringContainsString("Hit {$needle}", $fhtml);
        $this->assertStringContainsString('name="q"', $fhtml);

        $lists = $c->get('/admin/project.php', [
            'query' => ['id' => $projectId, 'tab' => 'lists', 'q' => $needle],
        ]);
        $this->assertSame(200, $lists->getStatusCode());
        $lhtml = (string)$lists->getBody();
        $this->assertStringContainsString('id="st-lists-filter"', $lhtml);
        $this->assertStringContainsString("Hit {$needle}", $lhtml);
    }
}
