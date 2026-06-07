<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scripted E2E for M6.1: member creates a task on a visible project (Q SMCP create-task contract).
 */
final class QAclMemberCreateTaskTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/config.php';
        require_once dirname(__DIR__, 3) . '/public/includes/functions.php';
        require_once dirname(__DIR__, 3) . '/tools/e2e/q_acl_fixture_lib.php';
        initializeDatabase();
        applySanctumSchemaMigrations(getDbConnection());
    }

    public function test_member_creates_task_on_visible_project_and_sees_it_on_board(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $this->assertTrue($boot['success']);
        $m = $boot['manifest'];

        $member = getUserById((int)$m['users']['member']['id'], false);
        $this->assertIsArray($member);

        $suffix = bin2hex(random_bytes(3));
        $title = 'E2E Q member create ' . $suffix;
        $created = createTask($title, 'todo', (int)$member['id'], (int)$member['id'], 'M6.1 scripted path', [
            'project_id' => (int)$m['projects']['member_visible']['id'],
            'list_id' => (int)$m['projects']['member_visible']['list_id'],
            'project' => Q_ACL_E2E_PROJECT_MEMBER_VISIBLE,
        ]);
        $this->assertTrue($created['success'], $created['error'] ?? 'create failed');

        $listed = listTasks(['project_id' => (int)$m['projects']['member_visible']['id'], 'limit' => 50], true, null, $member);
        $ids = array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $listed['tasks'] ?? []
        );
        $this->assertContains((int)$created['id'], $ids);
    }

    public function test_member_cannot_create_task_on_admin_only_project(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $this->assertTrue($boot['success']);
        $m = $boot['manifest'];

        $member = getUserById((int)$m['users']['member']['id'], false);
        $this->assertIsArray($member);

        $denied = createTask('E2E Q member denied', 'todo', (int)$member['id'], (int)$member['id'], null, [
            'project_id' => (int)$m['projects']['admin_only']['id'],
            'list_id' => (int)$m['projects']['admin_only']['list_id'],
            'project' => Q_ACL_E2E_PROJECT_ADMIN_ONLY,
        ]);
        $this->assertFalse($denied['success']);
    }
}
