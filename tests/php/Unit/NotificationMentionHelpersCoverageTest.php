<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NotificationMentionHelpersCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/public/includes/notifications.php';
    }

    public function testExtractAndRecipientEdgeBranches(): void
    {
        // Has '@' but no valid mention token → preg_match_all fails → [].
        $this->assertSame([], tasksExtractMentionUsernamesFromText('see @ alone'));
        $this->assertSame([], tasksExtractMentionUsernamesFromText('email@x')); // no word-boundary form

        $suffix = bin2hex(random_bytes(3));
        $author = createUser("men_auth_{$suffix}", 'AuthPass123456', 'admin', false);
        $this->assertTrue($author['success']);
        $aid = (int)$author['id'];
        $authorRow = getUserById($aid, false);

        $peer = createUser("men_peer_{$suffix}", 'PeerPass123456', 'member', false);
        $this->assertTrue($peer['success']);
        $pid = (int)$peer['id'];

        $inactive = createUser("men_dead_{$suffix}", 'DeadPass123456', 'member', false);
        $this->assertTrue($inactive['success']);
        $deadId = (int)$inactive['id'];
        // Soft-disable if helper exists; otherwise force via DB.
        if (function_exists('setUserActive')) {
            setUserActive($deadId, false);
        } else {
            $db = getDbConnection();
            $stmt = $db->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
            $stmt->bindValue(':id', $deadId, SQLITE3_INTEGER);
            $stmt->execute();
        }

        $text = '@' . $authorRow['username'] . ' hey @men_peer_' . $suffix . ' and @men_dead_' . $suffix;
        $recipients = tasksMentionRecipientUsers($text, $aid, (string)$authorRow['username']);
        $this->assertArrayHasKey($pid, $recipients);
        $this->assertArrayNotHasKey($aid, $recipients);
        $this->assertArrayNotHasKey($deadId, $recipients);

        $this->assertStringContainsString('view.php?id=9', notificationsTaskHref(9, null));
        $this->assertStringContainsString('#comment-3', notificationsTaskHref(9, 3));
        $this->assertStringContainsString('doc.php?id=4', notificationsDocHref(4, null));
        $this->assertStringContainsString('#comment-2', notificationsDocHref(4, 2));

        $anon = notificationActorPayload(null);
        $this->assertNull($anon['actor_user_id']);
        $named = notificationActorPayload($aid);
        $this->assertSame($aid, $named['actor_user_id']);
        $this->assertSame((string)$authorRow['username'], $named['actor_username']);
    }
}
