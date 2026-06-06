<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TaskScopeListTasksTest extends TestCase
{
    public function testListTasksWithLegacyProjectFilterHonorsDirectoryScopeUser(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $legacyProjectName = 'legacy-scope-' . $suffix;

        $u1 = createUser('scope_u1_' . $suffix, 'MemberPass123456', 'member', false);
        $u2 = createUser('scope_u2_' . $suffix, 'MemberPass123456', 'member', false);
        $this->assertTrue($u1['success']);
        $this->assertTrue($u2['success']);
        $uid1 = (int)$u1['id'];
        $uid2 = (int)$u2['id'];

        $p1 = createDirectoryProject($uid1, 'Scope A ' . $suffix, null, false, false);
        $p2 = createDirectoryProject($uid2, 'Scope B ' . $suffix, null, false, false);
        $this->assertTrue($p1['success']);
        $this->assertTrue($p2['success']);
        $pid1 = (int)$p1['id'];
        $pid2 = (int)$p2['id'];

        $db = getDbConnection();
        applySanctumSchemaMigrations($db);
        $list1 = getFirstTodoListIdForProject($db, $pid1);
        $list2 = getFirstTodoListIdForProject($db, $pid2);
        $this->assertNotNull($list1);
        $this->assertNotNull($list2);

        $t1 = createTask('Scope task one ' . $suffix, 'todo', $uid1, null, 'a', [
            'project_id' => $pid1,
            'project' => $legacyProjectName,
            'list_id' => $list1,
        ]);
        $t2 = createTask('Scope task two ' . $suffix, 'todo', $uid2, null, 'b', [
            'project_id' => $pid2,
            'project' => $legacyProjectName,
            'list_id' => $list2,
        ]);
        $this->assertTrue($t1['success']);
        $this->assertTrue($t2['success']);

        $stmt = $db->prepare('UPDATE tasks SET project = :project WHERE id = :id');
        $stmt->bindValue(':project', $legacyProjectName, SQLITE3_TEXT);
        $stmt->bindValue(':id', (int)$t1['id'], SQLITE3_INTEGER);
        $stmt->execute();
        $stmt = $db->prepare('UPDATE tasks SET project = :project WHERE id = :id');
        $stmt->bindValue(':project', $legacyProjectName, SQLITE3_TEXT);
        $stmt->bindValue(':id', (int)$t2['id'], SQLITE3_INTEGER);
        $stmt->execute();

        $viewer = getUserById($uid1, false);
        $this->assertIsArray($viewer);
        $result = listTasks(['project' => $legacyProjectName, 'limit' => 100], true, null, $viewer);
        $tasks = $result['tasks'] ?? [];
        $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $tasks);
        $this->assertContains((int)$t1['id'], $ids);
        $this->assertNotContains((int)$t2['id'], $ids);
    }
}

