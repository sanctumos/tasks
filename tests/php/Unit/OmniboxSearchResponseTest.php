<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OmniboxSearchResponseTest extends TestCase
{
    public function testBuildOmniboxSearchResponseShapesGroupsAndCounts(): void
    {
        $payload = buildOmniboxSearchResponse('ab', [
            'tasks' => [['id' => 1, 'title' => 'A', 'entity' => 'task', 'url' => '/admin/view.php?id=1']],
            'documents' => [],
            'users' => [['id' => 2, 'title' => 'alice', 'entity' => 'user', 'url' => '/admin/users.php']],
            'projects' => [['id' => 3, 'title' => 'Board', 'entity' => 'project', 'url' => '/admin/project.php?id=3']],
        ], [
            'tasks' => 12,
            'documents' => 0,
            'users' => 1,
            'projects' => 4,
        ]);

        $this->assertSame('ab', $payload['q']);
        $this->assertArrayHasKey('tasks', $payload['groups']);
        $this->assertArrayHasKey('documents', $payload['groups']);
        $this->assertArrayHasKey('users', $payload['groups']);
        $this->assertArrayHasKey('projects', $payload['groups']);
        $this->assertCount(1, $payload['groups']['tasks']);
        $this->assertSame([], $payload['groups']['documents']);
        $this->assertSame(12, $payload['counts']['tasks']);
        $this->assertSame(0, $payload['counts']['documents']);
        $this->assertSame(1, $payload['counts']['users']);
        $this->assertSame(4, $payload['counts']['projects']);
    }

    public function testBuildOmniboxSearchResponseDefaultsMissingGroups(): void
    {
        $payload = buildOmniboxSearchResponse('xy', []);
        $this->assertSame(['tasks', 'documents', 'users', 'projects'], array_keys($payload['groups']));
        foreach ($payload['groups'] as $items) {
            $this->assertSame([], $items);
        }
        foreach ($payload['counts'] as $c) {
            $this->assertSame(0, $c);
        }
    }

    public function testSearchOmniboxForUserRejectsShortQ(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("omni_{$suffix}", 'OmniPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $urow = getUserById((int)$admin['id'], false);
        $bad = searchOmniboxForUser($urow, 'a', 5);
        $this->assertFalse($bad['success'] ?? true);
        $this->assertSame(400, $bad['http'] ?? null);
    }

    public function testSearchOmniboxForUserFansOutAcrossEntities(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $needle = "omnifan{$suffix}";
        $admin = createUser("omni_a_{$suffix}", 'OmniPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $uid = (int)$admin['id'];
        $peer = createUser("omni_peer_{$suffix}", 'OmniPass123456', 'member', false);
        $this->assertTrue($peer['success']);

        $proj = createDirectoryProject($uid, "Board {$needle}", null, false, true);
        $this->assertTrue($proj['success'], (string)($proj['error'] ?? ''));
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);
        $this->assertNotNull($lid);

        $task = createTask("Task {$needle}", 'todo', $uid, null, "body {$needle}", [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $this->assertTrue($task['success'], (string)($task['error'] ?? ''));

        $doc = createDocument($uid, $pid, "Doc {$needle}", "doc body {$needle}");
        $this->assertTrue($doc['success'], (string)($doc['error'] ?? ''));

        $urow = getUserById($uid, false);
        $hit = searchOmniboxForUser($urow, $needle, 5);
        $this->assertArrayNotHasKey('success', $hit);
        $this->assertSame($needle, $hit['q']);
        $this->assertGreaterThanOrEqual(1, $hit['counts']['tasks']);
        $this->assertGreaterThanOrEqual(1, $hit['counts']['documents']);
        $this->assertGreaterThanOrEqual(1, $hit['counts']['projects']);
        $taskTitles = array_column($hit['groups']['tasks'], 'title');
        $this->assertTrue((bool)array_filter($taskTitles, static fn ($t) => str_contains((string)$t, $needle)));
        $docTitles = array_column($hit['groups']['documents'], 'title');
        $this->assertTrue((bool)array_filter($docTitles, static fn ($t) => str_contains((string)$t, $needle)));
        $projTitles = array_column($hit['groups']['projects'], 'title');
        $this->assertTrue((bool)array_filter($projTitles, static fn ($t) => str_contains((string)$t, $needle)));
        foreach ($hit['groups']['tasks'] as $item) {
            $this->assertSame('task', $item['entity']);
            $this->assertStringContainsString('/admin/view.php?id=', $item['url']);
        }

        // Username match via peer suffix fragment (min 2 chars already satisfied by needle-like peer name)
        $userHit = searchOmniboxForUser($urow, "omni_peer_{$suffix}", 5);
        $userNames = array_column($userHit['groups']['users'], 'title');
        $this->assertContains("omni_peer_{$suffix}", $userNames);

        $miss = searchOmniboxForUser($urow, 'zzznomatch' . $suffix, 5);
        $this->assertSame(0, $miss['counts']['tasks']);
        $this->assertSame([], $miss['groups']['tasks']);

        $capped = searchOmniboxForUser($urow, $needle, 100);
        $this->assertLessThanOrEqual(20, count($capped['groups']['tasks']));
    }
}
