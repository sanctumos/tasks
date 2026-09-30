<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use SanctumTasks\Tests\Support\PhpBuiltInServer;

/**
 * Wire-level ACL for search: /api/search-users.php, /api/search.php, /api/search-tasks.php,
 * and session /admin/search.php as a non-admin member.
 */
final class SearchAclHttpTest extends TestCase
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

    public function testSearchUsersAndOmniboxHttpRespectMemberScope(): void
    {
        $this->server = PhpBuiltInServer::start();
        $admin = new Client([
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
        ]))->get('/api/search-users.php', ['query' => ['q' => 'ab']]);
        $this->assertSame(401, $unauth->getStatusCode());

        $tag = bin2hex(random_bytes(4));
        $memberUser = "http_mem_{$tag}";
        $peerUser = "http_peer_{$tag}";
        $outUser = "http_out_{$tag}";
        $memberPass = 'MemberPass123456';

        $memberCreate = $admin->post('/api/create-user.php', [
            'body' => json_encode([
                'username' => $memberUser,
                'password' => $memberPass,
                'role' => 'member',
                'must_change_password' => false,
                'create_api_key' => true,
                'api_key_name' => 'member-search',
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $memberCreate->getStatusCode(), (string)$memberCreate->getBody());
        $memberPayload = json_decode((string)$memberCreate->getBody(), true);
        $memberKey = (string)($memberPayload['data']['api_key'] ?? $memberPayload['api_key'] ?? '');
        $memberId = (int)($memberPayload['data']['user']['id'] ?? $memberPayload['user']['id'] ?? 0);
        $this->assertNotSame('', $memberKey);
        $this->assertGreaterThan(0, $memberId);

        $peerCreate = $admin->post('/api/create-user.php', [
            'body' => json_encode([
                'username' => $peerUser,
                'password' => $memberPass,
                'role' => 'member',
                'must_change_password' => false,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $peerCreate->getStatusCode(), (string)$peerCreate->getBody());
        $peerId = (int)(json_decode((string)$peerCreate->getBody(), true)['data']['user']['id']
            ?? json_decode((string)$peerCreate->getBody(), true)['user']['id']
            ?? 0);

        $outCreateUser = $admin->post('/api/create-user.php', [
            'body' => json_encode([
                'username' => $outUser,
                'password' => $memberPass,
                'role' => 'member',
                'must_change_password' => false,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $outCreateUser->getStatusCode(), (string)$outCreateUser->getBody());
        $outId = (int)(json_decode((string)$outCreateUser->getBody(), true)['data']['user']['id']
            ?? json_decode((string)$outCreateUser->getBody(), true)['user']['id']
            ?? 0);
        $this->assertGreaterThan(0, $peerId);
        $this->assertGreaterThan(0, $outId);

        $proj = $admin->post('/api/create-directory-project.php', [
            'body' => json_encode([
                'name' => "HttpAcl {$tag}",
                'all_access' => false,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $proj->getStatusCode(), (string)$proj->getBody());
        $projectId = (int)(json_decode((string)$proj->getBody(), true)['data']['project']['id'] ?? 0);

        foreach ([$memberId, $peerId] as $uid) {
            $add = $admin->post('/api/add-project-member.php', [
                'body' => json_encode([
                    'project_id' => $projectId,
                    'user_id' => $uid,
                    'role' => 'member',
                ], JSON_THROW_ON_ERROR),
            ]);
            $this->assertSame(200, $add->getStatusCode(), (string)$add->getBody());
        }

        $lists = $admin->get('/api/list-todo-lists.php', ['query' => ['project_id' => $projectId]]);
        $listId = (int)(json_decode((string)$lists->getBody(), true)['data']['todo_lists'][0]['id']
            ?? json_decode((string)$lists->getBody(), true)['todo_lists'][0]['id']
            ?? 0);
        $this->assertGreaterThan(0, $listId);

        $secretNeedle = "sechttp{$tag}";
        $task = $admin->post('/api/create-task.php', [
            'body' => json_encode([
                'title' => "Task {$secretNeedle}",
                'status' => 'todo',
                'project_id' => $projectId,
                'list_id' => $listId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $task->getStatusCode(), (string)$task->getBody());
        $taskId = (int)(json_decode((string)$task->getBody(), true)['data']['task']['id']
            ?? json_decode((string)$task->getBody(), true)['task']['id']
            ?? 0);

        // Private board outsider is not on — create a second board only admin+out share conceptually:
        // outsider has no membership on $projectId; member is on it.
        $member = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => [
                'X-API-Key' => $memberKey,
                'Content-Type' => 'application/json',
            ],
        ]);

        $peerHit = $member->get('/api/search-users.php', ['query' => ['q' => $peerUser, 'limit' => 10]]);
        $this->assertSame(200, $peerHit->getStatusCode(), (string)$peerHit->getBody());
        $peerNames = array_column(
            json_decode((string)$peerHit->getBody(), true)['data']['users']
                ?? json_decode((string)$peerHit->getBody(), true)['users']
                ?? [],
            'username'
        );
        $this->assertContains($peerUser, $peerNames);

        $outMiss = $member->get('/api/search-users.php', ['query' => ['q' => $outUser, 'limit' => 10]]);
        $this->assertSame(200, $outMiss->getStatusCode(), (string)$outMiss->getBody());
        $outNames = array_column(
            json_decode((string)$outMiss->getBody(), true)['data']['users']
                ?? json_decode((string)$outMiss->getBody(), true)['users']
                ?? [],
            'username'
        );
        $this->assertNotContains($outUser, $outNames);
        $this->assertSame([], $outNames);

        $omnibox = $member->get('/api/search.php', ['query' => ['q' => $outUser, 'limit' => 5]]);
        $this->assertSame(200, $omnibox->getStatusCode(), (string)$omnibox->getBody());
        $omniData = json_decode((string)$omnibox->getBody(), true)['data']
            ?? json_decode((string)$omnibox->getBody(), true);
        $this->assertSame([], $omniData['groups']['users'] ?? null);
        $this->assertSame(0, (int)($omniData['counts']['users'] ?? -1));

        $taskOmni = $member->get('/api/search.php', ['query' => ['q' => $secretNeedle, 'limit' => 5]]);
        $this->assertSame(200, $taskOmni->getStatusCode(), (string)$taskOmni->getBody());
        $taskData = json_decode((string)$taskOmni->getBody(), true)['data']
            ?? json_decode((string)$taskOmni->getBody(), true);
        $taskTitles = array_column($taskData['groups']['tasks'] ?? [], 'title');
        $this->assertTrue((bool)array_filter($taskTitles, static fn ($t) => str_contains((string)$t, $secretNeedle)));

        $searchTasks = $member->get('/api/search-tasks.php', ['query' => ['q' => $secretNeedle]]);
        $this->assertSame(200, $searchTasks->getStatusCode(), (string)$searchTasks->getBody());
        $stPayload = json_decode((string)$searchTasks->getBody(), true);
        $stTasks = $stPayload['data']['tasks'] ?? $stPayload['tasks'] ?? [];
        $stIds = array_map(static fn (array $t): int => (int)($t['id'] ?? 0), $stTasks);
        $this->assertContains($taskId, $stIds);

        // Outsider API key: cannot see the private-board task
        $outCreate = $admin->post('/api/create-api-key.php', [
            'body' => json_encode([
                'user_id' => $outId,
                'key_name' => 'out-search',
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(201, $outCreate->getStatusCode(), (string)$outCreate->getBody());
        $outKey = (string)(json_decode((string)$outCreate->getBody(), true)['data']['api_key']
            ?? json_decode((string)$outCreate->getBody(), true)['api_key']
            ?? '');
        $outsider = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'headers' => [
                'X-API-Key' => $outKey,
                'Content-Type' => 'application/json',
            ],
        ]);
        $outTasks = $outsider->get('/api/search-tasks.php', ['query' => ['q' => $secretNeedle]]);
        $this->assertSame(200, $outTasks->getStatusCode(), (string)$outTasks->getBody());
        $outTaskPayload = json_decode((string)$outTasks->getBody(), true);
        $outTaskRows = $outTaskPayload['data']['tasks'] ?? $outTaskPayload['tasks'] ?? [];
        $outTaskIds = array_map(static fn (array $t): int => (int)($t['id'] ?? 0), $outTaskRows);
        $this->assertNotContains($taskId, $outTaskIds);

        $adminOmniPeople = $admin->get('/api/search.php', ['query' => ['q' => $memberUser, 'limit' => 5]]);
        $this->assertSame(200, $adminOmniPeople->getStatusCode());
        $adminPeople = json_decode((string)$adminOmniPeople->getBody(), true)['data']['groups']['users']
            ?? json_decode((string)$adminOmniPeople->getBody(), true)['groups']['users']
            ?? [];
        $this->assertContains($memberUser, array_column($adminPeople, 'title'));

        // Session: member full search page must not render People for outsider
        $db = new \SQLite3($this->server->dbPath);
        $db->exec("UPDATE users SET must_change_password = 0 WHERE username = '" . $db->escapeString($memberUser) . "'");
        $db->close();

        $jar = new CookieJar();
        $session = new Client([
            'base_uri' => $this->server->baseUrl,
            'http_errors' => false,
            'cookies' => $jar,
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $login = $session->post('/api/session-login.php', [
            'body' => json_encode([
                'username' => $memberUser,
                'password' => $memberPass,
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(200, $login->getStatusCode(), (string)$login->getBody());

        $page = $session->get('/admin/search.php', ['query' => ['q' => $outUser]]);
        $this->assertSame(200, $page->getStatusCode());
        $html = (string)$page->getBody();
        $this->assertStringNotContainsString('data-group="users"', $html);
        $this->assertStringNotContainsString('/admin/users.php?q=', $html);
    }
}
