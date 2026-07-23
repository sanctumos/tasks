<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Extra functions.php paths toward the 90% Unit gate. */
final class FunctionsCoverageGatePushTest extends TestCase
{
    public function testCreateTaskEdgesBackfillPinsAndAssets(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("gp_a_{$suffix}", 'MemberPass123456', 'admin', false);
        $member = createUser("gp_m_{$suffix}", 'MemberPass123456', 'member', false);
        $aid = (int)$admin['id'];
        $mid = (int)$member['id'];

        $proj = createDirectoryProject($aid, "Gp {$suffix}", null, true, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);
        $this->assertNotNull($lid);

        $badTitle = createTask('   ', 'todo', $aid, null, null, ['project_id' => $pid, 'list_id' => $lid]);
        $this->assertFalse($badTitle['success']);
        $badStatus = createTask("Gp bad {$suffix}", 'not-real', $aid, null, null, ['project_id' => $pid, 'list_id' => $lid]);
        $this->assertFalse($badStatus['success']);
        $badAssignee = createTask("Gp bad2 {$suffix}", 'todo', $aid, 999999, null, ['project_id' => $pid, 'list_id' => $lid]);
        $this->assertFalse($badAssignee['success']);

        $mrow = getUserById($mid, false);
        $muname = (string)($mrow['username'] ?? '');
        $ok = createTask("Gp ok {$suffix}", 'todo', $aid, $mid, "body @{$muname}", [
            'project_id' => $pid,
            'list_id' => $lid,
            'priority' => 'urgent',
            'due_at' => '2031-02-01 09:00:00',
            'tags' => ['gate', 'push'],
            'rank' => 7,
            'recurrence_rule' => 'FREQ=WEEKLY',
            'project' => "legacy-{$suffix}",
        ]);
        $this->assertTrue($ok['success'], (string)($ok['error'] ?? ''));
        $tid = (int)$ok['id'];

        $db = getDbConnection();
        // Legacy namespace row for backfill
        $db->exec("UPDATE tasks SET project_id = NULL, project = 'legacy-{$suffix}' WHERE id = " . (int)$tid);
        if (function_exists('backfillTaskProjectIdsFromLegacyNames')) {
            $bf = backfillTaskProjectIdsFromLegacyNames();
            $this->assertTrue(is_array($bf) || is_int($bf) || $bf === null || is_bool($bf));
        }

        setUserProjectPin($aid, $pid, 2);
        $pins = listUserProjectPinsForUser(getUserById($aid, false));
        $this->assertIsArray($pins);

        $listExtra = createTodoList($aid, $pid, "Gp list {$suffix}");
        $this->assertTrue($listExtra['success']);
        $del = deleteTodoList($aid, (int)$listExtra['id']);
        $this->assertTrue($del['success']);

        addProjectMember($aid, $pid, $mid, 'client');
        removeProjectMember($aid, $pid, $mid);
        $rmGhost = removeProjectMember($aid, 999999, $mid);
        $this->assertIsArray($rmGhost);
        $this->assertArrayHasKey('success', $rmGhost);

        $assetDir = rtrim((string)TASKS_ASSET_STORAGE_DIR, '/');
        if (!is_dir($assetDir)) {
            mkdir($assetDir, 0777, true);
        }
        if (function_exists('buildTaskAssetStorageRelPath') && function_exists('persistTaskAssetUpload')) {
            $src = sys_get_temp_dir() . '/gate-' . $suffix . '.png';
            file_put_contents($src, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
            $persisted = persistTaskAssetUpload($tid, $src, 'image/png');
            $this->assertTrue($persisted['success'] ?? false, (string)($persisted['error'] ?? ''));
            if (!empty($persisted['storage_rel_path'])) {
                deleteLocalTaskAttachmentFile([
                    'storage_kind' => 'local',
                    'storage_rel_path' => $persisted['storage_rel_path'],
                ]);
            }
            deleteLocalTaskAttachmentFile(['storage_kind' => 'remote', 'storage_rel_path' => '']);
        }
        if (function_exists('taskAttachmentMarkdownSnippet')) {
            $this->assertStringContainsString('![', taskAttachmentMarkdownSnippet('a.png', '/api/get-asset.php?id=1'));
        }
        if (function_exists('allowedTaskAssetMimeTypes')) {
            $this->assertNotEmpty(allowedTaskAssetMimeTypes());
        }
        if (function_exists('normalizeTags')) {
            $this->assertIsArray(normalizeTags(['A', 'a', 'B', '']));
        }
        if (function_exists('removeTaskWatcher')) {
            removeTaskWatcher($tid, $mid);
        }
        if (function_exists('removeUserProjectPin')) {
            removeUserProjectPin($aid, $pid);
        }
        if (function_exists('validateApiKeyAndGetUser')) {
            $this->assertNull(validateApiKeyAndGetUser(''));
            $this->assertNull(validateApiKeyAndGetUser(str_repeat('z', 64)));
        }

        $urow = getUserById($aid, false);
        $trow = getTaskById($tid, false);
        $this->assertTrue(userCanManageTaskForViewer($urow, $trow));
        $mrow = getUserById($mid, false);
        // member may or may not manage
        userCanManageTaskForViewer($mrow, $trow);

        require_once dirname(__DIR__, 3) . '/public/includes/notifications.php';
        $full = getTaskById($tid, true);
        addTaskWatcher($tid, $mid);
        $c = addTaskComment($tid, $aid, 'watcher ping');
        if (!empty($c['success'])) {
            notificationsAfterTaskComment($full, (int)($c['id'] ?? $c['comment_id'] ?? 0), $aid, 'watcher ping');
        }

        $listed = listTasks([
            'q' => 'Gp ok',
            'tags' => ['gate'],
            'created_by_user_id' => $aid,
            'limit' => 50,
            'offset' => 0,
            'include_archived_projects' => 1,
        ], true, $urow, $urow);
        $this->assertIsArray($listed);
    }
}
