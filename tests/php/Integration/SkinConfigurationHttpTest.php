<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;
use SQLite3;

final class SkinConfigurationHttpTest extends TestCase
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

    private function startServer(): void
    {
        if ($this->server === null) {
            $this->server = PhpBuiltInServer::start();
        }
    }

    private function adminSessionClient(): array
    {
        $this->startServer();
        $db = new SQLite3($this->server->dbPath);
        $db->exec('UPDATE users SET must_change_password = 0');
        $db->close();

        $jar = new CookieJar();
        $c = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'cookies' => $jar,
        ]);

        $login = $c->post('/api/session-login.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'username' => $this->server->adminUsername,
                'password' => $this->server->adminPassword,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $loginJson = json_decode((string)$login->getBody(), true);
        $this->assertTrue($loginJson['success'] ?? false);
        $csrf = (string)($loginJson['csrf_token'] ?? '');
        $this->assertGreaterThanOrEqual(32, strlen($csrf));

        return [$c, $csrf];
    }

    public function testSaveSkinRequiresAuthAndValidSlug(): void
    {
        $this->startServer();
        $c = new Client(['base_uri' => $this->server->baseUrl, 'http_errors' => false]);

        $unauth = $c->post('/admin/save-skin.php', [
            'form_params' => ['skin_slug' => 'hey', 'csrf_token' => 'x'],
            'allow_redirects' => false,
        ]);
        $this->assertContains($unauth->getStatusCode(), [302, 303]);

        [$client, $csrf] = $this->adminSessionClient();

        $bad = $client->post('/admin/save-skin.php', [
            'form_params' => ['skin_slug' => 'retro', 'csrf_token' => $csrf],
        ]);
        $this->assertSame(400, $bad->getStatusCode());
        $badJson = json_decode((string)$bad->getBody(), true);
        $this->assertFalse($badJson['success'] ?? true);

        $ok = $client->post('/admin/save-skin.php', [
            'form_params' => ['skin_slug' => 'ledger', 'csrf_token' => $csrf],
        ]);
        $this->assertSame(200, $ok->getStatusCode());
        $okJson = json_decode((string)$ok->getBody(), true);
        $this->assertTrue($okJson['success'] ?? false);
        $this->assertSame('ledger', $okJson['skin_slug'] ?? null);
    }

    public function testAppearanceSettingsPersistsUserOverride(): void
    {
        [$client, $csrf] = $this->adminSessionClient();

        $page = $client->get('/admin/settings.php?tab=appearance');
        $this->assertSame(200, $page->getStatusCode());
        $html = (string)$page->getBody();
        $this->assertStringContainsString('Appearance', $html);
        $this->assertStringContainsString('name="skin_choice"', $html);
        $this->assertStringContainsString('value="obsidian"', $html);

        $save = $client->post('/admin/settings.php?tab=appearance', [
            'form_params' => [
                'csrf_token' => $csrf,
                'settings_action' => 'save_appearance',
                'skin_choice' => 'obsidian',
            ],
        ]);
        $this->assertSame(200, $save->getStatusCode());
        $this->assertStringContainsString('Appearance saved', (string)$save->getBody());

        $home = $client->get('/admin/');
        $this->assertSame(200, $home->getStatusCode());
        $this->assertMatchesRegularExpression('/data-skin-comp="obsidian"/', (string)$home->getBody());
    }

    public function testOrganizationsDefaultSkinPersistsAndAffectsUsersWithoutOverride(): void
    {
        [$client, $csrf] = $this->adminSessionClient();

        $orgs = $client->get('/admin/organizations.php');
        $this->assertSame(200, $orgs->getStatusCode());
        $this->assertStringContainsString('Default skin', (string)$orgs->getBody());
        $this->assertStringContainsString('name="default_skin_slug"', (string)$orgs->getBody());

        $save = $client->post('/admin/organizations.php', [
            'form_params' => [
                'csrf_token' => $csrf,
                'action' => 'default_skin',
                'org_id' => '1',
                'default_skin_slug' => 'brutalist',
            ],
        ]);
        $this->assertSame(200, $save->getStatusCode());
        $this->assertStringContainsString('default skin saved', strtolower((string)$save->getBody()));

        $db = new SQLite3($this->server->dbPath);
        $settings = (string)$db->querySingle("SELECT settings_json FROM organizations WHERE id = 1");
        $db->exec('UPDATE users SET skin_slug = NULL');
        $db->close();
        $decoded = json_decode($settings, true);
        $this->assertIsArray($decoded);
        $this->assertSame('brutalist', $decoded['default_skin_slug'] ?? null);

        $home = $client->get('/admin/');
        $this->assertSame(200, $home->getStatusCode());
        $this->assertMatchesRegularExpression('/data-skin-comp="brutalist"/', (string)$home->getBody());
    }
}
