#!/usr/bin/env php
<?php
/**
 * CLI worker: process one project_board_exports job.
 * Usage: php tools/board-export-worker.php <job_id>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: php board-export-worker.php <job_id>\n");
    exit(1);
}

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/public/includes/functions.php';

$result = processBoardExportJob($jobId);
if (empty($result['success'])) {
    fwrite(STDERR, 'FAIL job ' . $jobId . ': ' . ($result['error'] ?? 'unknown') . "\n");
    exit(2);
}
fwrite(STDOUT, 'OK job ' . $jobId . "\n");
exit(0);
