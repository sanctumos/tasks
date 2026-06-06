<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QAclFixturesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/config.php';
        require_once dirname(__DIR__, 3) . '/public/includes/functions.php';
        require_once dirname(__DIR__, 3) . '/tools/e2e/q_acl_fixture_lib.php';
        initializeDatabase();
        applySanctumSchemaMigrations(getDbConnection());
    }

    public function test_bootstrap_is_idempotent(): void
    {
        $r1 = bootstrapQAclE2eFixtures();
        $r2 = bootstrapQAclE2eFixtures();
        $this->assertTrue($r1['success']);
        $this->assertTrue($r2['success']);

        $m1 = $r1['manifest'];
        $m2 = $r2['manifest'];
        $this->assertSame($m1['users']['member']['id'], $m2['users']['member']['id']);
        $this->assertSame($m1['users']['client']['id'], $m2['users']['client']['id']);
        $this->assertSame($m1['projects']['member_visible']['id'], $m2['projects']['member_visible']['id']);
        $this->assertSame($m1['projects']['admin_only']['id'], $m2['projects']['admin_only']['id']);
        $this->assertSame($m1['tasks']['member_visible_marker']['id'], $m2['tasks']['member_visible_marker']['id']);
        $this->assertSame($m1['tasks']['admin_only_marker']['id'], $m2['tasks']['admin_only_marker']['id']);
    }

    public function test_acl_matrix_matches_fixture_intent(): void
    {
        $result = bootstrapQAclE2eFixtures();
        $this->assertTrue($result['success']);
        $m = $result['manifest'];

        $member = getUserById((int)$m['users']['member']['id'], false);
        $client = getUserById((int)$m['users']['client']['id'], false);
        $admin = getUserById((int)$m['admin']['id'], false);
        $this->assertIsArray($member);
        $this->assertIsArray($client);
        $this->assertIsArray($admin);

        $visible = getDirectoryProjectById((int)$m['projects']['member_visible']['id']);
        $adminOnly = getDirectoryProjectById((int)$m['projects']['admin_only']['id']);
        $this->assertIsArray($visible);
        $this->assertIsArray($adminOnly);

        $this->assertTrue(userCanAccessDirectoryProject($member, $visible));
        $this->assertFalse(userCanAccessDirectoryProject($member, $adminOnly));
        $this->assertTrue(userCanAccessDirectoryProject($client, $visible));
        $this->assertFalse(userCanAccessDirectoryProject($client, $adminOnly));
        $this->assertTrue(userCanAccessDirectoryProject($admin, $visible));
        $this->assertTrue(userCanAccessDirectoryProject($admin, $adminOnly));

        $memberKey = getQBridgeDefaultApiKeyPlaintextForUser((int)$member['id']);
        $clientKey = getQBridgeDefaultApiKeyPlaintextForUser((int)$client['id']);
        $this->assertNotNull($memberKey);
        $this->assertNotNull($clientKey);
        $this->assertStringStartsWith('stq_', $memberKey);
        $this->assertStringStartsWith('stq_', $clientKey);
    }
}
