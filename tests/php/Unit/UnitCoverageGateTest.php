<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Soft gate: when clover XML is provided via TASKS_COVERAGE_CLOVER, assert ≥90% lines.
 * Local/CI: `composer run test:php:coverage` then optionally:
 * TASKS_COVERAGE_CLOVER=/tmp/clover.xml vendor/bin/phpunit --filter UnitCoverageGateTest
 */
final class UnitCoverageGateTest extends TestCase
{
    public function testUnitLineCoverageAtLeastNinetyWhenCloverProvided(): void
    {
        $path = getenv('TASKS_COVERAGE_CLOVER') ?: '';
        if ($path === '' || !is_file($path)) {
            $this->assertTrue(true, 'No clover path — skip numeric gate');
            return;
        }
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml);
        $covered = 0;
        $total = 0;
        foreach ($xml->xpath('//file/metrics') as $m) {
            $covered += (int)$m['coveredstatements'];
            $total += (int)$m['statements'];
        }
        $this->assertGreaterThan(0, $total);
        $pct = 100.0 * $covered / $total;
        $this->assertGreaterThanOrEqual(
            90.0,
            $pct,
            sprintf('Unit includes line coverage %.2f%% (%d/%d) — see docs/PHP_TEST_BENCHMARK.md', $pct, $covered, $total)
        );
    }
}
