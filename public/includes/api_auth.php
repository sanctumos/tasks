<?php
require_once __DIR__ . '/functions.php';

function jsonResponse($data, $statusCode = 200, array $headers = []): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($data);
    exit();
}

function apiError(string $code, string $message, int $statusCode = 400, array $details = [], array $extra = []): void {
    $payload = array_merge([
        'success' => false,
        // Keep legacy key for older SDK clients.
        'error' => $message,
        'error_object' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
    ], $extra);

    jsonResponse($payload, $statusCode);
}

function apiSuccess(array $payload = [], array $meta = [], int $statusCode = 200): void {
    $response = array_merge($payload, ['success' => true]);
    $response['data'] = $payload;
    if (!empty($meta)) {
        $response['meta'] = $meta;
    }
    jsonResponse($response, $statusCode);
}

function readJsonBody(): ?array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded;
}

function getApiKeyFromRequest(): ?string {
    // Primary: X-API-Key header.
    if (isset($_SERVER['HTTP_X_API_KEY']) && trim($_SERVER['HTTP_X_API_KEY']) !== '') {
        return trim($_SERVER['HTTP_X_API_KEY']);
    }
    // Authorization: Bearer <token>
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.+)/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
        return trim($m[1]);
    }
    // Query-string auth removed (C-04): keys must be sent via header only to avoid logs/referrer leakage.
    return null;
}

function setRateLimitHeaders(array $rateState): void {
    header('X-RateLimit-Limit: ' . (int)($rateState['limit'] ?? 0));
    header('X-RateLimit-Remaining: ' . (int)($rateState['remaining'] ?? 0));
    header('X-RateLimit-Reset: ' . (int)($rateState['reset_epoch'] ?? time()));
}

function requestScheme(): string {
    if (tasksTrustedProxyConnection()) {
        $xfp = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($xfp === 'https' || $xfp === 'http') {
            return $xfp;
        }
    }
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    return ($https !== '' && $https !== 'off') ? 'https' : 'http';
}

function sanitizeUrlHost(string $host): ?string {
    $host = trim($host);
    if ($host === '') {
        return null;
    }

    if (substr($host, 0, 1) === '[') {
        $closingBracket = strpos($host, ']');
        if ($closingBracket === false) {
            return null;
        }
        $host = substr($host, 1, $closingBracket - 1);
    } elseif (substr_count($host, ':') === 1) {
        [$rawHost, $rawPort] = explode(':', $host, 2);
        if ($rawPort !== '' && !ctype_digit($rawPort)) {
            return null;
        }
        $host = $rawHost;
    }

    $host = strtolower(trim($host));
    if ($host === '') {
        return null;
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return $host;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return '[' . $host . ']';
    }
    if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
        return $host;
    }
    if ($host === 'localhost') {
        return $host;
    }
    return null;
}

function normalizedPortFromServer(string $scheme): ?int {
    if (!isset($_SERVER['SERVER_PORT'])) {
        return null;
    }

    $port = (int)$_SERVER['SERVER_PORT'];
    if ($port <= 0 || $port > 65535) {
        return null;
    }
    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
        return null;
    }
    return $port;
}

/** Prefer X-Forwarded-Port when behind a trusted proxy; otherwise SERVER_PORT. */
function normalizedPortFromForwardedOrServer(string $scheme): ?int {
    if (tasksTrustedProxyConnection()) {
        $raw = trim((string)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? ''));
        if ($raw !== '' && ctype_digit($raw)) {
            $port = (int)$raw;
            if ($port > 0 && $port <= 65535) {
                if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
                    return null;
                }
                return $port;
            }
        }
    }
    return normalizedPortFromServer($scheme);
}

/**
 * Recover from broken env paste like "https://ahttps://b" — keep leftmost absolute origin.
 */
function sanitizeTasksEnvAppBaseUrl(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $parts = preg_split('#(?=https?://)#i', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) > 1) {
        return trim($parts[0]);
    }
    return trim($parts[0] ?? $raw);
}

function configuredAppOrigin(): ?string {
    $configured = sanitizeTasksEnvAppBaseUrl(trim((string)envOrDefault('TASKS_APP_BASE_URL', '')));
    if ($configured === '') {
        return null;
    }

    $parts = parse_url($configured);
    if (!is_array($parts)) {
        return null;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $host = sanitizeUrlHost((string)($parts['host'] ?? ''));
    if ($host === null) {
        return null;
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    if ($port !== null && ($port <= 0 || $port > 65535)) {
        return null;
    }

    $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    $portSuffix = ($port !== null && !$isDefaultPort) ? (':' . $port) : '';
    return $scheme . '://' . $host . $portSuffix;
}

function requestOrigin(): string {
    $configured = configuredAppOrigin();
    if ($configured !== null) {
        return $configured;
    }

    $scheme = requestScheme();

    // Trusted reverse proxy: prefer forwarded virtual host (public hostname), not the upstream bind IP.
    if (tasksTrustedProxyConnection()) {
        $xfh = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''), 2)[0]);
        $san = sanitizeUrlHost($xfh);
        if ($san !== null) {
            $port = normalizedPortFromForwardedOrServer($scheme);
            $portSuffix = $port !== null ? (':' . $port) : '';
            return $scheme . '://' . $san . $portSuffix;
        }
    }

    $host = 'localhost';
    // Prefer SERVER_NAME (vhost / server_name) over SERVER_ADDR (bind IP); never trust client Host here (SSRF).
    foreach ([
        (string)($_SERVER['SERVER_NAME'] ?? ''),
        (string)($_SERVER['SERVER_ADDR'] ?? ''),
    ] as $candidate) {
        $candidate = trim(explode(',', $candidate, 2)[0]);
        if ($candidate === '') {
            continue;
        }
        $sanitized = sanitizeUrlHost($candidate);
        if ($sanitized !== null) {
            $host = $sanitized;
            break;
        }
    }
    $port = normalizedPortFromForwardedOrServer($scheme);
    $portSuffix = $port !== null ? (':' . $port) : '';
    return $scheme . '://' . $host . $portSuffix;
}

function buildAbsoluteUrl(string $path, array $queryParams = []): string {
    $query = http_build_query($queryParams);
    return requestOrigin() . $path . ($query ? ('?' . $query) : '');
}

function paginationMeta(string $path, array $baseQueryParams, int $limit, int $offset, int $total): array {
    $nextOffset = ($offset + $limit < $total) ? ($offset + $limit) : null;
    $prevOffset = ($offset - $limit >= 0) ? ($offset - $limit) : null;

    $nextUrl = null;
    if ($nextOffset !== null) {
        $nextUrl = buildAbsoluteUrl($path, array_merge($baseQueryParams, ['limit' => $limit, 'offset' => $nextOffset]));
    }

    $prevUrl = null;
    if ($prevOffset !== null) {
        $prevUrl = buildAbsoluteUrl($path, array_merge($baseQueryParams, ['limit' => $limit, 'offset' => $prevOffset]));
    }

    return [
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'next_offset' => $nextOffset,
        'prev_offset' => $prevOffset,
        'next_url' => $nextUrl,
        'prev_url' => $prevUrl,
    ];
}

function requireApiUser(bool $requireAdmin = false): array {
    initializeDatabase();
    $apiKey = getApiKeyFromRequest();
    if (!$apiKey) {
        apiError('auth.invalid_api_key', 'Invalid or missing API key', 401);
    }

    $user = validateApiKeyAndGetUser($apiKey);
    if (!$user) {
        apiError('auth.invalid_api_key', 'Invalid or missing API key', 401);
    }

    $rateState = checkApiRateLimit($apiKey);
    setRateLimitHeaders($rateState);
    if (empty($rateState['allowed'])) {
        header('Retry-After: ' . (int)($rateState['retry_after'] ?? 1));
        apiError(
            'rate_limited',
            'Rate limit exceeded. Slow down and retry later.',
            429,
            ['retry_after' => (int)($rateState['retry_after'] ?? 1)],
            ['rate_limit' => $rateState]
        );
    }

    if ($requireAdmin && !isAdminRole((string)$user['role'])) {
        apiError('auth.forbidden', 'Admin role required', 403);
    }

    return $user;
}

function requireAdminApiUser(): array {
    return requireApiUser(true);
}

/**
 * Canonical HTTPS/HTML origin used in `public_share_url` for documents.
 */
function tasksDocumentShareAbsoluteUrl(?string $maybeHexToken): ?string {
    $t = normalizeDocumentPublicLinkHexToken((string)$maybeHexToken);
    if ($t === null) {
        return null;
    }

    $origin = configuredAppOrigin();
    if ($origin === null) {
        // Prefer TASKS_APP_BASE_URL; fallback to inferred request origin (dev / relative installs).
        $origin = requestOrigin();
    }

    return $origin . '/shared-document.php?token=' . rawurlencode($t);
}

/**
 * Remove `public_link_token` from document JSON while exposing optional `public_share_url`.
 *
 * Works for both hydrated `public_link_enabled` (bool|string|int) and raw DB values.
 *
 * @param array<string,mixed> $doc
 * @return array<string,mixed>
 */
function sanitizeDocumentForApiPayload(array $doc): array {
    $rawTok = isset($doc['public_link_token']) ? (string)$doc['public_link_token'] : '';
    $normTok = normalizeDocumentPublicLinkHexToken($rawTok);
    unset($doc['public_link_token']);

    $enabledRaw = $doc['public_link_enabled'] ?? false;
    $enabledIntent = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($enabledIntent === null) {
        $enabledIntent = ((int)$enabledRaw) === 1;
    }

    $effective = ($enabledIntent === true) && ($normTok !== null);
    $doc['public_link_enabled'] = $effective;
    $doc['public_share_url'] = ($effective ? tasksDocumentShareAbsoluteUrl($normTok) : null);

    return $doc;
}
