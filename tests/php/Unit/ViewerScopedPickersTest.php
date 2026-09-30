<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ViewerScopedPickersTest extends TestCase
{
    public function testMemberAssigneePickerHidesUnrelatedUsers(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = createUser("pick_adm_{$suffix}", 'OmniPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $member = createUser("pick_mem_{$suffix}", 'OmniPass123456', 'member', false);
        $this->assertTrue($member['success']);
        $outsider = createUser("pick_out_{$suffix}", 'OmniPass123456', 'member', false);
        $this->assertTrue($outsider['success']);
        $peer = createUser("pick_peer_{$suffix}", 'OmniPass123456', 'member', false);
        $this->assertTrue($peer['success']);

        $aid = (int)$admin['id'];
        $mid = (int)$member['id'];
        $oid = (int)$outsider['id'];
        $pidPeer = (int)$peer['id'];
        $foreignOrg = createOrganization("PickOutOrg {$suffix}", $aid);
        $this->assertTrue($foreignOrg['success'], (string)($foreignOrg['error'] ?? ''));
        $this->assertTrue(setUserOrganization($aid, $oid, (int)$foreignOrg['id'])['success']);

        $proj = createDirectoryProject($aid, "PickBoard {$suffix}", null, false, false);
        $this->assertTrue($proj['success'], (string)($proj['error'] ?? ''));
        $pid = (int)$proj['id'];
        $this->assertTrue(addProjectMember($aid, $pid, $mid, 'member')['success'] ?? false);
        $this->assertTrue(addProjectMember($aid, $pid, $pidPeer, 'member')['success'] ?? false);

        $memberRow = getUserById($mid, false);
        $names = array_column(listUsersVisibleForViewer($memberRow, false), 'username');
        $this->assertContains("pick_mem_{$suffix}", $names);
        $this->assertContains("pick_peer_{$suffix}", $names);
        $this->assertNotContains("pick_out_{$suffix}", $names);

        $adminRow = getUserById($aid, false);
        $adminNames = array_column(listUsersVisibleForViewer($adminRow, false), 'username');
        // Admin created the foreign org (multi-org staff), so they can still resolve that user.
        $this->assertContains("pick_out_{$suffix}", $adminNames);
    }

    public function testMemberProjectDatalistHidesInaccessibleNames(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = createUser("lp_adm_{$suffix}", 'OmniPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $member = createUser("lp_mem_{$suffix}", 'OmniPass123456', 'member', false);
        $this->assertTrue($member['success']);
        $aid = (int)$admin['id'];
        $mid = (int)$member['id'];

        $visible = createDirectoryProject($aid, "Visible {$suffix}", null, false, false);
        $hidden = createDirectoryProject($aid, "Hidden {$suffix}", null, false, false);
        $this->assertTrue($visible['success']);
        $this->assertTrue($hidden['success']);
        $vid = (int)$visible['id'];
        $hid = (int)$hidden['id'];
        $this->assertTrue(addProjectMember($aid, $vid, $mid, 'member')['success'] ?? false);

        applySanctumSchemaMigrations(getDbConnection());
        $vList = getFirstTodoListIdForProject(getDbConnection(), $vid);
        $hList = getFirstTodoListIdForProject(getDbConnection(), $hid);
        $this->assertNotNull($vList);
        $this->assertNotNull($hList);
        $this->assertTrue(createTask("T vis {$suffix}", 'todo', $aid, null, 'b', [
            'project_id' => $vid,
            'list_id' => $vList,
        ])['success']);
        $this->assertTrue(createTask("T hid {$suffix}", 'todo', $aid, null, 'b', [
            'project_id' => $hid,
            'list_id' => $hList,
        ])['success']);

        $memberRow = getUserById($mid, false);
        $names = array_column(listProjectsForUser($memberRow, 200), 'name');
        $this->assertContains("Visible {$suffix}", $names);
        $this->assertNotContains("Hidden {$suffix}", $names);

        $global = array_column(listProjects(200), 'name');
        $this->assertContains("Hidden {$suffix}", $global);
    }
}
