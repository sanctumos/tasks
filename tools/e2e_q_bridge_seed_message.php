#!/usr/bin/env php3
<?php
/**
 * Seed one unprocessed webchat message on prod q_bridge DB.
 * Run on multihost: php tools/e2e_q_bridge_seed_message.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('TASKS_Q_BRIDGE_DB_PATH=/var/www/tasks.decisionsciencecorp.com/db/q_bridge_webchat.db');
require_once $root . '/public/q-bridge/config/database.php';
require_once $root . '/public/q-bridge/includes/utils.php';
require_once $root . '/public/q-bridge/includes/auth.php';

init_database();
$pdo = get_db_connection();

$sid = 'otto-e2e-' . date('Ymd-His');
$meta = ['tasks_user_id' => 4];
$msg = 'E2E: Hi Q — reply with exactly: "Q Vernal online."';
$ts = date('c');

create_session($sid, $meta);
$ip = '127.0.0.1';
$user_data = get_or_create_web_chat_user($sid, $ip);
$uid = $user_data['uid'];

$stmt = $pdo->prepare(
    'INSERT INTO web_chat_messages (session_id, message, timestamp, processed) VALUES (?, ?, ?, 0)'
);
$stmt->execute([$sid, $msg, $ts]);

echo "seeded session=$sid uid=$uid tasks_user_id=4\n";
