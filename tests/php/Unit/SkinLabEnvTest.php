<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SQLite3;

require_once dirname(__DIR__, 3) . '/public/includes/skin-lab-env.php';

final class SkinLabEnvTest extends TestCase
{
    public function testNormalizeSlugAcceptsKnownSkinsAndRejectsUnknown(): void
    {
        $this->assertSame('hey', skinLabNormalizeSlug('hey'));
        $this->assertSame('ledger', skinLabNormalizeSlug(' LEDGER '));
        $this->assertSame('obsidian', skinLabNormalizeSlug('obsidian'));
        $this->assertNull(skinLabNormalizeSlug('basecamp'));
        $this->assertNull(skinLabNormalizeSlug(''));
        $this->assertNull(skinLabNormalizeSlug(null));
    }

    public function testDevHostGuard(): void
    {
        $prev = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'dev.tasks.decisionsciencecorp.com';
        $this->assertTrue(isSkinLabDevHost());
        $this->assertFalse(skinLabShouldShowCompBar());
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8765';
        $this->assertFalse(isSkinLabDevHost());
        $this->assertFalse(skinLabShouldShowCompBar());
        if ($prev === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $prev;
        }
    }

    public function testEffectiveSlugResolutionOrder(): void
    {
        $masterSet = updateMasterSkinPreference('obsidian', 1);
        $this->assertTrue($masterSet['success']);
        $this->assertSame('obsidian', skinLabMasterSlug());

        $user = [
            'id' => 1,
            'org_id' => 1,
            'skin_slug' => 'ledger',
        ];

        $this->assertSame('ledger', skinLabEffectiveSlug($user));

        $userNoOverride = [
            'id' => 1,
            'org_id' => 1,
            'skin_slug' => null,
        ];
        $this->assertSame('obsidian', skinLabOrgDefaultSlug($userNoOverride));
        $this->assertSame('obsidian', skinLabEffectiveSlug($userNoOverride));

        $masterReset = updateMasterSkinPreference('hey', 1);
        $this->assertTrue($masterReset['success']);
    }

    public function testUpdateOrganizationDefaultSkinPersistsInSettingsJson(): void
    {
        $db = getDbConnection();
        $this->assertInstanceOf(SQLite3::class, $db);
        $orgId = (int)$db->querySingle('SELECT id FROM organizations ORDER BY id ASC LIMIT 1');
        $this->assertGreaterThan(0, $orgId);

        $set = updateOrganizationDefaultSkin($orgId, 'obsidian', 1);
        $this->assertTrue($set['success'], (string)($set['error'] ?? 'set org skin'));
        $this->assertSame('obsidian', $set['default_skin_slug']);

        $org = getOrganizationById($orgId);
        $this->assertNotNull($org);
        $settings = json_decode((string)($org['settings_json'] ?? ''), true);
        $this->assertIsArray($settings);
        $this->assertSame('obsidian', $settings['default_skin_slug'] ?? null);

        $user = ['id' => 1, 'org_id' => $orgId, 'skin_slug' => null];
        $this->assertSame('obsidian', skinLabEffectiveSlug($user));

        $bad = updateOrganizationDefaultSkin($orgId, 'retro', 1);
        $this->assertFalse($bad['success']);
    }

    public function testUpdateUserSkinPreferenceSetClearAndValidate(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = createUser("skin_{$suffix}", 'SkinPass123456', 'member', false);
        $this->assertTrue($created['success'], (string)($created['error'] ?? 'create user'));
        $uid = (int)$created['id'];

        $set = updateUserSkinPreference($uid, 'brutalist');
        $this->assertTrue($set['success']);
        $this->assertSame('brutalist', $set['skin_slug']);

        $row = getUserById($uid);
        $this->assertNotNull($row);
        $this->assertSame('brutalist', $row['skin_slug'] ?? null);

        $clear = updateUserSkinPreference($uid, '__org__');
        $this->assertTrue($clear['success']);
        $this->assertNull($clear['skin_slug']);

        $row2 = getUserById($uid);
        $this->assertNotNull($row2);
        $this->assertNull($row2['skin_slug'] ?? null);

        $bad = updateUserSkinPreference($uid, 'swiss');
        $this->assertFalse($bad['success']);
    }
}
