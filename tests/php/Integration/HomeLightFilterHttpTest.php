<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class HomeLightFilterHttpTest extends TestCase
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

    public function testFilteredHomeUsesLightPathWhenBoardWidgetOff(): void
    {
        $this->server = PhpBuiltInServer::start();
        $db = new \SQLite3($this->server->dbPath);
        $db->exec("UPDATE users SET must_change_password = 0, home_widgets_json = '{\"cross_project_board\":false}' WHERE username = '" . $db->escapeString($this->server->adminUsername) . "'");
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
        $needle = "lightz{$tag}";
        $proj = $api->post('/api/create-directory-project.php', [
            'body' => json_encode(['name' => "Light {$needle}", 'all_access' => true], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $proj->getStatusCode(), (string)$proj->getBody());
        $projectId = (int)(json_decode((string)$proj->getBody(), true)['data']['project']['id'] ?? 0);
        $lists = $api->get('/api/list-todo-lists.php', ['query' => ['project_id' => $projectId]]);
        $listId = (int)((json_decode((string)$lists->getBody(), true)['data']['todo_lists'][0]['id']
            ?? json_decode((string)$lists->getBody(), true)['todo_lists'][0]['id']
            ?? 0));
        $task = $api->post('/api/create-task.php', [
            'body' => json_encode([
                'title' => "Hit {$needle}",
                'status' => 'todo',
                'project_id' => $projectId,
                'list_id' => $listId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $task->getStatusCode(), (string)$task->getBody());

        $resp = $c->get('/admin/', ['query' => ['q' => $needle]]);
        $this->assertSame(200, $resp->getStatusCode(), (string)$resp->getBody());
        $html = (string)$resp->getBody();
        $this->assertStringContainsString('data-st-home-path="light"', $html);
        $this->assertStringContainsString("Hit {$needle}", $html);
        $this->assertStringContainsString('id="st-home-results"', $html);
        $this->assertStringNotContainsString('st-home-board-hydration', $html);
        $this->assertStringNotContainsString('newTaskModal', $html);

        $unfiltered = $c->get('/admin/');
        $this->assertSame(200, $unfiltered->getStatusCode());
        $plain = (string)$unfiltered->getBody();
        $this->assertStringContainsString('st-home-master-optin', $plain);
        $this->assertStringNotContainsString('st-home-board-hydration', $plain);
    }
}
