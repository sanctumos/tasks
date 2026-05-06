<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SQLite3;

/**
 * Uses bootstrap SQLite (shared temp DB per PHPUnit process).
 */
final class DbTaskLifecycleTest extends TestCase
{
    public function testCreateUserAndTaskRequiresList(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = createUser("mem_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($user['success'], (string)($user['error'] ?? 'create user'));
        $uid = (int)$user['id'];

        $proj = createDirectoryProject($uid, "Proj {$suffix}", null, false, true);
        $this->assertTrue($proj['success'], (string)($proj['error'] ?? 'create project'));
        $pid = (int)$proj['id'];

        // Same as a new HTTP request: idempotent migration seeds "General" per project.
        applySanctumSchemaMigrations(getDbConnection());

        $noProj = createTask("Orphan {$suffix}", 'todo', $uid, null, 'Body text', ['priority' => 'normal']);
        $this->assertFalse($noProj['success'], 'create without project_id should fail');

        $db = getDbConnection();
        $this->assertInstanceOf(SQLite3::class, $db);
        $lid = getFirstTodoListIdForProject($db, $pid);
        $this->assertNotNull($lid);
        $this->assertGreaterThan(0, $lid);

        $noList = createTask("No list {$suffix}", 'todo', $uid, null, 'Body text', [
            'priority' => 'normal',
            'project_id' => $pid,
        ]);
        $this->assertFalse($noList['success'], 'create without list_id should fail');

        $task = createTask("Unit task {$suffix}", 'todo', $uid, null, 'Body text', [
            'priority' => 'normal',
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $this->assertTrue($task['success'], (string)($task['error'] ?? 'create task'));
        $tid = (int)$task['id'];

        $loaded = getTaskById($tid);
        $this->assertNotNull($loaded);
        $this->assertSame("Unit task {$suffix}", $loaded['title']);
        $this->assertSame('todo', $loaded['status']);
        $this->assertSame($pid, (int)($loaded['project_id'] ?? 0));
        $this->assertSame($lid, (int)($loaded['list_id'] ?? 0));

        $viaListOnly = createTask("Via list {$suffix}", 'todo', $uid, null, 'x', [
            'priority' => 'normal',
            'list_id' => $lid,
        ]);
        $this->assertTrue($viaListOnly['success'], (string)($viaListOnly['error'] ?? 'create via list'));
        $t2 = getTaskById((int)$viaListOnly['id']);
        $this->assertNotNull($t2);
        $this->assertSame($pid, (int)($t2['project_id'] ?? 0));
        $this->assertSame($lid, (int)($t2['list_id'] ?? 0));
    }
}
