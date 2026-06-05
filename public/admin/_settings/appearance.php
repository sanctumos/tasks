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

$skinLabels = [
    'hey' => 'HEY Bold',
    'ledger' => 'Ledger & Ink',
    'brutalist' => 'Brutalist Signal',
    'obsidian' => 'Obsidian Focus',
];
?>

<div class="surface surface-pad">
    <div class="section-title"><i class="bi bi-palette"></i> Appearance</div>
    <p class="fine-print mb-3">
        Choose how Sanctum Tasks looks for your account. Your organization default is
        <strong><?= htmlspecialchars($skinLabels[$orgDefault] ?? $orgDefault) ?></strong>
        unless you pick a personal override below.
    </p>

    <?php if ($appearance_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($appearance_error) ?></div>
    <?php endif; ?>
    <?php if ($appearance_success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($appearance_success) ?></div>
    <?php endif; ?>

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
