<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PurgeDirectoryProjectTest extends TestCase
{
    private function isolatedAdmin(string $prefix): int
    {
        $suffix = bin2hex(random_bytes(3));
        $boot = createUser("{$prefix}_b_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($boot['success']);
        $org = createOrganization("{$prefix} Org {$suffix}", (int)$boot['id']);
        $this->assertTrue($org['success']);
        $admin = createUser("{$prefix}_{$suffix}", 'MemberPass123456', 'admin', false, (int)$org['id']);
        $this->assertTrue($admin['success']);
        return (int)$admin['id'];
    }

    public function testRefuseActiveWithoutForceAndWrongConfirmName(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $aid = $this->isolatedAdmin('pga');
        $name = "Purge Active {$suffix}";
        $proj = createDirectoryProject($aid, $name, null, false, false);
        $pid = (int)$proj['id'];

        $wrong = purgeDirectoryProject($aid, $pid, [
            'confirm_name' => 'nope',
            'acknowledge_no_export' => true,
        ]);
        $this->assertFalse($wrong['success']);
        $this->assertSame('validation.confirm_name', $wrong['code'] ?? null);

        $active = purgeDirectoryProject($aid, $pid, [
            'confirm_name' => $name,
            'acknowledge_no_export' => true,
        ]);
        $this->assertFalse($active['success']);
        $this->assertSame('project.active', $active['code'] ?? null);
        $this->assertNotNull(getDirectoryProjectById($pid));
    }

    public function testNonAdminCannotPurge(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $aid = $this->isolatedAdmin('pgadm');
        $adminRow = getUserById($aid, false);
        $oid = (int)($adminRow['org_id'] ?? 0);
        $member = createUser("pgmem_{$suffix}", 'MemberPass123456', 'member', false, $oid);
        $this->assertTrue($member['success']);
        $mid = (int)$member['id'];
        $name = "Purge Lead {$suffix}";
        $proj = createDirectoryProject($aid, $name, null, false, false);
        $pid = (int)$proj['id'];
        addProjectMember($aid, $pid, $mid, 'lead');
        updateDirectoryProject($aid, $pid, ['status' => 'archived']);

        $denied = purgeDirectoryProject($mid, $pid, [
            'confirm_name' => $name,
            'acknowledge_no_export' => true,
        ]);
        $this->assertFalse($denied['success']);
        $this->assertSame('auth.forbidden', $denied['code'] ?? null);
    }

    public function testHappyPathPurgesArchivedBoardAndTasks(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $aid = $this->isolatedAdmin('pgok');
        $name = "Purge OK {$suffix}";
        $proj = createDirectoryProject($aid, $name, null, false, false);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);
        $task = createTask("doomed {$suffix}", 'todo', $aid, $aid, null, [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $this->assertTrue($task['success'] ?? false);
        $tid = (int)$task['id'];
        $doc = createDocument($aid, $pid, "Doc {$suffix}", "body {$suffix}");
        $this->assertTrue($doc['success'] ?? !empty($doc['id']));

        updateDirectoryProject($aid, $pid, ['status' => 'archived']);

        $noExport = purgeDirectoryProject($aid, $pid, [
            'confirm_name' => $name,
        ]);
        $this->assertFalse($noExport['success']);
        $this->assertSame('project.no_export', $noExport['code'] ?? null);

        $ok = purgeDirectoryProject($aid, $pid, [
            'confirm_name' => $name,
            'acknowledge_no_export' => true,
        ]);
        $this->assertTrue($ok['success'], (string)($ok['error'] ?? ''));
        $this->assertNull(getDirectoryProjectById($pid));
        $this->assertNull(getTaskById($tid, false));

        $again = purgeDirectoryProject($aid, $pid, [
            'confirm_name' => $name,
            'acknowledge_no_export' => true,
        ]);
        $this->assertFalse($again['success']);
        $this->assertSame('project.not_found', $again['code'] ?? null);
    }

    public function testForcePurgeActiveBoard(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $aid = $this->isolatedAdmin('pgforce');
        $name = "Force Purge {$suffix}";
        $proj = createDirectoryProject($aid, $name, null, false, false);
        $pid = (int)$proj['id'];

        $ok = purgeDirectoryProject($aid, $pid, [
            'confirm_name' => $name,
            'force' => true,
            'acknowledge_no_export' => true,
        ]);
        $this->assertTrue($ok['success'], (string)($ok['error'] ?? ''));
        $this->assertNull(getDirectoryProjectById($pid));
    }

    public function testDaysSinceUtcEdges(): void
    {
        $this->assertNull(st_days_since_utc(null));
        $this->assertNull(st_days_since_utc(''));
        $this->assertNull(st_days_since_utc('not-a-date'));
        $this->assertSame(0, st_days_since_utc(gmdate('Y-m-d H:i:s')));
        $this->assertSame(0, st_days_since_utc(gmdate('Y-m-d H:i:s', time() + 7200)));
    }

    public function testPurgeDeletesExportZipWhenPresent(): void
    {
        $aid = $this->isolatedAdmin('pgzip');
        $suffix = bin2hex(random_bytes(2));
        $name = "Zip Purge {$suffix}";
        $proj = createDirectoryProject($aid, $name, null, false, false);
        $pid = (int)$proj['id'];
        updateDirectoryProject($aid, $pid, ['status' => 'archived']);

        $db = getDbConnection();
        $db->exec("INSERT INTO project_board_exports (project_id, requested_by_user_id, status, storage_rel_path, created_at)
            VALUES ({$pid}, {$aid}, 'ready', '', CURRENT_TIMESTAMP)");
        $rel = 'purge-test-' . $pid . '.zip';
        $abs = boardExportAbsolutePath($rel);
        $this->assertNotNull($abs);
        file_put_contents($abs, 'PK zip');
        $db->exec("INSERT INTO project_board_exports (project_id, requested_by_user_id, status, storage_rel_path, created_at)
            VALUES ({$pid}, {$aid}, 'ready', '" . $db->escapeString($rel) . "', CURRENT_TIMESTAMP)");

        $ok = purgeDirectoryProject($aid, $pid, [
            'confirm_name' => $name,
            'acknowledge_no_export' => true,
        ]);
        $this->assertTrue($ok['success'], (string)($ok['error'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int)($ok['deleted']['exports'] ?? 0));
        $this->assertFileDoesNotExist($abs);
    }
}
