<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Search / mention / omnibox must follow the same org + membership directory model
 * as documents and boards (no global user or cross-org task dumps).
 */
final class SearchDirectoryAclTest extends TestCase
{
    public function testMentionSearchHidesUsersOutsideViewerScope(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = createUser("acl_adm_{$suffix}", 'OmniPass123456', 'admin', false);
        $member = createUser("acl_mem_{$suffix}", 'OmniPass123456', 'member', false);
        $outsider = createUser("acl_out_{$suffix}", 'OmniPass123456', 'member', false);
        $peer = createUser("acl_peer_{$suffix}", 'OmniPass123456', 'member', false);
        $this->assertTrue($admin['success'] && $member['success'] && $outsider['success'] && $peer['success']);

        $aid = (int)$admin['id'];
        $mid = (int)$member['id'];
        $oid = (int)$outsider['id'];
        // Isolate outsider in another org so prior all_access boards in org 1 cannot leak them.
        $foreignOrg = createOrganization("AclOutOrg {$suffix}", $aid);
        $this->assertTrue($foreignOrg['success'], (string)($foreignOrg['error'] ?? ''));
        $this->assertTrue(setUserOrganization($aid, $oid, (int)$foreignOrg['id'])['success']);

        $proj = createDirectoryProject($aid, "AclBoard {$suffix}", null, false, false);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        $this->assertTrue(addProjectMember($aid, $pid, $mid, 'member')['success'] ?? false);
        $this->assertTrue(addProjectMember($aid, $pid, (int)$peer['id'], 'member')['success'] ?? false);

        $memberRow = getUserById($mid, false);
        $hit = searchUsersVisibleForViewer($memberRow, 'acl_peer_' . $suffix, 10, 0);
        $this->assertContains('acl_peer_' . $suffix, array_column($hit['users'], 'username'));

        $miss = searchUsersVisibleForViewer($memberRow, 'acl_out_' . $suffix, 10, 0);
        $this->assertSame([], $miss['users']);
        $this->assertSame(0, $miss['total']);
    }

    public function testManagerOmniboxDoesNotLeakForeignOrgTasksOrPeople(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = createUser("mgracl_a_{$suffix}", 'OmniPass123456', 'admin', false);
        $manager = createUser("mgracl_m_{$suffix}", 'OmniPass123456', 'manager', false);
        $foreign = createUser("mgracl_f_{$suffix}", 'OmniPass123456', 'member', false);
        $this->assertTrue($admin['success'] && $manager['success'] && $foreign['success']);

        $aid = (int)$admin['id'];
        $mgrId = (int)$manager['id'];
        $fid = (int)$foreign['id'];

        $homeOrg = (int)(getUserById($mgrId, false)['org_id'] ?? 0);
        $this->assertGreaterThan(0, $homeOrg);
        $foreignOrg = createOrganization("ForeignAcl {$suffix}", $aid);
        $this->assertTrue($foreignOrg['success'], (string)($foreignOrg['error'] ?? ''));
        $foid = (int)$foreignOrg['id'];

        $this->assertTrue(setUserOrganization($aid, $fid, $foid)['success']);
        $this->assertTrue(replaceStaffOrganizationMemberships($aid, $mgrId, [$homeOrg])['success']);

        // Session org for project create follows creator; foreign user creates in foreign org.
        $fProj = createDirectoryProject($fid, "ForeignBoard {$suffix}", null, false, true);
        $this->assertTrue($fProj['success'], (string)($fProj['error'] ?? ''));
        $fpid = (int)$fProj['id'];
        $this->assertSame($foid, (int)(getDirectoryProjectById($fpid)['org_id'] ?? 0));

        applySanctumSchemaMigrations(getDbConnection());
        $listId = getFirstTodoListIdForProject(getDbConnection(), $fpid);
        $this->assertNotNull($listId);
        $needle = "mgraclneedle{$suffix}";
        $task = createTask("Task {$needle}", 'todo', $fid, null, 'body', [
            'project_id' => $fpid,
            'list_id' => $listId,
        ]);
        $this->assertTrue($task['success']);

        $mgrRow = getUserById($mgrId, false);
        $this->assertTrue(userHasUnrestrictedOrgDirectoryAccess($mgrRow));
        $this->assertNotContains($foid, listOrganizationIdsForUserAccess($mgrRow));

        $tasks = listTasks(['q' => $needle, 'limit' => 50], true, $mgrRow, $mgrRow);
        $ids = array_map(static fn (array $t): int => (int)($t['id'] ?? 0), $tasks['tasks'] ?? []);
        $this->assertNotContains((int)$task['id'], $ids);

        $omnibox = searchOmniboxForUser($mgrRow, $needle, 10);
        $this->assertSame(0, $omnibox['counts']['tasks'] ?? -1);
        $people = searchUsersVisibleForViewer($mgrRow, 'mgracl_f_' . $suffix, 10, 0);
        $this->assertSame([], $people['users']);

        $adminRow = getUserById($aid, false);
        $adminTasks = listTasks(['q' => $needle, 'limit' => 50], true, $adminRow, $adminRow);
        $adminIds = array_map(static fn (array $t): int => (int)($t['id'] ?? 0), $adminTasks['tasks'] ?? []);
        $this->assertContains((int)$task['id'], $adminIds);
    }

    public function testOmniboxPeopleUsesViewerScopedDirectory(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = createUser("ompeople_a_{$suffix}", 'OmniPass123456', 'admin', false);
        $member = createUser("ompeople_m_{$suffix}", 'OmniPass123456', 'member', false);
        $this->assertTrue($admin['success'] && $member['success']);

        $memberRow = getUserById((int)$member['id'], false);
        $asMember = searchOmniboxForUser($memberRow, 'ompeople_a_' . $suffix, 5);
        $this->assertSame([], $asMember['groups']['users']);
        $this->assertSame(0, $asMember['counts']['users']);

        $adminRow = getUserById((int)$admin['id'], false);
        $asAdmin = searchOmniboxForUser($adminRow, 'ompeople_m_' . $suffix, 5);
        $this->assertContains('ompeople_m_' . $suffix, array_column($asAdmin['groups']['users'], 'title'));
    }
}
