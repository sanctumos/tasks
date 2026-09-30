<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class HomeResultsPartialHttpTest extends TestCase
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

    public function testHomeResultsPartialFragmentAuthAndFilter(): void
    {
        $this->server = PhpBuiltInServer::start();

        $unauth = (new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'allow_redirects' => false,
        ]))->get('/admin/', ['query' => ['partial' => 'results', 'q' => 'x']]);
        $this->assertContains($unauth->getStatusCode(), [302, 303]);
        $loc = $unauth->getHeaderLine('Location');
        $this->assertTrue(
            str_contains($loc, 'login') || str_contains($loc, 'auth'),
            'expected login redirect, got: ' . $loc
        );

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
        $needle = "homerz{$tag}";
        $other = "otherz{$tag}";
        $proj = $api->post('/api/create-directory-project.php', [
            'body' => json_encode(['name' => "HomePart {$needle}", 'all_access' => true], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $proj->getStatusCode(), (string)$proj->getBody());
        $projectId = (int)(json_decode((string)$proj->getBody(), true)['data']['project']['id'] ?? 0);
        $lists = $api->get('/api/list-todo-lists.php', ['query' => ['project_id' => $projectId]]);
        $listId = (int)((json_decode((string)$lists->getBody(), true)['data']['todo_lists'][0]['id']
            ?? json_decode((string)$lists->getBody(), true)['todo_lists'][0]['id']
            ?? 0));

        $match = $api->post('/api/create-task.php', [
            'body' => json_encode([
                'title' => "Match {$needle}",
                'body' => 'partial body',
                'status' => 'todo',
                'project_id' => $projectId,
                'list_id' => $listId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $match->getStatusCode(), (string)$match->getBody());
        $miss = $api->post('/api/create-task.php', [
            'body' => json_encode([
                'title' => "Miss {$other}",
                'status' => 'todo',
                'project_id' => $projectId,
                'list_id' => $listId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $miss->getStatusCode(), (string)$miss->getBody());

        $resp = $c->get('/admin/', [
            'query' => ['partial' => 'results', 'q' => $needle],
        ]);
        $this->assertSame(200, $resp->getStatusCode(), (string)$resp->getBody());
        $html = (string)$resp->getBody();
        $this->assertSame('1', $resp->getHeaderLine('X-Total-Count'));
        $this->assertStringContainsString('id="st-home-results"', $html);
        $this->assertStringContainsString('data-total-count="1"', $html);
        $this->assertStringContainsString("Match {$needle}", $html);
        $this->assertStringNotContainsString("Miss {$other}", $html);
        $this->assertStringNotContainsString('admin-nav', $html);
        $this->assertStringNotContainsString('navbar-brand', $html);
        $this->assertStringNotContainsString('<h1>', $html);

        $full = $c->get('/admin/', ['query' => ['q' => $needle]]);
        $this->assertSame(200, $full->getStatusCode());
        $fullHtml = (string)$full->getBody();
        $this->assertStringContainsString('admin-nav', $fullHtml);
        $this->assertStringContainsString('navbar-brand', $fullHtml);
        $this->assertStringContainsString("Match {$needle}", $fullHtml);
        $this->assertStringContainsString('id="st-home-results"', $fullHtml);
    }
}
