<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 3 MVP: access matrix edge cases beyond Q ACL fixture smoke tests.
 */
final class ClientVisibilityAccessMatrixTest extends TestCase
{
    private static int $adminId = 0;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/config.php';
        require_once dirname(__DIR__, 3) . '/public/includes/functions.php';
        require_once dirname(__DIR__, 3) . '/tools/e2e/q_acl_fixture_lib.php';
        initializeDatabase();
        applySanctumSchemaMigrations(getDbConnection());
        $boot = bootstrapQAclE2eFixtures();
        if (!($boot['success'] ?? false)) {
            throw new RuntimeException('Fixture bootstrap failed: ' . (string)($boot['error'] ?? 'unknown'));
        }
        self::$adminId = (int)$boot['manifest']['admin']['id'];
    }

    public function test_client_cannot_create_task_or_document(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $client = getUserById((int)$m['users']['client']['id'], false);
        $this->assertIsArray($client);

        $taskRes = createTask('Client write attempt', 'todo', (int)$client['id'], (int)$client['id'], null, [
            'project_id' => (int)$m['projects']['member_visible']['id'],
            'list_id' => (int)$m['projects']['member_visible']['list_id'],
        ]);
        $this->assertFalse($taskRes['success']);
        $this->assertStringContainsString('read-only', strtolower((string)($taskRes['error'] ?? '')));

        $docRes = createDocument((int)$client['id'], (int)$m['projects']['member_visible']['id'], 'Client doc attempt');
        $this->assertFalse($docRes['success']);
        $this->assertStringContainsString('read-only', strtolower((string)($docRes['error'] ?? '')));
    }

    public function test_client_cannot_be_added_to_internal_project(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $res = addProjectMember(
            self::$adminId,
            (int)$m['projects']['admin_only']['id'],
            (int)$m['users']['client']['id'],
            'member'
        );
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('client-visible', strtolower((string)($res['error'] ?? '')));
    }

    public function test_client_without_membership_denied_even_when_project_is_client_visible(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $orgId = (int)$m['org_id'];

        $created = createDirectoryProject(self::$adminId, 'E2E Client Visible No Member', null, true, false);
        $this->assertTrue($created['success']);
        $pid = (int)$created['id'];
        createTodoList(self::$adminId, $pid, 'Main');

        $client = getUserById((int)$m['users']['client']['id'], false);
        $proj = getDirectoryProjectById($pid);
        $this->assertIsArray($client);
        $this->assertIsArray($proj);

        $this->assertFalse(userCanAccessDirectoryProject($client, $proj));
        $this->assertFalse(userCanManageDirectoryProject($client, $proj));
    }

    public function test_all_access_internal_visible_to_team_not_client(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];

        $created = createDirectoryProject(self::$adminId, 'E2E All Access Internal', null, false, true);
        $this->assertTrue($created['success']);
        $pid = (int)$created['id'];

        $member = getUserById((int)$m['users']['member']['id'], false);
        $client = getUserById((int)$m['users']['client']['id'], false);
        $proj = getDirectoryProjectById($pid);
        $this->assertIsArray($member);
        $this->assertIsArray($client);
        $this->assertIsArray($proj);

        $this->assertTrue(userCanAccessDirectoryProject($member, $proj));
        $this->assertFalse(userCanAccessDirectoryProject($client, $proj));
    }

    public function test_all_access_client_visible_allows_client_without_membership(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];

        $created = createDirectoryProject(self::$adminId, 'E2E All Access Client Portal', null, true, true);
        $this->assertTrue($created['success']);
        $pid = (int)$created['id'];

        $client = getUserById((int)$m['users']['client']['id'], false);
        $proj = getDirectoryProjectById($pid);
        $this->assertIsArray($client);
        $this->assertIsArray($proj);

        $this->assertTrue(userCanAccessDirectoryProject($client, $proj));
    }
}
