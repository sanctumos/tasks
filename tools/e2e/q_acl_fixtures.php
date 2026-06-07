#!/usr/bin/env php3
<?php
/**
 * CLI: bootstrap Q ACL E2E fixtures and print JSON manifest.
 *
 * Usage: php tools/e2e/q_acl_fixtures.php [--pretty]
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/public/includes/config.php';
require_once $root . '/public/includes/functions.php';
require_once __DIR__ . '/q_acl_fixture_lib.php';

initializeDatabase();

$result = bootstrapQAclE2eFixtures();
if (!($result['success'] ?? false)) {
    fwrite(STDERR, 'bootstrap failed: ' . (string)($result['error'] ?? 'unknown') . PHP_EOL);
    exit(1);
}

$pretty = in_array('--pretty', $argv, true);
$flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0;
echo json_encode($result['manifest'], $flags) . PHP_EOL;
