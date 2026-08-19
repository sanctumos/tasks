<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ListAllTasksAndTodoListArchiveTest extends TestCase
{
    public function test_list_all_tasks_pages_past_five_hundred_cap(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("alltasks_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $uid = (int)$admin['id'];
        $proj = createDirectoryProject($uid, "All tasks proj {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);

        for ($i = 0; $i < 12; $i++) {
            $res = createTask("Bulk {$suffix} {$i}", 'todo', $uid, null, null, [
                'project_id' => $pid,
                'list_id' => $lid,
                'rank' => $i,
            ]);
            $this->assertTrue($res['success'], (string)($res['error'] ?? ''));
        }

        $viewer = getUserById($uid, false);
        $this->assertIsArray($viewer);
        $single = listTasks(['project_id' => $pid, 'limit' => 5, 'sort_by' => 'rank', 'sort_dir' => 'ASC'], true, null, $viewer);
        $this->assertSame(12, (int)$single['total']);
        $this->assertCount(5, $single['tasks']);

        $all = listAllTasks(['project_id' => $pid, 'sort_by' => 'rank', 'sort_dir' => 'ASC'], null, $viewer);
        $this->assertSame(12, $all['total']);
        $this->assertCount(12, $all['tasks']);
    }

    public function test_archive_hides_list_from_default_query(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("archlist_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $uid = (int)$admin['id'];
        $proj = createDirectoryProject($uid, "Archive list proj {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());

        $extra = createTodoList($uid, $pid, "Phase done {$suffix}");
        $this->assertTrue($extra['success']);
        $listId = (int)$extra['id'];

        $viewer = getUserById($uid, false);
        $this->assertIsArray($viewer);

        $activeBefore = listTodoListsForProject($viewer, $pid, false);
        $this->assertGreaterThanOrEqual(2, count($activeBefore));

        $arch = archiveTodoList($uid, $listId);
        $this->assertTrue($arch['success'], (string)($arch['error'] ?? ''));

        $activeAfter = listTodoListsForProject($viewer, $pid, false);
        $activeIds = array_map(static fn(array $r): int => (int)$r['id'], $activeAfter);
        $this->assertNotContains($listId, $activeIds);

        $all = listTodoListsForProject($viewer, $pid, true);
        $archived = array_values(array_filter($all, static fn(array $r): bool => !empty($r['archived_at'])));
        $this->assertNotEmpty($archived);

        $restore = unarchiveTodoList($uid, $listId);
        $this->assertTrue($restore['success']);
        $activeRestored = listTodoListsForProject($viewer, $pid, false);
        $restoredIds = array_map(static fn(array $r): int => (int)$r['id'], $activeRestored);
        $this->assertContains($listId, $restoredIds);
    }
}
