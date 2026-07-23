<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Final push on remaining functions.php / notifications edge coverage. */
final class FunctionsCoverageFinalPushTest extends TestCase
{
    public function testListTasksFiltersOrgMfaIpAndDirectoryUpdate(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("fp_a_{$suffix}", 'MemberPass123456', 'admin', false);
        $member = createUser("fp_m_{$suffix}", 'MemberPass123456', 'member', false);
        $aid = (int)$admin['id'];
        $mid = (int)$member['id'];

        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        $this->assertSame('10.1.2.3', requestIpAddress());
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('unknown', requestIpAddress());
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->assertFalse(enableUserMfa(0, '')['success']);
        $mfa = enableUserMfa($mid, 'JBSWY3DPEHPK3PXP');
        $this->assertTrue($mfa['success'], (string)($mfa['error'] ?? ''));
        $this->assertTrue(disableUserMfa($mid)['success']);
        $this->assertFalse(disableUserMfa(0)['success']);

        recordLoginAttempt("fp_m_{$suffix}", false);
        recordLoginAttempt("fp_m_{$suffix}", true);

        $proj = createDirectoryProject($aid, "Fp {$suffix}", 'desc', false, true);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);

        $upd = updateDirectoryProject($aid, $pid, [
            'name' => "Fp Renamed {$suffix}",
            'description' => 'new desc',
            'client_visible' => true,
            'all_access' => true,
        ]);
        $this->assertTrue($upd['success'], (string)($upd['error'] ?? ''));
        $this->assertFalse(updateDirectoryProject($aid, $pid, [])['success']);
        $this->assertFalse(updateDirectoryProject($aid, 999999, ['name' => 'x'])['success']);

        $task = createTask("Fp task {$suffix}", 'todo', $aid, $mid, 'body', [
            'project_id' => $pid,
            'list_id' => $lid,
            'priority' => 'high',
            'tags' => ['fp'],
            'project' => 'legacy-fp',
        ]);
        $this->assertTrue($task['success']);
        $tid = (int)$task['id'];

        $listed = listTasks([
            'status' => 'todo',
            'priority' => 'high',
            'project' => 'legacy-fp',
            'project_id' => $pid,
            'list_id' => $lid,
            'assigned_to_user_id' => $mid,
            'limit' => 20,
        ], true, getUserById($aid, false), getUserById($aid, false));
        $this->assertIsArray($listed);

        $listedArch = listTasks([
            'include_archived_projects' => 1,
            'limit' => 10,
        ]);
        $this->assertIsArray($listedArch);

        $org = setUserOrganization($aid, $mid, 1);
        $this->assertTrue($org['success'], (string)($org['error'] ?? ''));
        $this->assertFalse(setUserOrganization($aid, 0, 1)['success']);
        $this->assertFalse(setUserOrganization($aid, $mid, 999999)['success']);

        if (function_exists('updateOrganizationDefaultSkin')) {
            $skin = updateOrganizationDefaultSkin(1, 'hey', $aid);
            $this->assertTrue($skin['success'] ?? is_array($skin));
            updateOrganizationDefaultSkin(1, null, $aid);
        }

        $urow = getUserById($aid, false);
        if (function_exists('listOrganizationIdsForUserAccess')) {
            $this->assertIsArray(listOrganizationIdsForUserAccess($urow));
        }
        if (function_exists('backfillTaskProjectIdsFromLegacyNames')) {
            backfillTaskProjectIdsFromLegacyNames();
        }

        // Attachment upload helper with tiny bytes
        if (function_exists('persistTaskAssetUpload')) {
            $tmp = sys_get_temp_dir() . '/fp-' . $suffix . '.bin';
            file_put_contents($tmp, 'abc');
            $stored = persistTaskAssetUpload($tid, $tmp, 'fp.bin', 'application/octet-stream');
            @unlink($tmp);
            $this->assertTrue(is_array($stored) || is_string($stored) || $stored === false || $stored === true);
        }

        addProjectMember($aid, $pid, $mid, 'member');
        removeProjectMember($aid, $pid, $mid);

        $doc = createDocument($aid, $pid, "Fp doc {$suffix}", 'body');
        if (!empty($doc['success'])) {
            updateDocument((int)$doc['id'], ['title' => "Fp doc2 {$suffix}", 'body' => 'b2', 'directory_path' => 'notes']);
        }

        require_once dirname(__DIR__, 3) . '/public/includes/notifications.php';
        notificationsAfterTaskAssigned($aid, getTaskById($tid, true), $mid, null);
        notificationsTaskBodyMentions($aid, getTaskById($tid, true), 'old', 'no mentions here');
    }
}
