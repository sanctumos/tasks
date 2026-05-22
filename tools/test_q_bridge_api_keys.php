#!/usr/bin/env php3
<?php
/**
 * CLI verification for Q bridge default API keys (no PHPUnit required).
 */
declare(strict_types=1);

$failures = 0;
$assert = static function (bool $ok, string $msg) use (&$failures): void {
    if ($ok) {
        echo "OK  $msg\n";
        return;
    }
    echo "FAIL $msg\n";
    $failures++;
};

$unitDb = sys_get_temp_dir() . '/sanctum_q_bridge_test_' . bin2hex(random_bytes(4)) . '.db';
putenv('TASKS_DB_PATH=' . $unitDb);
$_ENV['TASKS_DB_PATH'] = $unitDb;
putenv('TASKS_Q_BRIDGE_KEY_SECRET=testsecret123456789012345678901234567890');
putenv('TASKS_SESSION_COOKIE_SECURE=0');
putenv('TASKS_PASSWORD_COST=8');

require dirname(__DIR__) . '/public/includes/config.php';
require dirname(__DIR__) . '/public/includes/functions.php';
initializeDatabase();

$suffix = bin2hex(random_bytes(3));
$user = createUser("qbr_{$suffix}", 'QBridgePass123456', 'member', false);
$assert($user['success'] ?? false, 'createUser succeeds');
$uid = (int)($user['id'] ?? 0);
$assert($uid > 0, 'user id assigned');

$visible = listApiKeysForUser($uid);
$assert(count($visible) === 0, 'hidden key not listed for user');

$plain = getQBridgeDefaultApiKeyPlaintextForUser($uid);
$assert(is_string($plain) && str_starts_with($plain, 'stq_'), 'plaintext key format');

$auth = validateApiKeyAndGetUser((string)$plain);
$assert(is_array($auth) && (int)$auth['id'] === $uid, 'key authenticates as user');

$again = ensureQBridgeDefaultApiKeyForUser($uid);
$assert(($again['success'] ?? false) && !($again['created'] ?? true), 'ensure idempotent');

$r1 = backfillQBridgeDefaultApiKeysForAllUsers();
$r2 = backfillQBridgeDefaultApiKeysForAllUsers();
$assert(($r1['success'] ?? false) && ($r2['success'] ?? false), 'backfill runs');
$assert((int)($r2['created'] ?? -1) === 0, 'second backfill creates none');

@unlink($unitDb);
exit($failures > 0 ? 1 : 0);
