<?php
/**
 * Ask Q connection — enable/disable + Sanctum target (URL + agent).
 * Stored in Tasks app_settings; UI gated by q_bridge_is_ui_enabled().
 */
declare(strict_types=1);

const Q_BRIDGE_CONNECTION_SETTING_KEY = 'q_bridge.connection';

/**
 * @return array{enabled: bool, sanctum_url: string, agent_id: string, agent_label: string}
 */
function q_bridge_connection_defaults(): array
{
    return [
        // When no app_settings row exists, fall back to TASKS_Q_BRIDGE_ENABLED env
        // (default true for existing DSC installs). Explicit settings always win.
        'enabled' => defined('TASKS_Q_BRIDGE_ENABLED') ? (bool)TASKS_Q_BRIDGE_ENABLED : true,
        'sanctum_url' => '',
        'agent_id' => '',
        'agent_label' => 'Q. Vernal',
    ];
}

function q_bridge_ensure_tasks_core_loaded(): void
{
    if (function_exists('getAppSetting')) {
        return;
    }
    $publicRoot = dirname(__DIR__, 2);
    require_once $publicRoot . '/includes/config.php';
    require_once $publicRoot . '/includes/functions.php';
}

/** @var array<string, mixed>|null */
$GLOBALS['q_bridge_connection_config_cache'] = null;

function q_bridge_clear_connection_config_cache(): void
{
    $GLOBALS['q_bridge_connection_config_cache'] = null;
}

/**
 * @return array{enabled: bool, sanctum_url: string, agent_id: string, agent_label: string}
 */
function q_bridge_get_connection_config(): array
{
    if (is_array($GLOBALS['q_bridge_connection_config_cache'] ?? null)) {
        /** @var array{enabled: bool, sanctum_url: string, agent_id: string, agent_label: string} */
        return $GLOBALS['q_bridge_connection_config_cache'];
    }

    $cfg = q_bridge_connection_defaults();
    try {
        q_bridge_ensure_tasks_core_loaded();
        $raw = getAppSetting(Q_BRIDGE_CONNECTION_SETTING_KEY);
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                if (array_key_exists('enabled', $decoded)) {
                    $parsed = filter_var($decoded['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($parsed !== null) {
                        $cfg['enabled'] = $parsed;
                    }
                }
                if (isset($decoded['sanctum_url']) && is_string($decoded['sanctum_url'])) {
                    $cfg['sanctum_url'] = trim($decoded['sanctum_url']);
                }
                if (isset($decoded['agent_id']) && is_string($decoded['agent_id'])) {
                    $cfg['agent_id'] = trim($decoded['agent_id']);
                }
                if (isset($decoded['agent_label']) && is_string($decoded['agent_label'])) {
                    $label = trim($decoded['agent_label']);
                    if ($label !== '') {
                        $cfg['agent_label'] = $label;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Fall back to defaults if Tasks DB unavailable.
    }

    $GLOBALS['q_bridge_connection_config_cache'] = $cfg;
    return $cfg;
}

function q_bridge_is_ui_enabled(): bool
{
    // Hard kill-switch via env still works even if settings say on.
    if (defined('TASKS_Q_BRIDGE_ENABLED') && !TASKS_Q_BRIDGE_ENABLED) {
        return false;
    }
    $cfg = q_bridge_get_connection_config();
    return !empty($cfg['enabled']);
}

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, error?: string, config?: array{enabled: bool, sanctum_url: string, agent_id: string, agent_label: string}}
 */
function q_bridge_save_connection_config(array $input, ?int $actorUserId = null): array
{
    q_bridge_ensure_tasks_core_loaded();

    $enabled = !empty($input['enabled']);
    $sanctumUrl = trim((string)($input['sanctum_url'] ?? ''));
    $agentId = trim((string)($input['agent_id'] ?? ''));
    $agentLabel = trim((string)($input['agent_label'] ?? ''));
    if ($agentLabel === '') {
        $agentLabel = 'Q. Vernal';
    }

    if ($sanctumUrl !== '') {
        if (!preg_match('#^https?://#i', $sanctumUrl)) {
            return ['success' => false, 'error' => 'Sanctum URL must start with http:// or https://'];
        }
        if (strlen($sanctumUrl) > 500) {
            return ['success' => false, 'error' => 'Sanctum URL is too long'];
        }
    }
    if ($agentId !== '' && !preg_match('/^[a-zA-Z0-9._:-]{1,120}$/', $agentId)) {
        return ['success' => false, 'error' => 'Agent id has invalid characters'];
    }
    if (strlen($agentLabel) > 80) {
        return ['success' => false, 'error' => 'Agent display name is too long'];
    }

    $cfg = [
        'enabled' => $enabled,
        'sanctum_url' => $sanctumUrl,
        'agent_id' => $agentId,
        'agent_label' => $agentLabel,
    ];

    $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['success' => false, 'error' => 'Could not encode connection settings'];
    }

    $result = setAppSetting(Q_BRIDGE_CONNECTION_SETTING_KEY, $json, $actorUserId);
    if (empty($result['success'])) {
        return ['success' => false, 'error' => $result['error'] ?? 'Could not save connection settings'];
    }

    q_bridge_clear_connection_config_cache();
    return ['success' => true, 'config' => $cfg];
}
