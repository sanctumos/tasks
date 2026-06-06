<?php
/**
 * UI Skin Lab — host guard + skin slug resolution (dev host + prod prefs).
 */
require_once __DIR__ . '/functions.php';

/** Slugs available in the skin lab comp bar. */
function skinLabAvailableSlugs(): array {
    return ['hey', 'ledger', 'brutalist', 'obsidian'];
}

function skinLabNormalizeSlug(?string $slug): ?string {
    $s = strtolower(trim((string)$slug));
    return in_array($s, skinLabAvailableSlugs(), true) ? $s : null;
}

/** True on dev.tasks.decisionsciencecorp.com (comp bar + live toggles). */
function isSkinLabDevHost(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return $host === 'dev.tasks.decisionsciencecorp.com';
}

function skinLabMasterSlug(): string {
    $raw = getAppSetting('master_skin_slug');
    return skinLabNormalizeSlug($raw) ?? 'hey';
}

function skinLabOrgDefaultSlug(?array $userRow = null): string {
    $orgId = 0;
    if ($userRow !== null) {
        $orgId = getEffectiveDirectoryOrgId($userRow);
    }
    if ($orgId <= 0) {
        return skinLabMasterSlug();
    }
    $org = getOrganizationById($orgId);
    if (!$org || empty($org['settings_json'])) {
        return skinLabMasterSlug();
    }
    $settings = json_decode((string)$org['settings_json'], true);
    if (!is_array($settings)) {
        return skinLabMasterSlug();
    }
    return skinLabNormalizeSlug($settings['default_skin_slug'] ?? null) ?? skinLabMasterSlug();
}

function skinLabUserOverrideSlug(?array $userRow): ?string {
    if (!$userRow) {
        return null;
    }
    $raw = $userRow['skin_slug'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    return skinLabNormalizeSlug((string)$raw);
}

/** Effective skin for the current request (user override → org default → master → hey). */
function skinLabEffectiveSlug(?array $userRow = null): string {
    $user = $userRow;
    if ($user === null && function_exists('getCurrentUser')) {
        $user = getCurrentUser();
    }
    $override = skinLabUserOverrideSlug($user);
    if ($override !== null) {
        return $override;
    }
    return skinLabOrgDefaultSlug($user);
}

function skinLabShouldShowCompBar(): bool {
    return isSkinLabDevHost();
}
