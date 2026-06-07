<?php
/**
 * Settings — single page for account + workspace administration.
 * Tabs: Password · MFA · API keys · Audit
 *  (API keys / Audit are admin-only.)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser) {
    auth_redirect_to_login();
    exit();
}

$isAdmin = isAdminRole((string)($currentUser['role'] ?? ''));

$availableTabs = [
    'password' => ['label' => 'Password', 'icon' => 'bi-asterisk', 'admin' => false],
    'appearance' => ['label' => 'Appearance', 'icon' => 'bi-palette', 'admin' => false],
    'mfa' => ['label' => 'MFA', 'icon' => 'bi-shield-lock', 'admin' => false],
    'api-keys' => ['label' => 'API keys', 'icon' => 'bi-key', 'admin' => true],
    'audit' => ['label' => 'Audit log', 'icon' => 'bi-shield-check', 'admin' => true],
];
if (defined('TASKS_Q_BRIDGE_ENABLED') && TASKS_Q_BRIDGE_ENABLED) {
    $availableTabs['ask-q'] = ['label' => 'Ask Q', 'icon' => 'bi-chat-dots', 'admin' => true];
}

$tab = (string)($_GET['tab'] ?? 'password');
if (!isset($availableTabs[$tab])) {
    $tab = 'password';
}
if ($availableTabs[$tab]['admin'] && !$isAdmin) {
    $tab = 'password';
}

$pageTitle = 'Settings · ' . $availableTabs[$tab]['label'];
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
];
if ($tab === 'password') {
    $adminBreadcrumbs[] = ['label' => 'Settings'];
} else {
    $adminBreadcrumbs[] = ['href' => '/admin/settings.php', 'label' => 'Settings'];
    $adminBreadcrumbs[] = ['label' => $availableTabs[$tab]['label']];
}
require __DIR__ . '/_layout_top.php';

function st_settings_tab_link(string $tab, string $active, array $availableTabs, bool $isAdmin): string {
    if ($availableTabs[$tab]['admin'] && !$isAdmin) return '';
    $cls = $active === $tab ? 'active' : '';
    $href = '/admin/settings.php?tab=' . urlencode($tab);
    $aria = $active === $tab ? ' aria-current="page"' : '';
    return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '"' . $aria . '>'
        . '<i class="bi ' . htmlspecialchars($availableTabs[$tab]['icon']) . '"></i>'
        . '<span>' . htmlspecialchars($availableTabs[$tab]['label']) . '</span>'
        . '</a>';
}
?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Settings</h1>
        <div class="subtitle">Account &amp; workspace administration for <code><?= htmlspecialchars($currentUser['username']) ?></code></div>
    </div>
    <div class="page-header__actions d-flex align-items-center flex-wrap gap-2">
        <?= st_doc_help('settings-more', 'Password MFA API keys audit') ?>
    </div>
</div>

<nav class="tabbar" aria-label="Settings sections">
    <?php foreach ($availableTabs as $slug => $meta): ?>
        <?= st_settings_tab_link($slug, $tab, $availableTabs, $isAdmin) ?>
    <?php endforeach; ?>
</nav>

<?php
switch ($tab) {
    case 'password':
        require __DIR__ . '/_settings/password.php';
        break;
    case 'appearance':
        require __DIR__ . '/_settings/appearance.php';
        break;
    case 'mfa':
        require __DIR__ . '/_settings/mfa.php';
        break;
    case 'api-keys':
        if ($isAdmin) {
            require __DIR__ . '/_settings/api_keys.php';
        }
        break;
    case 'audit':
        if ($isAdmin) {
            require __DIR__ . '/_settings/audit.php';
        }
        break;
    case 'ask-q':
        if ($isAdmin) {
            require __DIR__ . '/_settings/ask_q.php';
        }
        break;
}
?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
