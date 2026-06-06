<?php
/**
 * Ask Q / q-bridge rate limits — defaults with optional admin overrides (app_settings).
 */
declare(strict_types=1);

const Q_BRIDGE_RATE_LIMITS_SETTING_KEY = 'q_bridge.rate_limits';

/**
 * @return array<string, mixed>
 */
function q_bridge_rate_limit_defaults(): array
{
    return [
        'user_endpoints' => [
            '/api/messages' => 60,
            '/api/responses' => 300,
            '/api/history' => 120,
            '/api/user_session' => 30,
        ],
        'user_max_requests' => 600,
        'ip_endpoints' => [
            '/api/messages' => 50,
            '/api/responses' => 200,
            '/api/history' => 120,
            '/api/user_session' => 30,
            '/api/inbox' => 120,
            '/api/outbox' => 200,
            '/api/sessions' => 20,
        ],
        'ip_max_requests' => 1000,
    ];
}

function q_bridge_ensure_tasks_settings_loaded(): void
{
    if (function_exists('getAppSetting')) {
        return;
    }
    $publicRoot = dirname(__DIR__, 2);
    require_once $publicRoot . '/includes/config.php';
    require_once $publicRoot . '/includes/functions.php';
}

/** @var array<string, mixed>|null */
$GLOBALS['q_bridge_rate_limit_config_cache'] = null;

function q_bridge_clear_rate_limit_config_cache(): void
{
    $GLOBALS['q_bridge_rate_limit_config_cache'] = null;
}

/**
 * @return array<string, mixed>
 */
function q_bridge_get_rate_limit_config(): array
{
    if (is_array($GLOBALS['q_bridge_rate_limit_config_cache'] ?? null)) {
        return $GLOBALS['q_bridge_rate_limit_config_cache'];
    }

    $merged = q_bridge_rate_limit_defaults();
    try {
        q_bridge_ensure_tasks_settings_loaded();
        $raw = getAppSetting(Q_BRIDGE_RATE_LIMITS_SETTING_KEY);
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $merged = q_bridge_merge_rate_limit_config($merged, $decoded);
            }
        }
    } catch (Throwable $e) {
        // Fall back to file defaults if Tasks DB unavailable.
    }

    $GLOBALS['q_bridge_rate_limit_config_cache'] = $merged;
    return $merged;
}

/**
 * @param array<string, mixed> $base
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function q_bridge_merge_rate_limit_config(array $base, array $overrides): array
{
    foreach (['user_endpoints', 'ip_endpoints'] as $bucket) {
        if (!isset($overrides[$bucket]) || !is_array($overrides[$bucket])) {
            continue;
        }
        foreach ($overrides[$bucket] as $endpoint => $limit) {
            $endpoint = (string)$endpoint;
            $limit = (int)$limit;
            if ($endpoint !== '' && $limit > 0) {
                $base[$bucket][$endpoint] = $limit;
            }
        }
    }
    if (isset($overrides['user_max_requests'])) {
        $v = (int)$overrides['user_max_requests'];
        if ($v > 0) {
            $base['user_max_requests'] = $v;
        }
    }
    if (isset($overrides['ip_max_requests'])) {
        $v = (int)$overrides['ip_max_requests'];
        if ($v > 0) {
            $base['ip_max_requests'] = $v;
        }
    }
    return $base;
}

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, error?: string}
 */
function q_bridge_validate_rate_limit_input(array $input): array
{
    $check = static function (mixed $value, string $label) {
        if (!is_numeric($value)) {
            return ['success' => false, 'error' => $label . ' must be a number'];
        }
        $n = (int)$value;
        if ($n < 1 || $n > 100000) {
            return ['success' => false, 'error' => $label . ' must be between 1 and 100000'];
        }
        return ['success' => true, 'value' => $n];
    };

    $fields = [
        'messages' => '/api/messages',
        'responses' => '/api/responses',
        'history' => '/api/history',
        'user_session' => '/api/user_session',
    ];
    $userEndpoints = [];
    foreach ($fields as $field => $endpoint) {
        if (!array_key_exists($field, $input)) {
            return ['success' => false, 'error' => 'Missing ' . $field];
        }
        $r = $check($input[$field], $field);
        if (!$r['success']) {
            return $r;
        }
        $userEndpoints[$endpoint] = $r['value'];
    }

    $rMax = $check($input['user_max_requests'] ?? null, 'user_max_requests');
    if (!$rMax['success']) {
        return $rMax;
    }

    $rIp = $check($input['ip_max_requests'] ?? null, 'ip_max_requests');
    if (!$rIp['success']) {
        return $rIp;
    }

    return [
        'success' => true,
        'config' => [
            'user_endpoints' => $userEndpoints,
            'user_max_requests' => $rMax['value'],
            'ip_max_requests' => $rIp['value'],
        ],
    ];
}

/**
 * @param array<string, mixed> $config Partial config from admin form
 */
function q_bridge_save_rate_limit_config(array $config, ?int $actorUserId = null): array
{
    q_bridge_ensure_tasks_settings_loaded();
    $validated = q_bridge_validate_rate_limit_input([
        'messages' => $config['user_endpoints']['/api/messages'] ?? $config['messages'] ?? null,
        'responses' => $config['user_endpoints']['/api/responses'] ?? $config['responses'] ?? null,
        'history' => $config['user_endpoints']['/api/history'] ?? $config['history'] ?? null,
        'user_session' => $config['user_endpoints']['/api/user_session'] ?? $config['user_session'] ?? null,
        'user_max_requests' => $config['user_max_requests'] ?? null,
        'ip_max_requests' => $config['ip_max_requests'] ?? null,
    ]);
    if (!$validated['success']) {
        return $validated;
    }

    $payload = json_encode($validated['config'], JSON_THROW_ON_ERROR);
    $result = setAppSetting(Q_BRIDGE_RATE_LIMITS_SETTING_KEY, $payload, $actorUserId);
    if ($result['success'] ?? false) {
        q_bridge_clear_rate_limit_config_cache();
    }
    return $result;
}
