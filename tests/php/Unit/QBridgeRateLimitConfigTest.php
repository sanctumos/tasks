<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class QBridgeRateLimitConfigTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/public/q-bridge/includes/rate_limit_config.php';
        q_bridge_clear_rate_limit_config_cache();
    }

    public function testDefaultsMatchApprovedPolicy(): void
    {
        $cfg = q_bridge_rate_limit_defaults();
        $this->assertSame(300, $cfg['user_endpoints']['/api/messages']);
        $this->assertSame(7200, $cfg['user_endpoints']['/api/responses']);
        $this->assertSame(600, $cfg['user_endpoints']['/api/history']);
        $this->assertSame(3000, $cfg['user_endpoints']['/api/user_session']);
        $this->assertSame(20000, $cfg['user_max_requests']);
        $this->assertSame(10000, $cfg['ip_endpoints']['/api/inbox']);
        $this->assertSame(25000, $cfg['ip_max_requests']);
    }

    public function testValidateRejectsOutOfRange(): void
    {
        $r = q_bridge_validate_rate_limit_input([
            'messages' => 0,
            'responses' => 7200,
            'history' => 600,
            'user_session' => 3000,
            'user_max_requests' => 20000,
            'ip_max_requests' => 25000,
        ]);
        $this->assertFalse($r['success']);
    }

    public function testMergeOverridesUserEndpoints(): void
    {
        $base = q_bridge_rate_limit_defaults();
        $merged = q_bridge_merge_rate_limit_config($base, [
            'user_endpoints' => ['/api/messages' => 99],
        ]);
        $this->assertSame(99, $merged['user_endpoints']['/api/messages']);
        $this->assertSame(7200, $merged['user_endpoints']['/api/responses']);
    }
}
