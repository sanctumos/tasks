<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class SharedDocumentAssetHttpTest extends TestCase
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

    public function testPublicShareServesEmbeddedAssetWithoutLogin(): void
    {
        $this->server = PhpBuiltInServer::start();
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => ['X-API-Key' => $this->server->apiKey],
        ]);

        $p = $c->post('/api/create-directory-project.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['name' => 'Share asset proj', 'all_access' => true], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $p->getStatusCode(), (string)$p->getBody());
        $projId = (int)(json_decode((string)$p->getBody(), true)['data']['project']['id'] ?? 0);

        $listsResp = $c->get('/api/list-todo-lists.php', ['query' => ['project_id' => $projId]]);
        $listId = (int)(json_decode((string)$listsResp->getBody(), true)['data']['todo_lists'][0]['id'] ?? 0);

        $taskResp = $c->post('/api/create-task.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'title' => 'Asset host task',
                'project_id' => $projId,
                'list_id' => $listId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $taskResp->getStatusCode(), (string)$taskResp->getBody());
        $taskId = (int)(json_decode((string)$taskResp->getBody(), true)['data']['task']['id'] ?? 0);

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        $tmp = tempnam(sys_get_temp_dir(), 'st-png-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $png);

        $upload = $c->post('/api/upload-attachment.php', [
            'multipart' => [
                ['name' => 'task_id', 'contents' => (string)$taskId],
                ['name' => 'file', 'contents' => fopen($tmp, 'r'), 'filename' => 'pixel.png'],
            ],
        ]);
        @unlink($tmp);
        $this->assertSame(201, $upload->getStatusCode(), (string)$upload->getBody());
        $uploadJson = json_decode((string)$upload->getBody(), true);
        $attachId = (int)($uploadJson['data']['attachment_id'] ?? 0);
        $this->assertGreaterThan(0, $attachId);

        $assetUrl = '/api/get-asset.php?id=' . $attachId;
        $docResp = $c->post('/api/create-document.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'project_id' => $projId,
                'title' => 'Public with image',
                'body' => "# Hi\n\n![pixel]({$assetUrl})\n",
                'public_link_enabled' => true,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $docResp->getStatusCode(), (string)$docResp->getBody());
        $doc = json_decode((string)$docResp->getBody(), true)['data']['document'] ?? [];
        $shareUrl = (string)($doc['public_share_url'] ?? '');
        $this->assertNotSame('', $shareUrl);
        parse_str((string)(parse_url($shareUrl, PHP_URL_QUERY) ?? ''), $qs);
        $shareToken = (string)($qs['token'] ?? '');
        $this->assertSame(64, strlen($shareToken));

        $guest = new Client(['base_uri' => $this->server->baseUrl, 'http_errors' => false]);
        $denied = $guest->get('/api/get-asset.php', ['query' => ['id' => $attachId]]);
        $this->assertSame(401, $denied->getStatusCode());

        $allowed = $guest->get('/api/get-asset.php', [
            'query' => [
                'id' => $attachId,
                'document_share_token' => $shareToken,
            ],
        ]);
        $this->assertSame(200, $allowed->getStatusCode(), (string)$allowed->getBody());

        $page = $guest->get('/shared-document.php', ['query' => ['token' => $shareToken]]);
        $this->assertSame(200, $page->getStatusCode(), (string)$page->getBody());
        $this->assertStringContainsString('document_share_token=' . $shareToken, (string)$page->getBody());
    }
}
