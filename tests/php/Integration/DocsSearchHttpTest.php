<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class DocsSearchHttpTest extends TestCase
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

    public function testDocsHubSearchHighlightAndProjectScope(): void
    {
        $this->server = PhpBuiltInServer::start();
        $jar = new CookieJar();
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'cookies' => $jar,
            'headers' => ['Content-Type' => 'application/json'],
        ]);
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

        $api = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => [
                'X-API-Key' => $this->server->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
        $tag = bin2hex(random_bytes(3));
        $needle = "retainer{$tag}";

        $p1 = $api->post('/api/create-directory-project.php', [
            'body' => json_encode(['name' => "DocsA {$tag}", 'all_access' => true], JSON_THROW_ON_ERROR),
        ]);
        $pid1 = (int)(json_decode((string)$p1->getBody(), true)['data']['project']['id'] ?? 0);
        $p2 = $api->post('/api/create-directory-project.php', [
            'body' => json_encode(['name' => "DocsB {$tag}", 'all_access' => true], JSON_THROW_ON_ERROR),
        ]);
        $pid2 = (int)(json_decode((string)$p2->getBody(), true)['data']['project']['id'] ?? 0);

        $hit = $api->post('/api/create-document.php', [
            'body' => json_encode([
                'project_id' => $pid1,
                'title' => "Gutor {$needle} shape",
                'body' => "Only the {$needle} instrument for consulting hours.",
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $hit->getStatusCode(), (string)$hit->getBody());
        $miss = $api->post('/api/create-document.php', [
            'body' => json_encode([
                'project_id' => $pid2,
                'title' => "Other {$tag}",
                'body' => "no matching token here",
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $miss->getStatusCode());

        $emptyQ = $c->get('/admin/docs.php');
        $this->assertSame(200, $emptyQ->getStatusCode());
        $this->assertStringContainsString('docs-table', (string)$emptyQ->getBody());

        $search = $c->get('/admin/docs.php', ['query' => ['q' => $needle]]);
        $this->assertSame(200, $search->getStatusCode());
        $html = (string)$search->getBody();
        $this->assertStringContainsString('st-docresult', $html);
        $this->assertStringContainsString('st-hl', $html);
        $this->assertStringContainsString($needle, $html);
        $this->assertStringContainsString('st-docresult__snip', $html);

        $scoped = $c->get('/admin/docs.php', [
            'query' => ['q' => $needle, 'project_id' => $pid2],
        ]);
        $this->assertSame(200, $scoped->getStatusCode());
        $scopedHtml = (string)$scoped->getBody();
        $this->assertStringContainsString('No documents match', $scopedHtml);

        $projTab = $c->get('/admin/project.php', [
            'query' => ['id' => $pid1, 'tab' => 'docs', 'q' => $needle],
        ]);
        $this->assertSame(200, $projTab->getStatusCode());
        $projHtml = (string)$projTab->getBody();
        $this->assertStringContainsString('st-docresult', $projHtml);
        $this->assertStringContainsString($needle, $projHtml);
    }
}
