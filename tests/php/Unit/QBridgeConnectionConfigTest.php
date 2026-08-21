<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QBridgeConnectionConfigTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/public/q-bridge/includes/connection_config.php';
        q_bridge_clear_connection_config_cache();
    }

    public function test_defaults_include_agent_label_and_empty_target(): void
    {
        $d = q_bridge_connection_defaults();
        $this->assertArrayHasKey('enabled', $d);
        $this->assertSame('Q. Vernal', $d['agent_label']);
        $this->assertSame('', $d['sanctum_url']);
        $this->assertSame('', $d['agent_id']);
    }
}
