<?php
/**
 * Settings tab: appearance / UI skin preference.
 */
require_once __DIR__ . '/../../includes/skin-lab-env.php';

$appearance_error = null;
$appearance_success = null;
$skinSlugs = skinLabAvailableSlugs();
$orgDefault = skinLabOrgDefaultSlug($currentUser);
$userOverride = skinLabUserOverrideSlug($currentUser);
$effective = skinLabEffectiveSlug($currentUser);
$masterSkin = skinLabMasterSlug();
$isAdminAppearance = isAdminRole((string)($currentUser['role'] ?? ''));
$appNameSetting = getAppName();
$homeWidgets = getHomeWidgetsForUser($currentUser);

$homeWidgetLabels = [
    'pulse_kpis' => ['Pulse KPIs', 'COUNT strip at the top of Home'],
    'my_work' => ['My Work', 'Limited list of your open tasks'],
    'board_health' => ['Board health', 'Per-project attention cards'],
    'inbox_peek' => ['Inbox peek', 'Recent unread notifications'],
    'schedule_peek' => ['Schedule peek', 'Next few schedule items'],
    'projects_hub' => ['Projects hub', 'Grid of boards you can access'],
    'cross_project_board' => ['Cross-project board', 'Loads all reachable tasks — slow'],
    'recent_activity' => ['Recent activity', 'Last 10 events across projects'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['settings_action'] ?? '') === 'save_branding') {
    requireCsrfToken();
    if (!$isAdminAppearance) {
        $appearance_error = 'Admin role required.';
    } else {
        $result = updateAppNameSetting((string)($_POST['app_name'] ?? ''), (int)$currentUser['id']);
        if ($result['success']) {
            $appNameSetting = (string)($result['app_name'] ?? getAppName());
            $appearance_success = 'Branding saved. Header and login will show the new name.';
        } else {
            $appearance_error = $result['error'] ?? 'Could not save branding.';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['settings_action'] ?? '') === 'save_home_widgets') {
    requireCsrfToken();
    $posted = [];
    foreach (homeWidgetKeys() as $key) {
        $posted[$key] = isset($_POST['home_widget'][$key]);
    }
    $result = updateUserHomeWidgets((int)$currentUser['id'], $posted);
    if ($result['success']) {
        $homeWidgets = (array)($result['home_widgets'] ?? getHomeWidgetsForUser($currentUser));
        $appearance_success = 'Home widgets saved. Open Home to see the new layout.';
        $currentUser = getCurrentUser() ?: $currentUser;
    } else {
        $appearance_error = $result['error'] ?? 'Could not save home widgets.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['settings_action'] ?? '') === 'save_appearance') {
    requireCsrfToken();
    $choice = (string)($_POST['skin_choice'] ?? '');
    $result = updateUserSkinPreference((int)$currentUser['id'], $choice === '__org__' ? null : $choice);
    if ($result['success']) {
        $appearance_success = 'Appearance saved. Reloading may be needed for every page to pick up the skin.';
        $currentUser = getCurrentUser();
        $userOverride = skinLabUserOverrideSlug($currentUser);
        $effective = skinLabEffectiveSlug($currentUser);
    } else {
        $appearance_error = $result['error'] ?? 'Could not save appearance.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['settings_action'] ?? '') === 'save_master_skin') {
    requireCsrfToken();
    if (!$isAdminAppearance) {
        $appearance_error = 'Admin role required.';
    } else {
        $choice = (string)($_POST['master_skin_slug'] ?? '');
        $result = updateMasterSkinPreference($choice, (int)$currentUser['id']);
        if ($result['success']) {
            $masterSkin = (string)($result['master_skin_slug'] ?? skinLabMasterSlug());
            $appearance_success = 'Master theme saved. It applies where no organization/user override is set.';
            $effective = skinLabEffectiveSlug($currentUser);
        } else {
            $appearance_error = $result['error'] ?? 'Could not save master theme.';
        }
    }
}

$skinLabels = [
    'hey' => 'HEY Bold',
    'ledger' => 'Ledger & Ink',
    'brutalist' => 'Brutalist Signal',
    'obsidian' => 'Obsidian Focus',
];
?>

<?php if ($appearance_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($appearance_error) ?></div>
<?php endif; ?>
<?php if ($appearance_success): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($appearance_success) ?></div>
<?php endif; ?>

<?php if ($isAdminAppearance): ?>
<div class="surface surface-pad mb-3">
    <div class="section-title"><i class="bi bi-type"></i> Branding</div>
    <p class="fine-print mb-3">
        Shown in the top navbar, browser tab, login page, and public document footer.
        Default is <code><?= htmlspecialchars(TASKS_APP_NAME_DEFAULT) ?></code>.
    </p>
    <form method="post" action="/admin/settings.php?tab=appearance" style="max-width: 520px;" autocomplete="off">
        <?= csrfInputField() ?>
        <input type="hidden" name="settings_action" value="save_branding">
        <div class="mb-3">
            <label class="form-label" for="app_name">Application name</label>
            <input type="text" class="form-control" id="app_name" name="app_name"
                   value="<?= htmlspecialchars($appNameSetting) ?>"
                   maxlength="100" required>
        </div>
        <button type="submit" class="btn btn-primary">Save branding</button>
    </form>
</div>
<?php endif; ?>

<div class="surface surface-pad mb-3">
    <div class="section-title"><i class="bi bi-layout-wtf"></i> Home widgets</div>
    <p class="fine-print mb-3">
        Choose which blocks load on Home. The cross-project board hydrates every reachable task — leave it off unless you need it.
        Defaults match the Home element comps (Doc #1224).
    </p>
    <form method="post" action="/admin/settings.php?tab=appearance" style="max-width: 520px;">
        <?= csrfInputField() ?>
        <input type="hidden" name="settings_action" value="save_home_widgets">
        <?php foreach ($homeWidgetLabels as $key => $meta): ?>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="home_widget_<?= htmlspecialchars($key) ?>"
                       name="home_widget[<?= htmlspecialchars($key) ?>]" value="1"
                       <?= !empty($homeWidgets[$key]) ? 'checked' : '' ?>>
                <label class="form-check-label" for="home_widget_<?= htmlspecialchars($key) ?>">
                    <strong><?= htmlspecialchars($meta[0]) ?></strong>
                    <span class="fine-print d-block"><?= htmlspecialchars($meta[1]) ?></span>
                </label>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary mt-2">Save home widgets</button>
    </form>
</div>

<div class="surface surface-pad">
    <div class="section-title"><i class="bi bi-palette"></i> Appearance</div>
    <p class="fine-print mb-3">
        Choose how Sanctum Tasks looks for your account. Your organization default is
        <strong><?= htmlspecialchars($skinLabels[$orgDefault] ?? $orgDefault) ?></strong>
        unless you pick a personal override below. Instance master default is
        <strong><?= htmlspecialchars($skinLabels[$masterSkin] ?? $masterSkin) ?></strong>.
    </p>

    <form method="post" action="/admin/settings.php?tab=appearance" style="max-width: 520px;">
        <?= csrfInputField() ?>
        <input type="hidden" name="settings_action" value="save_appearance">

        <div class="mb-3">
            <label class="form-label">Skin preference</label>
            <div class="d-flex flex-column gap-2">
                <label class="form-check">
                    <input class="form-check-input" type="radio" name="skin_choice" value="__org__"
                        <?= $userOverride === null ? 'checked' : '' ?>>
                    <span class="form-check-label">Use organization default (<?= htmlspecialchars($skinLabels[$orgDefault] ?? $orgDefault) ?>)</span>
                </label>
                <?php foreach ($skinSlugs as $slug): ?>
                    <label class="form-check">
                        <input class="form-check-input" type="radio" name="skin_choice" value="<?= htmlspecialchars($slug) ?>"
                            <?= $userOverride === $slug ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= htmlspecialchars($skinLabels[$slug] ?? $slug) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <p class="fine-print mb-3">Currently active: <strong><?= htmlspecialchars($skinLabels[$effective] ?? $effective) ?></strong></p>

        <button type="submit" class="btn btn-primary">Save appearance</button>
    </form>
</div>

<?php if ($isAdminAppearance): ?>
<div class="surface surface-pad mt-3">
    <div class="section-title"><i class="bi bi-sliders"></i> Master theme (admin)</div>
    <p class="fine-print mb-3">
        Sets the instance-wide fallback theme used when an organization and user do not set one.
    </p>
    <form method="post" action="/admin/settings.php?tab=appearance" style="max-width: 520px;">
        <?= csrfInputField() ?>
        <input type="hidden" name="settings_action" value="save_master_skin">
        <div class="mb-3">
            <label class="form-label">Master theme</label>
            <select class="form-select" name="master_skin_slug">
                <?php foreach ($skinSlugs as $slug): ?>
                    <option value="<?= htmlspecialchars($slug) ?>" <?= $masterSkin === $slug ? 'selected' : '' ?>>
                        <?= htmlspecialchars($skinLabels[$slug] ?? $slug) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-outline-primary">Save master theme</button>
    </form>
</div>
<?php endif; ?>
