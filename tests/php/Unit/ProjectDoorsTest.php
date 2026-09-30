<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectDoorsTest extends TestCase
{
    private static int $adminId = 0;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/config.php';
        require_once dirname(__DIR__, 3) . '/public/includes/functions.php';
        require_once dirname(__DIR__, 3) . '/tools/e2e/q_acl_fixture_lib.php';
        initializeDatabase();
        applySanctumSchemaMigrations(getDbConnection());
        $boot = bootstrapQAclE2eFixtures();
        self::$adminId = (int)$boot['manifest']['admin']['id'];
    }

    public function test_normalize_url_rejects_unsafe_schemes(): void
    {
        $bad = normalizeProjectDoorUrl('javascript:alert(1)');
        $this->assertFalse($bad['success']);
        $emptyHost = normalizeProjectDoorUrl('http:///no-host');
        $this->assertFalse($emptyHost['success']);
        $this->assertSame('Invalid URL', $emptyHost['error'] ?? null);
        $creds = normalizeProjectDoorUrl('https://user:pass@example.com/x');
        $this->assertFalse($creds['success']);
        $this->assertStringContainsString('credentials', (string)($creds['error'] ?? ''));
        $good = normalizeProjectDoorUrl('https://figma.com/file/abc');
        $this->assertTrue($good['success']);
        $this->assertSame('https://figma.com/file/abc', $good['url']);
    }

    public function test_lead_can_create_and_member_can_read(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $admin = getUserById(self::$adminId, false);
        $member = getUserById((int)$m['users']['member']['id'], false);
        $projectId = (int)$m['projects']['member_visible']['id'];

        $created = createProjectDoor(self::$adminId, $projectId, [
            'title' => 'Figma',
            'url' => 'https://www.figma.com/file/test-door',
            'description' => 'Design board',
        ]);
        $this->assertTrue($created['success'], $created['error'] ?? '');

        $adminDoors = listProjectDoorsForProject($admin, $projectId);
        $memberDoors = listProjectDoorsForProject($member, $projectId);
        $this->assertNotEmpty($adminDoors);
        $this->assertSame(count($adminDoors), count($memberDoors));
        $this->assertSame('Figma', $adminDoors[0]['title']);
    }

    public function test_member_cannot_create_door(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $member = getUserById((int)$m['users']['member']['id'], false);
        $projectId = (int)$m['projects']['member_visible']['id'];

        $denied = createProjectDoor((int)$member['id'], $projectId, [
            'title' => 'Blocked',
            'url' => 'https://example.com/blocked',
        ]);
        $this->assertFalse($denied['success']);
    }

    public function test_client_can_read_but_not_manage_on_visible_project(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $client = getUserById((int)$m['users']['client']['id'], false);
        $projectId = (int)$m['projects']['member_visible']['id'];

        createProjectDoor(self::$adminId, $projectId, [
            'title' => 'Drive',
            'url' => 'https://drive.google.com/drive/folders/abc',
        ]);

        $doors = listProjectDoorsForProject($client, $projectId);
        $this->assertNotEmpty($doors);

        $denied = createProjectDoor((int)$client['id'], $projectId, [
            'title' => 'Nope',
            'url' => 'https://example.com/nope',
        ]);
        $this->assertFalse($denied['success']);
    }

    public function test_update_door_rejects_overlong_fields(): void
    {
        $boot = bootstrapQAclE2eFixtures();
        $m = $boot['manifest'];
        $projectId = (int)$m['projects']['member_visible']['id'];
        $created = createProjectDoor(self::$adminId, $projectId, [
            'title' => 'Resize me',
            'url' => 'https://example.com/door-edge',
        ]);
        $this->assertTrue($created['success'], $created['error'] ?? '');
        $doorId = (int)($created['id'] ?? $created['door']['id'] ?? 0);
        if ($doorId <= 0) {
            $listed = listProjectDoorsForProject(getUserById(self::$adminId, false), $projectId);
            foreach ($listed as $d) {
                if (($d['title'] ?? '') === 'Resize me') {
                    $doorId = (int)$d['id'];
                    break;
                }
            }
        }
        $this->assertGreaterThan(0, $doorId);

        $longTitle = updateProjectDoor(self::$adminId, $doorId, [
            'title' => str_repeat('T', 201),
        ]);
        $this->assertFalse($longTitle['success']);
        $this->assertStringContainsString('200', (string)($longTitle['error'] ?? ''));

        $longDesc = updateProjectDoor(self::$adminId, $doorId, [
            'description' => str_repeat('D', 501),
        ]);
        $this->assertFalse($longDesc['success']);
        $this->assertStringContainsString('500', (string)($longDesc['error'] ?? ''));

        $missing = updateProjectDoor(self::$adminId, 99999999, ['title' => 'x']);
        $this->assertFalse($missing['success']);

        $emptyTitle = updateProjectDoor(self::$adminId, $doorId, ['title' => '   ']);
        $this->assertFalse($emptyTitle['success']);
        $this->assertStringContainsString('Title', (string)($emptyTitle['error'] ?? ''));

        $delMissing = deleteProjectDoor(self::$adminId, 99999999);
        $this->assertFalse($delMissing['success']);
    }
}
