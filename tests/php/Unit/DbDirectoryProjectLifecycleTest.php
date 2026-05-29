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
}
