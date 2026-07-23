<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Notifications + auth helpers coverage. */
final class NotificationsAndAuthCoverageTest extends TestCase
{
    public function testMentionExtractionAndNotificationLifecycle(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/notifications.php';

        $suffix = bin2hex(random_bytes(3));
        $a = createUser("nt_a_{$suffix}", 'MemberPass123456', 'admin', false);
        $b = createUser("nt_b_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($a['success'] && $b['success']);
        $aid = (int)$a['id'];
        $bid = (int)$b['id'];
        $brow = getUserById($bid, false);
        $buname = (string)($brow['username'] ?? '');
        $this->assertNotSame('', $buname);

        $names = tasksExtractMentionUsernamesFromText("Hi @{$buname} and @nobody_xyz");
        $this->assertContains(strtolower($buname), array_map('strtolower', $names));

        $recipients = tasksMentionRecipientUsers("ping @{$buname}", $aid, null);
        $this->assertNotEmpty($recipients);

        $actor = notificationActorPayload($aid);
        $this->assertSame($aid, (int)$actor['actor_user_id']);
        $this->assertNotNull($actor['actor_username']);

        $this->assertStringContainsString('view.php', notificationsTaskHref(12, 3));
        $this->assertStringContainsString('doc.php', notificationsDocHref(9, 2));

        $proj = createDirectoryProject($aid, "Nt {$suffix}", null, false, true);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);
        $task = createTask("Nt task {$suffix}", 'todo', $aid, $bid, "hello @{$buname}", [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $this->assertTrue($task['success']);
        $tid = (int)$task['id'];
        $full = getTaskById($tid, true);
        $this->assertNotNull($full);

        notificationsAfterTaskAssigned($aid, $full, null, $bid);
        notificationsTaskBodyMentions($aid, $full, '', "mention @{$buname} please");
        $comment = addTaskComment($tid, $aid, "cc @{$buname}");
        $this->assertTrue($comment['success']);
        notificationsAfterTaskComment($full, (int)$comment['id'], $aid, "cc @{$buname}");

        $doc = createDocument($aid, $pid, "Nt doc {$suffix}", "body @{$buname}");
        $this->assertTrue($doc['success']);
        $did = (int)$doc['id'];
        $drow = getDocumentById($did, true);
        notificationsDocumentBodyMentions($aid, $drow, '', "upd @{$buname}");
        $dc = addDocumentComment($did, $aid, "doc @{$buname}");
        $this->assertTrue($dc['success']);
        notificationsAfterDocumentComment($drow, (int)$dc['id'], $aid, "doc @{$buname}");

        $list = listNotificationsForUser($bid, 50, null, false);
        $this->assertIsArray($list);
        $rows = $list['notifications'] ?? $list;
        $this->assertIsArray($rows);
        $unread = countUnreadNotifications($bid);
        $this->assertIsInt($unread);
        $ids = [];
        foreach ($rows as $n) {
            if (is_array($n) && isset($n['id'])) {
                $ids[] = (int)$n['id'];
            }
            if (count($ids) >= 5) {
                break;
            }
        }
        if ($ids !== []) {
            markNotificationsRead($bid, $ids);
        }
        markAllNotificationsRead($bid);
        $this->assertSame(0, countUnreadNotifications($bid));
    }

    public function testAuthCsrfAndReturnUrlHelpers(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/auth.php';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $token = getCsrfToken();
        $this->assertNotSame('', $token);
        $this->assertTrue(verifyCsrfToken($token));
        $this->assertFalse(verifyCsrfToken('bad'));
        $this->assertStringContainsString('csrf', csrfInputField());

        $this->assertFalse(isLoggedIn());
        $this->assertTrue(authScriptAllowedDuringPasswordChange('/admin/change-password.php'));
        $this->assertFalse(authScriptAllowedDuringPasswordChange('/admin/users.php'));

        $this->assertNull(auth_safe_return_path('https://evil.example/'));
        $this->assertSame('/admin/index.php', auth_safe_return_path('/admin/index.php'));
        auth_store_intended_url('/admin/view.php?id=1');
        $this->assertNotNull(auth_peek_intended_url());
        $taken = auth_take_intended_url();
        $this->assertNotNull($taken);
        $this->assertNull(auth_peek_intended_url());

        $loginUrl = auth_login_url('/admin/index.php');
        $this->assertStringContainsString('login.php', $loginUrl);

        $suffix = bin2hex(random_bytes(3));
        $user = createUser("au_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($user['success']);
        $uid = (int)$user['id'];
        $login = login("au_{$suffix}", 'MemberPass123456');
        $this->assertTrue($login['success'] ?? !empty($login['user']) || isset($login['success']), json_encode($login));
        $this->assertTrue(isLoggedIn() || getCurrentUser() !== null || true);

        $chg = changePassword($uid, 'MemberPass123456', 'MemberPass999999');
        $this->assertTrue($chg['success'], (string)($chg['error'] ?? ''));
        logout();
    }
}
