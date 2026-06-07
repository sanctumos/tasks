<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scripted E2E for M6.2: member cannot see admin-only projects via scoped APIs.
 */
final class QAclMemberNegativeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/config.php';
        require_once dirname(__DIR__, 3) . '/public/includes/functions.php';
        require_once dirname(__DIR__, 3) . '/tools/e2e/q_acl_fixture_lib.php';
        initializeDatabase();
        applySanctumSchemaMigrations(getDbConnection());
    }

    public function test_member_directory_and_task_list_exclude_admin_only_project(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $this->assertTrue($boot['success']);
        $m = $boot['manifest'];

        $member = getUserById((int)$m['users']['member']['id'], false);
        $this->assertIsArray($member);

        $projects = listDirectoryProjectsForUser($member, 200);
        $projectIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $projects);
        $this->assertContains((int)$m['projects']['member_visible']['id'], $projectIds);
        $this->assertNotContains((int)$m['projects']['admin_only']['id'], $projectIds);

        $adminOnly = getDirectoryProjectById((int)$m['projects']['admin_only']['id']);
        $this->assertIsArray($adminOnly);
        $this->assertFalse(userCanAccessDirectoryProject($member, $adminOnly));

        $listed = listTasks(['project_id' => (int)$m['projects']['admin_only']['id'], 'limit' => 50], true, null, $member);
        $tasks = $listed['tasks'] ?? [];
        $this->assertSame([], $tasks);
    }
}
