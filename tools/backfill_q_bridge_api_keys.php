#!/usr/bin/env php3
<?php
/**
 * Idempotent backfill: hidden q_bridge API key row per active user.
 * Usage: php tools/backfill_q_bridge_api_keys.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/public/includes/config.php';
require_once $root . '/public/includes/functions.php';

initializeDatabase();
$result = backfillQBridgeDefaultApiKeysForAllUsers();
if (!$result['success']) {
    fwrite(STDERR, "backfill failed\n");
    exit(1);
}
printf(
    "Q bridge keys: created=%d skipped=%d failed=%d\n",
    (int)$result['created'],
    (int)$result['skipped'],
    (int)$result['failed']
);
exit((int)$result['failed'] > 0 ? 1 : 0);
