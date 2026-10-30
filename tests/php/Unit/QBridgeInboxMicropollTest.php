<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QBridgeInboxMicropollTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/public/q-bridge/includes/webchat_inbox_micropoll.php';
        putenv('WEBCHAT_INBOX_BUSY_TIMEOUT_MS');
        unset($GLOBALS['_webchat_bridge_inbox_busy_timeout_ms']);
    }

    public function testDefaultTimeoutIsTwoSeconds(): void
    {
        $this->assertSame(2000, webchat_bridge_inbox_busy_timeout_ms());
    }

    public function testEnvOverrideIsClamped(): void
    {
        putenv('WEBCHAT_INBOX_BUSY_TIMEOUT_MS=150');
        $this->assertSame(150, webchat_bridge_inbox_busy_timeout_ms());
        putenv('WEBCHAT_INBOX_BUSY_TIMEOUT_MS=1');
        $this->assertSame(50, webchat_bridge_inbox_busy_timeout_ms());
        putenv('WEBCHAT_INBOX_BUSY_TIMEOUT_MS=99999');
        $this->assertSame(5000, webchat_bridge_inbox_busy_timeout_ms());
        putenv('WEBCHAT_INBOX_BUSY_TIMEOUT_MS');
    }

    public function testBeginMarksMicropollActive(): void
    {
        $this->assertFalse(webchat_bridge_inbox_micropoll_active());
        webchat_bridge_begin_inbox_micropoll();
        $this->assertTrue(webchat_bridge_inbox_micropoll_active());
        unset($GLOBALS['_webchat_bridge_inbox_busy_timeout_ms']);
    }
}
