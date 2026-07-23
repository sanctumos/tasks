<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration category gate: ≥90% of critical API flows have an Integration test class.
 * See docs/CRITICAL_API_FLOWS.md.
 */
final class CriticalApiFlowsChecklistTest extends TestCase
{
    public function testCriticalApiFlowsDocumentedAndCovered(): void
    {
        $root = dirname(__DIR__, 3);
        $doc = $root . '/docs/CRITICAL_API_FLOWS.md';
        $this->assertFileExists($doc);
        $body = (string)file_get_contents($doc);

        $flows = [
            'A01' => 'ApiHealthAndTaskFlowTest',
            'A02' => 'ApiHealthAndTaskFlowTest',
            'A03' => 'ApiHealthAndTaskFlowTest',
            'A04' => 'SharedDocumentAssetHttpTest',
            'A05' => 'QBridgeSecurityHttpTest',
            'A06' => 'BoardExportHttpTest',
            'A07' => 'BoardExportHttpTest',
            'A08' => 'BoardExportHttpTest',
            'A09' => 'BoardExportHttpTest',
            'A10' => 'BoardExportHttpTest',
        ];
        $ok = 0;
        $bad = [];
        foreach ($flows as $id => $class) {
            $file = $root . '/tests/php/Integration/' . $class . '.php';
            if (is_file($file) && str_contains($body, $id)) {
                $ok++;
            } else {
                $bad[] = $id;
            }
        }
        $pct = 100.0 * $ok / max(1, count($flows));
        $this->assertGreaterThanOrEqual(
            90.0,
            $pct,
            'Critical API flows covered=' . $ok . '/' . count($flows)
            . ' (' . number_format($pct, 1) . '%). Gaps: ' . implode(', ', $bad)
        );
    }
}
