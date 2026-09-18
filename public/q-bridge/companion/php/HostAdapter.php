<?php
declare(strict_types=1);

/**
 * HostAdapter — thin host contract for companion boot config.
 *
 * A Tasks (or lab) host supplies these keys into the shell bootstrap
 * (e.g. window.__COMPANION_BOOT__ / companion-boot.php). Full public
 * allowlist (including sessionId, pollIntervalMs, mount) is BootstrapConfig;
 * this surface is the host-facing subset.
 *
 * Keys:
 *   apiBase         string   same-origin bridge base (e.g. /q-bridge/api/v1/)
 *   csrfToken       ?string  session CSRF for POSTs; null in lab
 *   useSessionAuth  bool     true on Tasks; false in echo lab
 *   theme           string   'light' | 'dark'
 *   greeting        string   opening assistant line
 *   pageContext     ?array   optional page/task context blob
 *
 * Implementors expose bootConfig(); validate() checks an array of these keys.
 */
interface HostAdapter
{
    /**
     * @return array{
     *   apiBase: string,
     *   csrfToken: ?string,
     *   useSessionAuth: bool,
     *   theme: string,
     *   greeting: string,
     *   pageContext: ?array
     * }
     */
    public function bootConfig(): array;
}

/**
 * Static validation for HostAdapter boot arrays (host key subset).
 */
final class HostAdapterBoot
{
    /** @var list<string> */
    public const KEYS = [
        'apiBase',
        'csrfToken',
        'useSessionAuth',
        'theme',
        'greeting',
        'pageContext',
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
            if (!is_string($key) || !in_array($key, self::KEYS, true)) {
                return ['ok' => false, 'reason' => 'unknown-key'];
            }
        }
        if (!class_exists('BootstrapConfig', false)) {
            $bootPath = __DIR__ . '/BootstrapConfig.php';
            if (is_readable($bootPath)) {
                require_once $bootPath;
            }
        }
        return BootstrapConfig::validate($raw);
    }
}
