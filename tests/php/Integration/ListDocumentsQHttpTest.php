<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

/**
 * S1.1 — HTTP coverage for GET /api/list-documents.php?q=…
 */
final class ListDocumentsQHttpTest extends TestCase
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

    public function testListDocumentsQFiltersAndAuth(): void
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

        $noKey = $c->get('/api/list-documents.php', [
            'headers' => ['X-API-Key' => ''],
            'query' => ['q' => 'anything'],
        ]);
        // Empty key header still sent — strip auth entirely
        $noKey = (new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
        ]))->get('/api/list-documents.php', ['query' => ['q' => 'anything']]);
        $this->assertSame(401, $noKey->getStatusCode());

        $tag = bin2hex(random_bytes(4));
        $proj = $c->post('/api/create-directory-project.php', [
            'body' => json_encode([
                'name' => "DocsQ {$tag}",
                'all_access' => true,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $proj->getStatusCode(), (string)$proj->getBody());
        $projectId = (int)(json_decode((string)$proj->getBody(), true)['data']['project']['id'] ?? 0);
        $this->assertGreaterThan(0, $projectId);

        $other = $c->post('/api/create-directory-project.php', [
            'body' => json_encode([
                'name' => "DocsQOther {$tag}",
                'all_access' => true,
            ], JSON_THROW_ON_ERROR),
        ]);
        $otherId = (int)(json_decode((string)$other->getBody(), true)['data']['project']['id'] ?? 0);

        $hit = $c->post('/api/create-document.php', [
            'body' => json_encode([
                'project_id' => $projectId,
                'title' => "Searchable title {$tag}",
                'body' => "body mentions unicorns {$tag}",
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $hit->getStatusCode(), (string)$hit->getBody());
        $hitId = (int)(json_decode((string)$hit->getBody(), true)['document']['id']
            ?? json_decode((string)$hit->getBody(), true)['data']['document']['id']
            ?? 0);
        $this->assertGreaterThan(0, $hitId);

        $miss = $c->post('/api/create-document.php', [
            'body' => json_encode([
                'project_id' => $projectId,
                'title' => "Unrelated {$tag}",
                'body' => 'no matching token',
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $miss->getStatusCode(), (string)$miss->getBody());
        $missId = (int)(json_decode((string)$miss->getBody(), true)['document']['id']
            ?? json_decode((string)$miss->getBody(), true)['data']['document']['id']
            ?? 0);

        $otherDoc = $c->post('/api/create-document.php', [
            'body' => json_encode([
                'project_id' => $otherId,
                'title' => "Other board unicorns {$tag}",
                'body' => 'elsewhere',
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $otherDoc->getStatusCode(), (string)$otherDoc->getBody());
        $otherDocId = (int)(json_decode((string)$otherDoc->getBody(), true)['document']['id']
            ?? json_decode((string)$otherDoc->getBody(), true)['data']['document']['id']
            ?? 0);

        $listed = $c->get('/api/list-documents.php', [
            'query' => ['q' => 'unicorns', 'project_id' => $projectId],
        ]);
        $this->assertSame(200, $listed->getStatusCode(), (string)$listed->getBody());
        $payload = json_decode((string)$listed->getBody(), true);
        $docs = $payload['documents'] ?? $payload['data']['documents'] ?? [];
        $ids = array_map(static fn ($d) => (int)$d['id'], $docs);
        $this->assertContains($hitId, $ids);
        $this->assertNotContains($missId, $ids);
        $this->assertNotContains($otherDocId, $ids);

        $empty = $c->get('/api/list-documents.php', [
            'query' => ['q' => 'zzzz-no-match-' . $tag, 'project_id' => $projectId],
        ]);
        $this->assertSame(200, $empty->getStatusCode());
        $emptyPayload = json_decode((string)$empty->getBody(), true);
        $emptyDocs = $emptyPayload['documents'] ?? $emptyPayload['data']['documents'] ?? [];
        $this->assertSame([], $emptyDocs);
    }
}
