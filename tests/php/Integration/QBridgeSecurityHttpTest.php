<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use PDO;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

final class QBridgeSecurityHttpTest extends TestCase
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

    public function testResponsesRequirePollKeyOrOwnedTasksSession(): void
    {
        $this->server = PhpBuiltInServer::start([
            'TASKS_Q_BRIDGE_POLL_API_KEY' => 'poll_key_secure_for_tests',
            'TASKS_Q_BRIDGE_ADMIN_KEY' => 'admin_key_secure_for_tests',
        ]);
        $client = new Client(['base_uri' => $this->server->baseUrl, 'http_errors' => false]);

        $sessionId = 'session_tasks_9999';
        $this->seedQBridgeSessionAndResponse($sessionId, 'secret response payload');

        $unauthorized = $client->get('/q-bridge/api/v1/index.php', [
            'query' => ['action' => 'responses', 'session_id' => $sessionId],
        ]);
        $this->assertSame(401, $unauthorized->getStatusCode(), (string)$unauthorized->getBody());

        $authorized = $client->get('/q-bridge/api/v1/index.php', [
            'headers' => ['Authorization' => 'Bearer poll_key_secure_for_tests'],
            'query' => ['action' => 'responses', 'session_id' => $sessionId],
        ]);
        $this->assertSame(200, $authorized->getStatusCode(), (string)$authorized->getBody());
        $payload = json_decode((string)$authorized->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['success'] ?? false);
        $responses = $payload['data']['responses'] ?? [];
        $this->assertCount(1, $responses);
        $this->assertSame('secret response payload', (string)($responses[0]['response'] ?? ''));
    }

    public function testConfigEndpointNoLongerReturnsSecretsAndRejectsPostMutation(): void
    {
        $this->server = PhpBuiltInServer::start([
            'TASKS_Q_BRIDGE_POLL_API_KEY' => 'poll_key_for_config_test',
            'TASKS_Q_BRIDGE_ADMIN_KEY' => 'admin_key_for_config_test',
        ]);
        $client = new Client(['base_uri' => $this->server->baseUrl, 'http_errors' => false]);

        $config = $client->get('/q-bridge/api/v1/index.php', [
            'headers' => ['Authorization' => 'Bearer admin_key_for_config_test'],
            'query' => ['action' => 'config'],
        ]);
        $this->assertSame(200, $config->getStatusCode(), (string)$config->getBody());
        $payload = json_decode((string)$config->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['success'] ?? false);
        $data = $payload['data'] ?? [];
        $this->assertArrayNotHasKey('api_key', $data);
        $this->assertArrayNotHasKey('admin_key', $data);
        $this->assertTrue((bool)($data['poll_key_configured'] ?? false));
        $this->assertTrue((bool)($data['admin_key_configured'] ?? false));

        $post = $client->post('/q-bridge/api/v1/index.php?action=config', [
            'headers' => ['Authorization' => 'Bearer admin_key_for_config_test', 'Content-Type' => 'application/json'],
            'body' => json_encode(['api_key' => 'newkey', 'admin_key' => 'newadm'], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(405, $post->getStatusCode(), (string)$post->getBody());
    }

    public function testMissingEnvKeysDoNotAcceptLegacyFallbackSecrets(): void
    {
        $this->server = PhpBuiltInServer::start([
            'TASKS_Q_BRIDGE_POLL_API_KEY' => '',
            'TASKS_Q_BRIDGE_ADMIN_KEY' => '',
            'WEB_CHAT_API_KEY' => '',
            'WEB_CHAT_ADMIN_KEY' => '',
        ]);
        $client = new Client(['base_uri' => $this->server->baseUrl, 'http_errors' => false]);

        $pollFallbackAttempt = $client->get('/q-bridge/api/v1/index.php', [
            'headers' => ['Authorization' => 'Bearer CHANGE_ME_Q_BRIDGE_POLL_KEY'],
            'query' => ['action' => 'inbox'],
        ]);
        $this->assertSame(401, $pollFallbackAttempt->getStatusCode(), (string)$pollFallbackAttempt->getBody());

        $adminFallbackAttempt = $client->get('/q-bridge/api/v1/index.php', [
            'headers' => ['Authorization' => 'Bearer free0ps'],
            'query' => ['action' => 'sessions'],
        ]);
        $this->assertSame(401, $adminFallbackAttempt->getStatusCode(), (string)$adminFallbackAttempt->getBody());
    }

    public function testSessionsRouteRateLimitIsEnforced(): void
    {
        $this->server = PhpBuiltInServer::start([
            'TASKS_Q_BRIDGE_POLL_API_KEY' => 'poll_key_rate_test',
            'TASKS_Q_BRIDGE_ADMIN_KEY' => 'admin_key_rate_test',
        ]);
        $client = new Client(['base_uri' => $this->server->baseUrl, 'http_errors' => false]);

        $statusCodes = [];
        for ($i = 0; $i < 205; $i++) {
            $resp = $client->get('/q-bridge/api/v1/index.php', [
                'headers' => ['Authorization' => 'Bearer admin_key_rate_test'],
                'query' => ['action' => 'sessions'],
            ]);
            $statusCodes[] = $resp->getStatusCode();
        }

        $this->assertContains(429, $statusCodes, 'Expected at least one HTTP 429 from q-bridge sessions rate limit');
    }

    private function seedQBridgeSessionAndResponse(string $sessionId, string $responseText): void
    {
        $qBridgeDbPath = dirname($this->server->dbPath) . '/q_bridge_webchat.db';
        $pdo = new PDO('sqlite:' . $qBridgeDbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS web_chat_sessions (
                id VARCHAR(64) PRIMARY KEY,
                uid VARCHAR(16) UNIQUE,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_active TEXT DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45),
                metadata TEXT
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS web_chat_responses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id VARCHAR(64),
                response TEXT,
                timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                message_id INTEGER NULL
            )
        ");

        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO web_chat_sessions (id, uid, created_at, last_active, ip_address, metadata)
            VALUES (:id, :uid, datetime('now'), datetime('now'), '127.0.0.1', NULL)
        ");
        $stmt->execute([
            ':id' => $sessionId,
            ':uid' => null,
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO web_chat_responses (session_id, response, timestamp, message_id)
            VALUES (:sid, :resp, datetime('now'), NULL)
        ");
        $stmt->execute([
            ':sid' => $sessionId,
            ':resp' => $responseText,
        ]);
    }
}

