<?php
declare(strict_types=1);

/**
 * BootstrapConfig — allowlisted public/session-safe boot JSON for the companion shell.
 * Rejects hidden keys (poll/admin bearers, secrets). No framework.
 */
final class BootstrapConfig
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'apiBase',
        'csrfToken',
        'useSessionAuth',
        'theme',
        'greeting',
        'sessionId',
        'historyLimit',
        'pollIntervalMs',
        'pageContext',
        'mount',
    ];

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'pollApiKey',
        'poll_api_key',
        'adminApiKey',
        'admin_api_key',
        'bearer',
        'authorization',
        'Authorization',
        'apiKey',
        'api_key',
        'secret',
        'password',
        'token',
    ];

    /**
     * @param mixed $raw
     * @return array{ok:bool,reason?:string,config?:array}
     */
    public static function validate($raw): array
    {
        if (!is_array($raw)) {
            return ['ok' => false, 'reason' => 'not-object'];
        }
        foreach (array_keys($raw) as $key) {
            if (!is_string($key)) {
                return ['ok' => false, 'reason' => 'key-type'];
            }
            if (in_array($key, self::FORBIDDEN_KEYS, true)) {
                return ['ok' => false, 'reason' => 'forbidden-key'];
            }
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                return ['ok' => false, 'reason' => 'unknown-key'];
            }
        }

        $config = [];
        if (isset($raw['apiBase'])) {
            if (!is_string($raw['apiBase']) || $raw['apiBase'] === '') {
                return ['ok' => false, 'reason' => 'apiBase'];
            }
            // Same-origin relative or http(s) only — no javascript:/data:
            if (!preg_match('#^(/|https?://)#i', $raw['apiBase'])) {
                return ['ok' => false, 'reason' => 'apiBase-scheme'];
            }
            $config['apiBase'] = $raw['apiBase'];
        }
        if (array_key_exists('csrfToken', $raw)) {
            if ($raw['csrfToken'] !== null && !is_string($raw['csrfToken'])) {
                return ['ok' => false, 'reason' => 'csrfToken'];
            }
            $config['csrfToken'] = $raw['csrfToken'];
        }
        if (isset($raw['useSessionAuth'])) {
            if (!is_bool($raw['useSessionAuth'])) {
                return ['ok' => false, 'reason' => 'useSessionAuth'];
            }
            $config['useSessionAuth'] = $raw['useSessionAuth'];
        }
        if (isset($raw['theme'])) {
            if (!is_string($raw['theme']) || !in_array($raw['theme'], ['light', 'dark'], true)) {
                return ['ok' => false, 'reason' => 'theme'];
            }
            $config['theme'] = $raw['theme'];
        }
        if (isset($raw['greeting'])) {
            if (!is_string($raw['greeting']) || strlen($raw['greeting']) > 500) {
                return ['ok' => false, 'reason' => 'greeting'];
            }
            $config['greeting'] = $raw['greeting'];
        }
        if (isset($raw['sessionId'])) {
            if (!is_string($raw['sessionId']) || strlen($raw['sessionId']) > 128) {
                return ['ok' => false, 'reason' => 'sessionId'];
            }
            $config['sessionId'] = $raw['sessionId'];
        }
        if (isset($raw['historyLimit'])) {
            if (!is_int($raw['historyLimit']) || $raw['historyLimit'] < 1 || $raw['historyLimit'] > 500) {
                return ['ok' => false, 'reason' => 'historyLimit'];
            }
            $config['historyLimit'] = $raw['historyLimit'];
        }
        if (isset($raw['pollIntervalMs'])) {
            if (!is_int($raw['pollIntervalMs']) || $raw['pollIntervalMs'] < 500 || $raw['pollIntervalMs'] > 60000) {
                return ['ok' => false, 'reason' => 'pollIntervalMs'];
            }
            $config['pollIntervalMs'] = $raw['pollIntervalMs'];
        }
        if (array_key_exists('pageContext', $raw)) {
            if ($raw['pageContext'] !== null && !is_array($raw['pageContext'])) {
                return ['ok' => false, 'reason' => 'pageContext'];
            }
            $config['pageContext'] = $raw['pageContext'];
        }
        if (isset($raw['mount'])) {
            if (!is_string($raw['mount']) || $raw['mount'] === '' || strlen($raw['mount']) > 64) {
                return ['ok' => false, 'reason' => 'mount'];
            }
            $config['mount'] = $raw['mount'];
        }

        return ['ok' => true, 'config' => $config];
    }
}
