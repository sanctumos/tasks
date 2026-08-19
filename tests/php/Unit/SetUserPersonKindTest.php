<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SetUserPersonKindTest extends TestCase
{
    public function test_flip_client_to_team_member_allows_create_task_check(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("pk_admin_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $aid = (int)$admin['id'];

        $client = createUser("pk_client_{$suffix}", 'MemberPass123456', 'member', false, null, 'client', true);
        $this->assertTrue($client['success']);
        $cid = (int)$client['id'];

        $proj = createDirectoryProject($aid, "PK proj {$suffix}", null, true, false);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        addProjectMember($aid, $pid, $cid, 'member');
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);

        $before = getUserById($cid, false);
        $this->assertIsArray($before);
        $this->assertSame('client', $before['person_kind']);
        $project = getDirectoryProjectById($pid);
        $this->assertIsArray($project);
        $this->assertFalse(userCanCreateTaskOnProject($before, $project));

        $flip = setUserPersonKind($aid, $cid, 'team_member');
        $this->assertTrue($flip['success']);
        $after = getUserById($cid, false);
        $this->assertIsArray($after);
        $this->assertSame('team_member', $after['person_kind']);
        $this->assertTrue(userCanCreateTaskOnProject($after, $project));

        $task = createTask("Client-now-team {$suffix}", 'todo', $cid, null, null, [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $this->assertTrue($task['success'], (string)($task['error'] ?? ''));
    }
}
