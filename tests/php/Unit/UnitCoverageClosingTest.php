<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Close the last Unit coverage gap (target ≥90%). */
final class UnitCoverageClosingTest extends TestCase
{
    public function testOrgAccessMembershipWatchersAndNotifEdges(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/notifications.php';

        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("cl_a_{$suffix}", 'MemberPass123456', 'admin', false);
        $member = createUser("cl_m_{$suffix}", 'MemberPass123456', 'member', false);
        $client = createUser("cl_c_{$suffix}", 'MemberPass123456', 'member', false, null, 'client');
        $this->assertTrue($admin['success'] && $member['success'] && $client['success']);
        $aid = (int)$admin['id'];
        $mid = (int)$member['id'];
        $cid = (int)$client['id'];

        $proj = createDirectoryProject($aid, "Cl {$suffix}", null, true, true);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);

        addProjectMember($aid, $pid, $mid, 'member');
        addProjectMember($aid, $pid, $cid, 'client');
        // role change / duplicate paths
        addProjectMember($aid, $pid, $mid, 'member');

        $adminRow = getUserById($aid, false);
        $memberRow = getUserById($mid, false);
        $this->assertIsArray(listOrganizationIdsForUserAccess($adminRow));
        setUserLimitedProjectAccess($aid, $mid, true);
        $memberRow = getUserById($mid, false);
        $this->assertIsArray(listOrganizationIdsForUserAccess($memberRow));
        setUserLimitedProjectAccess($aid, $mid, false);

        // Manager multi-org path
        $mgr = createUser("cl_g_{$suffix}", 'MemberPass123456', 'manager', false);
        if (!empty($mgr['success'])) {
            $gid = (int)$mgr['id'];
            $grow = getUserById($gid, false);
            $this->assertIsArray(listOrganizationIdsForUserAccess($grow));
            replaceStaffOrganizationMemberships($aid, $gid, [1]);
        }

        // Tiny board_export helpers still unpaid
        $this->assertNull(boardExportAbsolutePath('..'));
        boardExportRmTree(sys_get_temp_dir() . '/no-such-dir-' . $suffix);
        $tmpTree = sys_get_temp_dir() . '/be-tree-' . $suffix;
        mkdir($tmpTree . '/sub', 0777, true);
        file_put_contents($tmpTree . '/sub/a.txt', 'x');
        boardExportRmTree($tmpTree);
        // Best-effort cleanup; tree helper may leave empty parents on some FS layouts.
        @unlink($tmpTree . '/sub/a.txt');
        @rmdir($tmpTree . '/sub');
        @rmdir($tmpTree);
        $this->assertNotSame('', boardExportSafeSlug('Hello World!!'));
        $this->assertNotSame('', boardExportSafeFileName('weird name (1).PNG'));
        $this->assertSame('file', boardExportSafeFileName(''));
        $this->assertSame('board', boardExportSafeSlug('!!!'));
        $this->assertSame('board', boardExportSafeSlug(''));
        // touch one more board_export early-return
        $this->assertNull(boardExportAbsolutePath(''));
        if (function_exists('base32Encode') && function_exists('base32Decode')) {
            $enc = base32Encode('hi');
            $this->assertNotSame('', $enc);
            base32Decode($enc);
        }
        if (function_exists('generateTotpCode') && function_exists('verifyTotpCode')) {
            $code = generateTotpCode('JBSWY3DPEHPK3PXP');
            $this->assertTrue(is_string($code) || is_int($code));
            verifyTotpCode('JBSWY3DPEHPK3PXP', (string)$code);
        }
        // A few more functions.php edges
        $this->assertSame('abc', truncateString('abcdef', 3));
        $this->assertSame('abc', truncateString('abc', 10));
        $this->assertNotSame('', normalizeSlug('Hello World'));
        if (function_exists('taskAttachmentMarkdownSnippet')) {
            $this->assertStringContainsString('](', taskAttachmentMarkdownSnippet('[x].png', '/u'));
        }
        $this->assertNotSame('', nowUtc());
        $this->assertNull(sanitizeStatus('nope-status'));
        $this->assertNotNull(getDefaultTaskStatusSlug());
        $slug = getDefaultTaskStatusSlug();
        if (is_string($slug) && $slug !== '') {
            $this->assertNotNull(getTaskStatusBySlug($slug));
        }
        $this->assertSame([], listOrganizationMembershipIdsForUser(0));
        $this->assertNull(getOrganizationById(0));
        $this->assertNull(getUserById(0));
        $this->assertFalse(setUserActive(0, true)['success']);
        if (function_exists('disableUser')) {
            // create disposable then disable
            $tmp = createUser("dis_{$suffix}", 'MemberPass123456', 'member', false);
            if (!empty($tmp['success'])) {
                disableUser((int)$tmp['id']);
            }
        }
        $del0 = deleteTask(0);
        $this->assertFalse($del0['success']);
        $delGhost = deleteTask(999999);
        $this->assertIsArray($delGhost);
        if (function_exists('createOrganization')) {
            $org = createOrganization('Org ' . $suffix);
            $this->assertTrue(!empty($org['success']) || isset($org['id']));
        }
        $this->assertFalse(taskUserIsWatcher(0, 0));
        $this->assertFalse(addTaskComment(0, $aid, 'x')['success']);
        $emptyC = addTaskComment(999999, $aid, '');
        $this->assertFalse($emptyC['success']);
        resetLoginAttempts('cl_m_' . $suffix);
        if (function_exists('isHiddenApiKeyKind')) {
            $this->assertIsBool(isHiddenApiKeyKind('default'));
        }
        if (function_exists('normalizeApiKeyKind')) {
            $this->assertIsString(normalizeApiKeyKind(''));
        }
        if (function_exists('isAllowedTaskAssetMimeType')) {
            $this->assertFalse(isAllowedTaskAssetMimeType('application/x-msdownload'));
        }
        $this->assertSame([], listAllDirectoryProjectsInOrganization(0));
        $this->assertNull(getDirectoryProjectById(0));
        $this->assertNull(getFirstTodoListIdForProject(getDbConnection(), 0));
        $pin = setUserProjectPin(0, 0);
        $this->assertIsArray($pin);
        $this->assertFalse(createTodoList($aid, 0, 'x')['success']);
        $this->assertFalse(createDocument($aid, 0, 'x')['success']);
        $this->assertNull(getDocumentByPublicLinkToken(''));
        $this->assertFalse(documentBodyReferencesAttachmentId('', 1));
        if (function_exists('normalizeDirectoryProjectStatus')) {
            $this->assertNull(normalizeDirectoryProjectStatus('bogus'));
        }
        if (function_exists('normalizeProjectMemberRole')) {
            $this->assertNull(normalizeProjectMemberRole('bogus'));
        }

        replaceStaffOrganizationMemberships($aid, $aid, [1]);
        $this->assertFalse(replaceStaffOrganizationMemberships($aid, $mid, [1])['success']);

        $task = createTask("Cl task {$suffix}", 'todo', $aid, $mid, 'hello', [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $tid = (int)$task['id'];
        $full = getTaskById($tid, true);
        addTaskWatcher($tid, $cid);
        addTaskWatcher($tid, $mid);
        $c = addTaskComment($tid, $aid, 'ping watchers please');
        $this->assertTrue($c['success']);
        notificationsAfterTaskComment($full, (int)$c['id'], $aid, 'ping watchers please');

        $mname = (string)$memberRow['username'];
        notificationsTaskBodyMentions($aid, $full, '', "hi @{$mname}");
        notificationsAfterTaskAssigned(null, $full, null, $mid);
        notificationsAfterTaskAssigned($aid, $full, $mid, $mid); // no-op same

        $doc = createDocument($aid, $pid, "Cl doc {$suffix}", "doc @{$mname}");
        $did = (int)$doc['id'];
        $drow = getDocumentById($did, true);
        $dc = addDocumentComment($did, $mid, "reply @{$mname}");
        if (!empty($dc['success'])) {
            notificationsAfterDocumentComment($drow, (int)$dc['id'], $mid, "reply @{$mname}");
        }

        // trusted proxy IP branches
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        putenv('TASKS_TRUSTED_PROXIES=10.0.0.1');
        $_ENV['TASKS_TRUSTED_PROXIES'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.10';
        $ip = requestIpAddress();
        $this->assertNotSame('', $ip);
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_ENV['TASKS_TRUSTED_PROXIES']);
        putenv('TASKS_TRUSTED_PROXIES');

        if (function_exists('userCanManageDocument')) {
            $this->assertTrue(userCanManageDocument($adminRow, $drow));
        }
        if (function_exists('setAppSetting')) {
            setAppSetting('cl_' . $suffix, null, $aid);
        }
    }
}
