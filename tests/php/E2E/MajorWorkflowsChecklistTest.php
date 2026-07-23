<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * E2E category gate: ≥90% of required major workflows have a design-smoke script.
 * See docs/MAJOR_WORKFLOWS.md and docs/PHP_TEST_BENCHMARK.md.
 */
final class MajorWorkflowsChecklistTest extends TestCase
{
    public function testRequiredWorkflowScriptsPresentAtNinetyPercent(): void
    {
        $root = dirname(__DIR__, 3);
        $doc = $root . '/docs/MAJOR_WORKFLOWS.md';
        $this->assertFileExists($doc);

        $required = [
            'admin_shell.py',
            'admin_walkthrough.py',
            'task_view_verify.py',
            'lists_view_screenshots.py',
            'schedule_verify.py',
            'doors_verify.py',
            'docs_verify.py',
            'mobile_nav_toggler_verify.py',
            'ask_q_verify.py',
            'ask_q_multiturn_verify.py',
            'ask_q_composer_paste_verify.py',
            'ask_q_reload_persist_verify.py',
            'board_export_archives_verify.py',
        ];
        $smoke = $root . '/tools/design-smoke';
        $present = 0;
        $missing = [];
        foreach ($required as $script) {
            if (is_file($smoke . '/' . $script)) {
                $present++;
            } else {
                $missing[] = $script;
            }
        }
        $pct = 100.0 * $present / max(1, count($required));
        $this->assertGreaterThanOrEqual(
            90.0,
            $pct,
            'Required major workflows covered=' . $present . '/' . count($required)
            . ' (' . number_format($pct, 1) . '%). Missing: ' . implode(', ', $missing)
        );
    }
}
