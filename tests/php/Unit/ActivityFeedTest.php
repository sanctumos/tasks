<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Activity timeline helpers (audit-backed, directory-scoped).
 */
final class ActivityFeedTest extends TestCase
{
    public function testProjectActivityIncludesTaskCommentTodoListCreateAndDelete(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = createUser("act_adm_{$suffix}", 'AdminPass123456', 'admin', false);
        $this->assertTrue($user['success'], (string)($user['error'] ?? 'create user'));
        $uid = (int)$user['id'];

        $proj = createDirectoryProject($uid, "ActProj {$suffix}", null, false, true);
        $this->assertTrue($proj['success'], (string)($proj['error'] ?? 'create project'));
        $pid = (int)$proj['id'];

        applySanctumSchemaMigrations(getDbConnection());
        $db = getDbConnection();
        $lid = getFirstTodoListIdForProject($db, $pid);
        $this->assertNotNull($lid);

        $extraList = createTodoList($uid, $pid, "Extra {$suffix}");
        $this->assertTrue($extraList['success'], (string)($extraList['error'] ?? 'create list'));

        $task = createTask("Keep {$suffix}", 'todo', $uid, null, 'Body', [
            'priority' => 'normal',
            'project_id' => $pid,
            'list_id' => (int)$lid,
        ]);
        $this->assertTrue($task['success'], (string)($task['error'] ?? 'create task'));
        $tid = (int)$task['id'];
        $comment = addTaskComment($tid, $uid, 'First reply');
        $this->assertIsArray($comment);
        $this->assertTrue($comment['success'] ?? false, (string)($comment['error'] ?? 'comment'));

        $toDelete = createTask("Trash {$suffix}", 'todo', $uid, null, '', [
            'priority' => 'low',
            'project_id' => $pid,
            'list_id' => (int)$lid,
        ]);
        $this->assertTrue($toDelete['success']);
        $delId = (int)$toDelete['id'];
        $delResult = deleteTask($delId);
        $this->assertTrue($delResult['success']);

        $feed = listDirectoryProjectActivity($pid, 100, null);
        $actions = array_column($feed, 'action');
        $this->assertContains('task.create', $actions);
        $this->assertContains('task.comment_add', $actions);
        $this->assertContains('todo_list.create', $actions);
        $this->assertContains('task.delete', $actions);

        $deleteRow = null;
        foreach ($feed as $row) {
            if (($row['action'] ?? '') === 'task.delete') {
                $deleteRow = $row;
                break;
            }
        }
        $this->assertNotNull($deleteRow);
        $this->assertStringContainsString('Trash', (string)($deleteRow['summary'] ?? ''));
        $this->assertStringContainsString('tab=activity', (string)($deleteRow['href'] ?? ''));
    }

    public function testActivityFeedStripForApiRemovesIp(): void
    {
        $row = [
            'id' => 1,
            'ip_address' => '10.0.0.1',
            'action' => 'task.create',
        ];
        $stripped = activityFeedStripForApi($row);
        $this->assertArrayNotHasKey('ip_address', $stripped);
    }

    public function testBeforeIdPaginationReturnsOlderSlice(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = createUser("act_pg_{$suffix}", 'AdminPass123456', 'admin', false);
        $this->assertTrue($user['success']);
        $uid = (int)$user['id'];
        $proj = createDirectoryProject($uid, "ActPg {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $db = getDbConnection();
        $lid = getFirstTodoListIdForProject($db, $pid);
        $this->assertNotNull($lid);

        for ($i = 0; $i < 5; $i++) {
            $t = createTask("Bulk {$suffix}-{$i}", 'todo', $uid, null, '', [
                'priority' => 'normal',
                'project_id' => $pid,
                'list_id' => (int)$lid,
            ]);
            $this->assertTrue($t['success']);
        }

        $page1 = listDirectoryProjectActivity($pid, 2, null);
        $this->assertCount(2, $page1);
        $oldestOnPage1 = (int)$page1[array_key_last($page1)]['id'];
        $page2 = listDirectoryProjectActivity($pid, 2, $oldestOnPage1);
        $this->assertNotEmpty($page2);
        foreach ($page2 as $r) {
            $this->assertLessThan($oldestOnPage1, (int)($r['id'] ?? 0));
        }
    }

    public function testMemberCannotViewAnotherUsersActivityFeed(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = createUser("act_a_{$suffix}", 'AdminPass123456', 'admin', false);
        $member = createUser("act_m_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($admin['success'] && $member['success']);
        $adminRow = getUserById((int)$admin['id'], false);
        $memberRow = getUserById((int)$member['id'], false);
        $this->assertNotNull($adminRow);
        $this->assertNotNull($memberRow);

        $feed = listUserActivityFeedForViewer($memberRow, (int)$adminRow['id'], 20, null);
        $this->assertNull($feed);
    }

    public function testAdminCanViewPeerActivityInSharedOrg(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $alice = createUser("act_alice_{$suffix}", 'AdminPass123456', 'admin', false);
        $bob = createUser("act_bob_{$suffix}", 'AdminPass123456', 'admin', false);
        $this->assertTrue($alice['success'] && $bob['success']);
        $aliceRow = getUserById((int)$alice['id'], false);
        $bobRow = getUserById((int)$bob['id'], false);
        $this->assertNotNull($aliceRow);
        $this->assertNotNull($bobRow);

        $aid = (int)$alice['id'];
        $bid = (int)$bob['id'];

        $proj = createDirectoryProject($aid, "Shared {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $db = getDbConnection();
        $lid = getFirstTodoListIdForProject($db, $pid);
        $this->assertNotNull($lid);

        $task = createTask("Bob task {$suffix}", 'todo', $bid, null, 'x', [
            'priority' => 'normal',
            'project_id' => $pid,
            'list_id' => (int)$lid,
        ]);
        $this->assertTrue($task['success']);

        $forAlice = listUserActivityFeedForViewer($aliceRow, $bid, 50, null);
        $this->assertIsArray($forAlice);
        $actions = array_column($forAlice, 'action');
        $this->assertContains('task.create', $actions);
    }

    public function testSelfUserActivityFeedIsAllowedForMember(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $member = createUser("act_self_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($member['success']);
        $mid = (int)$member['id'];
        $row = getUserById($mid, false);
        $this->assertNotNull($row);

        $proj = createDirectoryProject($mid, "Mine {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $db = getDbConnection();
        $lid = getFirstTodoListIdForProject($db, $pid);
        $this->assertNotNull($lid);

        $task = createTask("Solo {$suffix}", 'todo', $mid, null, '', [
            'priority' => 'normal',
            'project_id' => $pid,
            'list_id' => (int)$lid,
        ]);
        $this->assertTrue($task['success']);

        $feed = listUserActivityFeedForViewer($row, $mid, 30, null);
        $this->assertIsArray($feed);
        $this->assertContains('task.create', array_column($feed, 'action'));
    }
}
