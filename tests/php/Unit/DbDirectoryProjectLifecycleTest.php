<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Directory project lifecycle: active / archived / trashed listing rules.
 */
final class DbDirectoryProjectLifecycleTest extends TestCase
{
    public function testListDirectoryProjectsHidesArchivedByDefault(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = createUser("dir_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($user['success'], (string)($user['error'] ?? 'create user'));
        $uid = (int)$user['id'];
        $full = getUserById($uid, false);
        $this->assertNotNull($full);

        $active = createDirectoryProject($uid, "Active {$suffix}", null, false, true);
        $this->assertTrue($active['success'], (string)($active['error'] ?? 'create active'));
        $archived = createDirectoryProject($uid, "Archived {$suffix}", null, false, true);
        $this->assertTrue($archived['success'], (string)($archived['error'] ?? 'create archived'));
        $archivedId = (int)$archived['id'];

        $archive = updateDirectoryProject($uid, $archivedId, ['status' => 'archived']);
        $this->assertTrue($archive['success'], (string)($archive['error'] ?? 'archive'));

        $defaultList = listDirectoryProjectsForUser($full, 200);
        $defaultNames = array_column($defaultList, 'name');
        $this->assertContains("Active {$suffix}", $defaultNames);
        $this->assertNotContains("Archived {$suffix}", $defaultNames);

        $withArchived = listDirectoryProjectsForUser($full, 200, ['include_archived' => true]);
        $allNames = array_column($withArchived, 'name');
        $this->assertContains("Active {$suffix}", $allNames);
        $this->assertContains("Archived {$suffix}", $allNames);

        $accessible = getAccessibleDirectoryProjectIdsForUser($full);
        $this->assertContains($archivedId, $accessible, 'archived projects remain accessible for existing work');
    }

    public function testListTasksExcludesArchivedProjectTasksUnlessScoped(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = createUser("dir_t_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($user['success']);
        $uid = (int)$user['id'];
        $full = getUserById($uid, false);
        $this->assertNotNull($full);

        $active = createDirectoryProject($uid, "ActiveT {$suffix}", null, false, true);
        $archived = createDirectoryProject($uid, "ArchivedT {$suffix}", null, false, true);
        $this->assertTrue($active['success'] && $archived['success']);
        $activeId = (int)$active['id'];
        $archivedId = (int)$archived['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $activeLid = getFirstTodoListIdForProject(getDbConnection(), $activeId);
        $archLid = getFirstTodoListIdForProject(getDbConnection(), $archivedId);
        $this->assertNotNull($activeLid);
        $this->assertNotNull($archLid);

        $tActive = createTask("Keep {$suffix}", 'todo', $uid, null, 'x', [
            'priority' => 'normal',
            'project_id' => $activeId,
            'list_id' => $activeLid,
        ]);
        $tArch = createTask("Hide {$suffix}", 'todo', $uid, null, 'x', [
            'priority' => 'normal',
            'project_id' => $archivedId,
            'list_id' => $archLid,
        ]);
        $this->assertTrue($tActive['success'] && $tArch['success']);
        $this->assertTrue(updateDirectoryProject($uid, $archivedId, ['status' => 'archived'])['success']);

        $home = listTasks(['limit' => 200, 'q' => $suffix], true, null, $full);
        $titles = array_column($home['tasks'], 'title');
        $this->assertContains("Keep {$suffix}", $titles);
        $this->assertNotContains("Hide {$suffix}", $titles, 'archived board tasks must not appear in cross-project all tasks');

        $scoped = listTasks(['project_id' => $archivedId, 'limit' => 50], true, null, $full);
        $scopedTitles = array_column($scoped['tasks'], 'title');
        $this->assertContains("Hide {$suffix}", $scopedTitles, 'project workspace still lists its own archived-board tasks');
    }
}
