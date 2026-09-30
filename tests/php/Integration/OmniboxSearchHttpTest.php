<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class OmniboxSearchHttpTest extends TestCase
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

    public function testSearchFanOutAuthAndValidation(): void
    {
        $this->server = PhpBuiltInServer::start();
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => [
                'X-API-Key' => $this->server->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);

        $unauth = (new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
        ]))->get('/api/search.php', ['query' => ['q' => 'ab']]);
        $this->assertSame(401, $unauth->getStatusCode());

        $short = $c->get('/api/search.php', ['query' => ['q' => 'a']]);
        $this->assertSame(400, $short->getStatusCode(), (string)$short->getBody());

        $tag = bin2hex(random_bytes(4));
        $needle = "omniz{$tag}";
        $proj = $c->post('/api/create-directory-project.php', [
            'body' => json_encode([
                'name' => "OmniProj {$needle}",
                'all_access' => true,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $proj->getStatusCode(), (string)$proj->getBody());
        $projectId = (int)(json_decode((string)$proj->getBody(), true)['data']['project']['id'] ?? 0);

        $lists = $c->get('/api/list-todo-lists.php', ['query' => ['project_id' => $projectId]]);
        $this->assertSame(200, $lists->getStatusCode(), (string)$lists->getBody());
        $listsPayload = json_decode((string)$lists->getBody(), true);
        $listsData = $listsPayload['data'] ?? $listsPayload;
        $listId = (int)($listsData['todo_lists'][0]['id'] ?? 0);
        $this->assertGreaterThan(0, $listId);

        $task = $c->post('/api/create-task.php', [
            'body' => json_encode([
                'title' => "Task {$needle}",
                'body' => 'search body',
                'status' => 'todo',
                'project_id' => $projectId,
                'list_id' => $listId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $task->getStatusCode(), (string)$task->getBody());

        $doc = $c->post('/api/create-document.php', [
            'body' => json_encode([
                'project_id' => $projectId,
                'title' => "Doc {$needle}",
                'body' => 'doc body',
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $doc->getStatusCode(), (string)$doc->getBody());

        $hit = $c->get('/api/search.php', ['query' => ['q' => $needle, 'limit' => 5]]);
        $this->assertSame(200, $hit->getStatusCode(), (string)$hit->getBody());
        $payload = json_decode((string)$hit->getBody(), true);
        $data = $payload['data'] ?? $payload;
        $this->assertSame($needle, $data['q'] ?? null);
        foreach (['tasks', 'documents', 'users', 'projects'] as $k) {
            $this->assertArrayHasKey($k, $data['groups'] ?? [], $k);
            $this->assertArrayHasKey($k, $data['counts'] ?? [], $k);
        }
        $taskTitles = array_column($data['groups']['tasks'] ?? [], 'title');
        $docTitles = array_column($data['groups']['documents'] ?? [], 'title');
        $projTitles = array_column($data['groups']['projects'] ?? [], 'title');
        $this->assertTrue((bool)array_filter($taskTitles, static fn ($t) => str_contains((string)$t, $needle)));
        $this->assertTrue((bool)array_filter($docTitles, static fn ($t) => str_contains((string)$t, $needle)));
        $this->assertTrue((bool)array_filter($projTitles, static fn ($t) => str_contains((string)$t, $needle)));

        $empty = $c->get('/api/search.php', ['query' => ['q' => 'zzzzempty' . $tag]]);
        $this->assertSame(200, $empty->getStatusCode());
        $emptyData = json_decode((string)$empty->getBody(), true);
        $ed = $emptyData['data'] ?? $emptyData;
        $this->assertSame(0, (int)($ed['counts']['tasks'] ?? -1));
        $this->assertSame([], $ed['groups']['tasks'] ?? null);
    }
}
