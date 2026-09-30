<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class AdminSearchPageHttpTest extends TestCase
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

    public function testSearchPageSessionGroupedResultsAndPagination(): void
    {
        $this->server = PhpBuiltInServer::start();
        $jar = new CookieJar();
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'cookies' => $jar,
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // Clear must_change_password so session login lands cleanly
        $db = new \SQLite3($this->server->dbPath);
        $db->exec("UPDATE users SET must_change_password = 0 WHERE username = '" . $db->escapeString($this->server->adminUsername) . "'");
        $db->close();

        $login = $c->post('/api/session-login.php', [
            'body' => json_encode([
                'username' => $this->server->adminUsername,
                'password' => $this->server->adminPassword,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $login->getStatusCode(), (string)$login->getBody());

        $empty = $c->get('/admin/search.php');
        $this->assertSame(200, $empty->getStatusCode());
        $emptyHtml = (string)$empty->getBody();
        $this->assertStringContainsString('Enter a query', $emptyHtml);

        $api = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => [
                'X-API-Key' => $this->server->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
        $tag = bin2hex(random_bytes(3));
        $needle = "srchpg{$tag}";
        $proj = $api->post('/api/create-directory-project.php', [
            'body' => json_encode(['name' => "Board {$needle}", 'all_access' => true], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $proj->getStatusCode(), (string)$proj->getBody());
        $projectId = (int)(json_decode((string)$proj->getBody(), true)['data']['project']['id'] ?? 0);
        $lists = $api->get('/api/list-todo-lists.php', ['query' => ['project_id' => $projectId]]);
        $listId = (int)((json_decode((string)$lists->getBody(), true)['data']['todo_lists'][0]['id']
            ?? json_decode((string)$lists->getBody(), true)['todo_lists'][0]['id']
            ?? 0));

        for ($i = 0; $i < 26; $i++) {
            $t = $api->post('/api/create-task.php', [
                'body' => json_encode([
                    'title' => "Task {$needle} {$i}",
                    'status' => 'todo',
                    'project_id' => $projectId,
                    'list_id' => $listId,
                ], JSON_THROW_ON_ERROR),
            ]);
            $this->assertSame(201, $t->getStatusCode(), (string)$t->getBody());
        }
        $doc = $api->post('/api/create-document.php', [
            'body' => json_encode([
                'project_id' => $projectId,
                'title' => "Doc {$needle}",
                'body' => 'body',
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $doc->getStatusCode(), (string)$doc->getBody());

        $hit = $c->get('/admin/search.php', ['query' => ['q' => $needle]]);
        $this->assertSame(200, $hit->getStatusCode());
        $html = (string)$hit->getBody();
        $this->assertStringContainsString('data-group="tasks"', $html);
        $this->assertStringContainsString('data-group="documents"', $html);
        $this->assertStringContainsString('data-group="projects"', $html);
        $this->assertStringContainsString($needle, $html);
        $this->assertStringContainsString('st-search-row', $html);
        $this->assertStringContainsString('More Tasks', $html);
        $this->assertStringContainsString('/admin/doc.php?id=', $html);

        $page2 = $c->get('/admin/search.php', [
            'query' => ['q' => $needle, 'tasks_offset' => 25],
        ]);
        $this->assertSame(200, $page2->getStatusCode());
        $html2 = (string)$page2->getBody();
        $this->assertStringContainsString($needle, $html2);
        $this->assertStringContainsString('Previous Tasks', $html2);
        $this->assertStringContainsString('/admin/view.php?id=', $html2);
    }
}
