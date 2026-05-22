#!/usr/bin/env php3
<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/st_resolve_' . bin2hex(random_bytes(3)) . '.db';
$wchat = sys_get_temp_dir() . '/st_wchat_' . bin2hex(random_bytes(3)) . '.db';
putenv('TASKS_DB_PATH=' . $tmp);
putenv('TASKS_Q_BRIDGE_DB_PATH=' . $wchat);
putenv('TASKS_Q_BRIDGE_KEY_SECRET=testsecret123456789012345678901234567890');
putenv('TASKS_Q_BRIDGE_POLL_API_KEY=test-poll-key-12345');
putenv('TASKS_SESSION_COOKIE_SECURE=0');
putenv('TASKS_PASSWORD_COST=8');

require dirname(__DIR__) . '/public/includes/config.php';
require dirname(__DIR__) . '/public/includes/functions.php';
initializeDatabase();

$u = createUser('resolve_u', 'ResolvePass123456', 'member', false);
$uid = (int)$u['id'];
$plain = getQBridgeDefaultApiKeyPlaintextForUser($uid);

$port = 19876;
$cmd = sprintf(
    'php -S 127.0.0.1:%d -t %s/public > /dev/null 2>&1 & echo $!',
    $port,
    dirname(__DIR__)
);
$pid = (int)trim((string)shell_exec($cmd));
usleep(400000);

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer test-poll-key-12345\r\n",
        'content' => json_encode(['tasks_user_id' => $uid]),
        'ignore_errors' => true,
    ],
]);
$body = file_get_contents("http://127.0.0.1:$port/q-bridge/api/v1/index.php?action=resolve_user_key", false, $ctx);
$code = 500;
if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
    $code = (int)$m[0];
}
posix_kill($pid, 9);

$data = json_decode((string)$body, true);
if ($code !== 200 || !($data['success'] ?? false) || ($data['data']['api_key'] ?? '') !== $plain) {
    fwrite(STDERR, "FAIL code=$code body=$body expected=$plain\n");
    exit(1);
}
echo "PASS resolve_user_key\n";
@unlink($tmp);
@unlink($wchat);
