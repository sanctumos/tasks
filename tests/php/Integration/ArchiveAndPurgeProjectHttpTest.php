<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

/**
 * HTTP coverage for archive-project + permanent delete-directory-project.
 */
final class ArchiveAndPurgeProjectHttpTest extends TestCase
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

    public function testArchiveThenPurgeWithConfirmName(): void
    {
        $this->server = PhpBuiltInServer::start();
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => ['X-API-Key' => $this->server->apiKey],
        ]);

        $name = 'Archive HTTP ' . bin2hex(random_bytes(2));
        $p = $c->post('/api/create-directory-project.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['name' => $name, 'all_access' => false], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $p->getStatusCode(), (string)$p->getBody());
        $projId = (int)(json_decode((string)$p->getBody(), true)['data']['project']['id'] ?? 0);
        $this->assertGreaterThan(0, $projId);

        $arch = $c->post('/api/archive-project.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['id' => $projId], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $arch->getStatusCode(), (string)$arch->getBody());
        $archBody = json_decode((string)$arch->getBody(), true);
        $this->assertSame('archived', $archBody['data']['project']['status'] ?? null);

        $again = $c->post('/api/archive-project.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['project_id' => $projId], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $again->getStatusCode(), (string)$again->getBody());
        $this->assertTrue((bool)(json_decode((string)$again->getBody(), true)['data']['already_archived'] ?? false));

        $refuseActive = $c->post('/api/delete-directory-project.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'id' => $projId,
                'confirm_name' => $name,
                // still archived — not active; missing acknowledge should fail without ZIP
            ], JSON_THROW_ON_ERROR),
        ]);
        // No ZIP → soft error unless acknowledge_no_export
        $this->assertSame(400, $refuseActive->getStatusCode(), (string)$refuseActive->getBody());

        $purge = $c->post('/api/delete-directory-project.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'id' => $projId,
                'confirm_name' => $name,
                'acknowledge_no_export' => true,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $purge->getStatusCode(), (string)$purge->getBody());
        $purgeBody = json_decode((string)$purge->getBody(), true);
        $this->assertTrue((bool)($purgeBody['data']['deleted'] ?? false));

        $gone = $c->get('/api/get-directory-project.php', ['query' => ['id' => $projId]]);
        $this->assertContains($gone->getStatusCode(), [404, 400], (string)$gone->getBody());
    }
}
