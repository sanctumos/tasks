<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scripted E2E for M6.3: client user respects client_visible project ACL.
 */
final class QAclClientVisibleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/config.php';
        require_once dirname(__DIR__, 3) . '/public/includes/functions.php';
        require_once dirname(__DIR__, 3) . '/tools/e2e/q_acl_fixture_lib.php';
        initializeDatabase();
        applySanctumSchemaMigrations(getDbConnection());
    }

    public function test_client_sees_only_client_visible_member_projects(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $this->assertTrue($boot['success']);
        $m = $boot['manifest'];

        $client = getUserById((int)$m['users']['client']['id'], false);
        $this->assertIsArray($client);
        $this->assertSame('client', $client['person_kind']);

        $visible = getDirectoryProjectById((int)$m['projects']['member_visible']['id']);
        $adminOnly = getDirectoryProjectById((int)$m['projects']['admin_only']['id']);
        $this->assertIsArray($visible);
        $this->assertIsArray($adminOnly);

        $this->assertTrue(userCanAccessDirectoryProject($client, $visible));
        $this->assertFalse(userCanAccessDirectoryProject($client, $adminOnly));

        $projects = listDirectoryProjectsForUser($client, 200);
        $projectIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $projects);
        $this->assertContains((int)$visible['id'], $projectIds);
        $this->assertNotContains((int)$adminOnly['id'], $projectIds);

        $listed = listTasks(['project_id' => (int)$visible['id'], 'limit' => 50], true, null, $client);
        $markerId = (int)$m['tasks']['member_visible_marker']['id'];
        $ids = array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $listed['tasks'] ?? []
        );
        $this->assertContains($markerId, $ids);

        $hidden = listTasks(['project_id' => (int)$adminOnly['id'], 'limit' => 50], true, null, $client);
        $this->assertSame([], $hidden['tasks'] ?? []);
    }
}
