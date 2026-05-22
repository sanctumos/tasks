<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QBridgeApiKeyTest extends TestCase
{
    private static string $suffix;

    public static function setUpBeforeClass(): void
    {
        self::$suffix = bin2hex(random_bytes(4));
        require_once dirname(__DIR__, 3) . '/public/includes/config.php';
        require_once dirname(__DIR__, 3) . '/public/includes/functions.php';
        initializeDatabase();
        applySanctumSchemaMigrations(getDbConnection());
    }

    public function test_create_user_mints_hidden_q_bridge_key(): void
    {
        $user = createUser('qbr_' . self::$suffix, 'QBridgePass123456', 'member', false);
        $this->assertTrue($user['success']);
        $uid = (int)$user['id'];

        $visible = listApiKeysForUser($uid);
        $this->assertCount(0, $visible, 'Q bridge keys must not appear in user-visible listings');

        $plain = getQBridgeDefaultApiKeyPlaintextForUser($uid);
        $this->assertNotNull($plain);
        $this->assertStringStartsWith('stq_', $plain);

        $auth = validateApiKeyAndGetUser($plain);
        $this->assertIsArray($auth);
        $this->assertSame($uid, (int)$auth['id']);

        $again = ensureQBridgeDefaultApiKeyForUser($uid);
        $this->assertTrue($again['success']);
        $this->assertFalse($again['created'] ?? true);
    }

    public function test_backfill_is_idempotent(): void
    {
        $r1 = backfillQBridgeDefaultApiKeysForAllUsers();
        $r2 = backfillQBridgeDefaultApiKeysForAllUsers();
        $this->assertTrue($r1['success']);
        $this->assertTrue($r2['success']);
        $this->assertSame(0, $r2['created']);
    }
}
