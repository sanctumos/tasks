<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * API rate limit must be file-backed (no tasks.db) and deny with allowed=false (429 path), never throw.
 */
final class ApiRateLimitFileBackendTest extends TestCase
{
    public function testExceedingLimitDeniesWithoutThrowing(): void
    {
        $key = 'rl-unit-' . bin2hex(random_bytes(8));
        $this->assertTrue(checkApiRateLimit($key, 2, 60)['allowed']);
        $this->assertTrue(checkApiRateLimit($key, 2, 60)['allowed']);
        $third = checkApiRateLimit($key, 2, 60);
        $this->assertFalse($third['allowed']);
        $this->assertSame(0, $third['remaining']);
        $this->assertGreaterThanOrEqual(1, $third['retry_after']);

        $path = rtrim((string)API_RATE_LIMIT_DIR, '/\\') . '/' . hash('sha256', $key) . '.rl';
        $this->assertFileExists($path);
    }

    public function testReturnsStableShapeWithoutThrowing(): void
    {
        $key = 'rl-soft-' . bin2hex(random_bytes(8));
        $state = checkApiRateLimit($key, 100, 60);
        $this->assertIsArray($state);
        $this->assertArrayHasKey('allowed', $state);
        $this->assertArrayHasKey('limit', $state);
        $this->assertArrayHasKey('remaining', $state);
        $this->assertArrayHasKey('reset_epoch', $state);
        $this->assertArrayHasKey('retry_after', $state);
    }
}
