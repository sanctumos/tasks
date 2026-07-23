<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Broad in-process coverage for previously under-exercised functions.php paths.
 */
final class FunctionsCoverageBoostTest extends TestCase
{
    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("cov_a_{$suffix}", 'MemberPass123456', 'admin', false);
        $member = createUser("cov_m_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($admin['success'] && $member['success']);
        $aid = (int)$admin['id'];
        $mid = (int)$member['id'];
        $proj = createDirectoryProject($aid, "CovProj {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);
        $this->assertNotNull($lid);
        return compact('suffix', 'aid', 'mid', 'pid', 'lid');
    }

    public function testUpdateTaskHappyPathAndValidation(): void
    {
        $f = $this->fixture();
        $task = createTask("Upd {$f['suffix']}", 'todo', $f['aid'], null, 'body', [
            'project_id' => $f['pid'],
            'list_id' => $f['lid'],
            'priority' => 'normal',
        ]);
        $this->assertTrue($task['success']);
        $tid = (int)$task['id'];

        $this->assertFalse(updateTask(0, ['title' => 'x'])['success']);
        $this->assertFalse(updateTask(999999, ['title' => 'x'])['success']);
        $this->assertFalse(updateTask($tid, [])['success']);
        $this->assertFalse(updateTask($tid, ['title' => '   '])['success']);
        $this->assertFalse(updateTask($tid, ['status' => 'not-a-status'])['success']);
        $this->assertFalse(updateTask($tid, ['priority' => 'banana'])['success']);
        $this->assertFalse(updateTask($tid, ['due_at' => 'not-a-date'])['success']);
        $this->assertFalse(updateTask($tid, ['assigned_to_user_id' => 999999])['success']);
        $this->assertFalse(updateTask($tid, ['list_id' => null])['success']);
        $this->assertFalse(updateTask($tid, ['project_id' => null])['success']);
        $this->assertFalse(updateTask($tid, ['list_id' => 999999])['success']);

        $ok = updateTask($tid, [
            'title' => "Updated {$f['suffix']}",
            'status' => 'doing',
            'body' => 'new body',
            'due_at' => '2030-01-15 12:00:00',
            'priority' => 'high',
            'tags' => ['a', 'b'],
            'rank' => 3,
            'recurrence_rule' => null,
            'assigned_to_user_id' => $f['mid'],
            'project' => 'legacy-label',
        ], $f['aid']);
        $this->assertTrue($ok['success'], (string)($ok['error'] ?? ''));

        $clearAssign = updateTask($tid, ['assigned_to_user_id' => null], $f['aid']);
        $this->assertTrue($clearAssign['success'], (string)($clearAssign['error'] ?? ''));

        $proj2 = createDirectoryProject($f['aid'], "CovProj2 {$f['suffix']}", null, false, true);
        $this->assertTrue($proj2['success']);
        $pid2 = (int)$proj2['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $move = updateTask($tid, ['project_id' => $pid2], $f['aid']);
        $this->assertTrue($move['success'], (string)($move['error'] ?? ''));
        $loaded = getTaskById($tid);
        $this->assertSame($pid2, (int)($loaded['project_id'] ?? 0));
        $this->assertGreaterThan(0, (int)($loaded['list_id'] ?? 0));
    }

    public function testBulkCreateAndUpdate(): void
    {
        $f = $this->fixture();
        $bulk = bulkCreateTasks([
            'not-array',
            [
                'title' => "Bulk1 {$f['suffix']}",
                'project_id' => $f['pid'],
                'list_id' => $f['lid'],
                'priority' => 'low',
                'tags' => ['bulk'],
            ],
            [
                'title' => '',
                'project_id' => $f['pid'],
                'list_id' => $f['lid'],
            ],
        ], $f['aid']);
        $this->assertSame(1, (int)$bulk['created']);
        $this->assertGreaterThan(0, (int)$bulk['failed']);
        $tid = 0;
        foreach ($bulk['results'] as $r) {
            if (!empty($r['success'])) {
                $tid = (int)$r['id'];
            }
        }
        $this->assertGreaterThan(0, $tid);

        $upd = bulkUpdateTasks([
            'bad',
            ['id' => 0, 'title' => 'x'],
            ['id' => $tid, 'title' => "BulkUpd {$f['suffix']}", 'status' => 'done', 'priority' => 'urgent'],
            ['id' => 999999, 'title' => 'ghost'],
        ]);
        $this->assertGreaterThan(0, (int)$upd['failed']);
        $this->assertGreaterThanOrEqual(1, (int)$upd['updated']);
        $loaded = getTaskById($tid);
        $this->assertSame("BulkUpd {$f['suffix']}", $loaded['title'] ?? null);
    }

    public function testDeleteUserForceAndGuards(): void
    {
        $f = $this->fixture();
        $victim = createUser("cov_v_{$f['suffix']}", 'MemberPass123456', 'member', false);
        $vid = (int)$victim['id'];

        $this->assertFalse(deleteUser(0)['success']);
        $self = deleteUser($f['aid'], $f['aid']);
        $this->assertFalse($self['success']);

        $task = createTask("Own {$f['suffix']}", 'todo', $vid, $vid, 'x', [
            'project_id' => $f['pid'],
            'list_id' => $f['lid'],
        ]);
        $this->assertTrue($task['success']);
        addProjectMember($f['aid'], $f['pid'], $vid, 'member');

        $blocked = deleteUser($vid, $f['aid'], false);
        $this->assertFalse($blocked['success']);
        $this->assertArrayHasKey('references', $blocked);

        $forced = deleteUser($vid, $f['aid'], true);
        $this->assertTrue($forced['success'], (string)($forced['error'] ?? ''));
        $again = deleteUser($vid, $f['aid'], true);
        $this->assertTrue($again['success']);
        $this->assertTrue(!empty($again['already_deleted']));
    }

    public function testApiKeySettingsTagsListsMembers(): void
    {
        $f = $this->fixture();
        $key = createApiKeyForUser($f['mid'], "key-{$f['suffix']}", $f['aid']);
        $this->assertIsString($key);
        $this->assertGreaterThan(20, strlen($key));

        $set = setAppSetting('cov_test_key_' . $f['suffix'], 'value-1', $f['aid']);
        $this->assertTrue($set['success'], (string)($set['error'] ?? ''));

        $tags = listTags(50);
        $this->assertIsArray($tags);

        $members = listProjectMembers($f['pid']);
        $this->assertIsArray($members);

        $add = addProjectMember($f['aid'], $f['pid'], $f['mid'], 'member');
        $this->assertTrue($add['success'], (string)($add['error'] ?? ''));

        $list2 = createTodoList($f['aid'], $f['pid'], "Extra {$f['suffix']}");
        $this->assertTrue($list2['success'], (string)($list2['error'] ?? ''));
        $lid2 = (int)$list2['id'];
        $del = deleteTodoList($f['aid'], $lid2);
        $this->assertTrue($del['success'], (string)($del['error'] ?? ''));

        $rm = removeProjectMember($f['aid'], $f['pid'], $f['mid']);
        $this->assertTrue($rm['success'], (string)($rm['error'] ?? ''));

        $adminRow = getUserById($f['aid'], false);
        $this->assertNotNull($adminRow);
        $pins = listUserProjectPinsForUser($adminRow);
        $this->assertIsArray($pins);

        $orgs = listOrganizationsWithStats();
        $this->assertIsArray($orgs);

        $users = listUsers(false);
        $this->assertNotEmpty($users);

        $docCount = countDocumentsForUser($adminRow, $f['pid']);
        $this->assertIsInt($docCount);

        $allDir = listAllDirectoryProjectsInOrganization(1);
        $this->assertIsArray($allDir);
    }

    public function testStatusesLoginLockAndRateLimit(): void
    {
        $f = $this->fixture();
        $st = createTaskStatus('cov_' . $f['suffix'], 'Cov Status', 55, false);
        $this->assertTrue($st['success'], (string)($st['error'] ?? ''));

        $lock = getLoginLockState('cov_a_' . $f['suffix']);
        $this->assertIsArray($lock);

        $apiKey = createApiKeyForUser($f['aid'], 'rl-' . $f['suffix'], $f['aid']);
        $rl = checkApiRateLimit($apiKey, 10000, 60);
        $this->assertIsArray($rl);
        $this->assertArrayHasKey('allowed', $rl);
    }
}
