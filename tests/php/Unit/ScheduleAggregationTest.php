<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScheduleAggregationTest extends TestCase
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
        self::$adminId = (int)$boot['manifest']['admin']['id'];
    }

    public function test_mine_scope_returns_assigned_due_tasks_grouped(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $member = getUserById((int)$m['users']['member']['id'], false);
        $this->assertIsArray($member);

        $due = (new DateTime('+3 days', new DateTimeZone('UTC')))->format('Y-m-d 17:00:00');
        $created = createTask(
            'Schedule test task',
            'todo',
            self::$adminId,
            (int)$member['id'],
            null,
            [
                'project_id' => (int)$m['projects']['member_visible']['id'],
                'list_id' => (int)$m['projects']['member_visible']['list_id'],
                'due_at' => $due,
            ]
        );
        $this->assertTrue($created['success']);

        $schedule = listScheduleForViewer($member, [
            'scope' => 'mine',
            'due_after' => (new DateTime('-1 day', new DateTimeZone('UTC')))->format('Y-m-d 00:00:00'),
            'due_before' => (new DateTime('+10 days', new DateTimeZone('UTC')))->format('Y-m-d 23:59:59'),
            'limit' => 50,
        ]);

        $ids = array_map(static fn(array $row): int => (int)($row['task_id'] ?? 0), $schedule['entries']);
        $this->assertContains((int)$created['id'], $ids);
        $this->assertNotEmpty($schedule['grouped_by_date']);
        $this->assertSame('mine', $schedule['scope']);
    }

    public function test_project_scope_excludes_inaccessible_project(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $member = getUserById((int)$m['users']['member']['id'], false);
        $this->assertIsArray($member);

        $schedule = listScheduleForViewer($member, [
            'scope' => 'project',
            'project_id' => (int)$m['projects']['admin_only']['id'],
            'limit' => 20,
        ]);

        $this->assertSame([], $schedule['entries']);
        $this->assertNotEmpty($schedule['error'] ?? '');
    }

    public function test_done_tasks_excluded_by_default(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $member = getUserById((int)$m['users']['member']['id'], false);
        $this->assertIsArray($member);

        $due = (new DateTime('+2 days', new DateTimeZone('UTC')))->format('Y-m-d 12:00:00');
        $created = createTask(
            'Schedule done task',
            'done',
            self::$adminId,
            (int)$member['id'],
            null,
            [
                'project_id' => (int)$m['projects']['member_visible']['id'],
                'list_id' => (int)$m['projects']['member_visible']['list_id'],
                'due_at' => $due,
            ]
        );
        $this->assertTrue($created['success']);

        $schedule = listScheduleForViewer($member, [
            'scope' => 'mine',
            'due_after' => (new DateTime('-1 day', new DateTimeZone('UTC')))->format('Y-m-d 00:00:00'),
            'due_before' => (new DateTime('+10 days', new DateTimeZone('UTC')))->format('Y-m-d 23:59:59'),
        ]);

        $ids = array_map(static fn(array $row): int => (int)($row['task_id'] ?? 0), $schedule['entries']);
        $this->assertNotContains((int)$created['id'], $ids);
    }
}
