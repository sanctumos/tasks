#!/usr/bin/env php
<?php
/**
 * Deployed copy of tools/board-export-worker.php (multihost only syncs public/).
 * Usage: php public/cli/board-export-worker.php <job_id>
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

require_once dirname(__DIR__) . '/includes/functions.php';

$result = processBoardExportJob($jobId);
if (empty($result['success'])) {
    fwrite(STDERR, 'FAIL job ' . $jobId . ': ' . ($result['error'] ?? 'unknown') . "\n");
    exit(2);
}
fwrite(STDOUT, 'OK job ' . $jobId . "\n");
exit(0);
