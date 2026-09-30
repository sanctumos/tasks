<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HomeBoardHealthAndScheduleTest extends TestCase
{
    /** @return array{0:int,1:array<string,mixed>} admin user id + row in an isolated org */
    private function isolatedAdmin(string $prefix): array
    {
        $suffix = bin2hex(random_bytes(3));
        $bootstrap = createUser("{$prefix}_boot_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($bootstrap['success']);
        $bootId = (int)$bootstrap['id'];
        $org = createOrganization("{$prefix} Org {$suffix}", $bootId);
        $this->assertTrue($org['success'], (string)($org['error'] ?? ''));
        $oid = (int)$org['id'];
        $admin = createUser("{$prefix}_{$suffix}", 'MemberPass123456', 'admin', false, $oid);
        $this->assertTrue($admin['success']);
        $aid = (int)$admin['id'];
        return [$aid, getUserById($aid)];
    }

    public function testBoardHealthCardsSurfaceBlockedAndCap(): void
    {
        [$aid, $viewer] = $this->isolatedAdmin('hbh');
        $suffix = bin2hex(random_bytes(2));

        $hot = createDirectoryProject($aid, "Hot {$suffix}", null, false, false);
        $quiet = createDirectoryProject($aid, "Quiet {$suffix}", null, false, false);
        $this->assertTrue($hot['success'] && $quiet['success']);
        $hotId = (int)$hot['id'];
        $quietId = (int)$quiet['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $hotList = getFirstTodoListIdForProject(getDbConnection(), $hotId);
        $quietList = getFirstTodoListIdForProject(getDbConnection(), $quietId);

        createTask("blocked {$suffix}", 'todo', $aid, $aid, null, [
            'project_id' => $hotId,
            'list_id' => $hotList,
            'tags' => ['blocked'],
        ]);
        createTask("plain {$suffix}", 'doing', $aid, $aid, null, [
            'project_id' => $quietId,
            'list_id' => $quietList,
        ]);

        $cards = computeHomeBoardHealthCards($viewer, 8);
        $this->assertNotEmpty($cards);
        $byId = [];
        foreach ($cards as $c) {
            $byId[(int)$c['project_id']] = $c;
        }
        $this->assertArrayHasKey($hotId, $byId);
        $this->assertSame('needs_eyes', $byId[$hotId]['state']);
        $this->assertSame(1, (int)$byId[$hotId]['blocked']);
        $this->assertArrayHasKey($quietId, $byId);

        $capped = computeHomeBoardHealthCards($viewer, 1);
        $this->assertCount(1, $capped);
    }

    public function testSchedulePeekEntriesRespectMineScope(): void
    {
        [$aid, $viewer] = $this->isolatedAdmin('hsp');
        $suffix = bin2hex(random_bytes(2));
        $proj = createDirectoryProject($aid, "Sched {$suffix}", null, false, false);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);

        $due = gmdate('Y-m-d H:i:s', time() + 86400);
        createTask("due soon {$suffix}", 'todo', $aid, $aid, null, [
            'project_id' => $pid,
            'list_id' => $lid,
            'due_at' => $due,
        ]);

        $sched = listScheduleForViewer($viewer, [
            'scope' => 'mine',
            'include_overdue' => false,
            'include_done' => false,
            'limit' => 5,
        ]);
        $this->assertGreaterThanOrEqual(1, (int)$sched['count']);
        $titles = array_map(static fn(array $e): string => (string)$e['title'], $sched['entries']);
        $this->assertTrue(
            (bool)array_filter($titles, static fn(string $t): bool => str_contains($t, "due soon {$suffix}"))
        );
    }

    public function testBoardHealthPrefersPinnedAndQuietVsMoving(): void
    {
        [$aid, $viewer] = $this->isolatedAdmin('hbp');
        $suffix = bin2hex(random_bytes(2));

        $a = createDirectoryProject($aid, "AAA Pin {$suffix}", null, false, false);
        $b = createDirectoryProject($aid, "BBB Other {$suffix}", null, false, false);
        $this->assertTrue($a['success'] && $b['success']);
        $aidPid = (int)$a['id'];
        $bid = (int)$b['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $listB = getFirstTodoListIdForProject(getDbConnection(), $bid);
        createTask("doing {$suffix}", 'doing', $aid, $aid, null, [
            'project_id' => $bid,
            'list_id' => $listB,
        ]);

        setUserProjectPin($aid, $aidPid, 0);
        $cards = computeHomeBoardHealthCards($viewer, 8);
        $this->assertGreaterThanOrEqual(2, count($cards));
        $this->assertSame($aidPid, (int)$cards[0]['project_id'], 'pinned board should sort first');

        $byId = [];
        foreach ($cards as $c) {
            $byId[(int)$c['project_id']] = $c;
        }
        $this->assertSame('moving', $byId[$bid]['state']);
        $this->assertContains($byId[$aidPid]['state'], ['quiet', 'moving', 'needs_eyes']);
        $this->assertIsString($byId[$aidPid]['message']);
    }

    public function testBoardHealthEmptyQuietAndStaleBranches(): void
    {
        $this->assertSame(0, st_days_since_utc(gmdate('Y-m-d H:i:s', time() + 3600)));

        $suffix = bin2hex(random_bytes(2));
        $boot = createUser("hbe_b_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($boot['success']);
        $org = createOrganization("HBE Org {$suffix}", (int)$boot['id']);
        $this->assertTrue($org['success']);
        $oid = (int)$org['id'];
        $member = createUser("hbe_m_{$suffix}", 'MemberPass123456', 'member', false, $oid);
        $this->assertTrue($member['success']);
        $mid = (int)$member['id'];
        $this->assertSame([], computeHomeBoardHealthCards(getUserById($mid), 8));

        $admin = createUser("hbe_a_{$suffix}", 'MemberPass123456', 'admin', false, $oid);
        $this->assertTrue($admin['success']);
        $aid = (int)$admin['id'];
        // Use a limited member as viewer so board-health cards are not flooded by all-org admin visibility.
        $lead = createUser("hbe_l_{$suffix}", 'MemberPass123456', 'member', false, $oid, 'team_member', true);
        $this->assertTrue($lead['success']);
        $lidUser = (int)$lead['id'];
        $viewer = getUserById($lidUser);

        $p1 = createDirectoryProject($aid, "QuietOld {$suffix}", null, false, false);
        $p2 = createDirectoryProject($aid, "StaleOpen {$suffix}", null, false, false);
        $p3 = createDirectoryProject($aid, "PinTwo {$suffix}", null, false, false);
        $this->assertTrue($p1['success'] && $p2['success'] && $p3['success']);
        $id1 = (int)$p1['id'];
        $id2 = (int)$p2['id'];
        $id3 = (int)$p3['id'];
        foreach ([$id1, $id2, $id3] as $pid) {
            $this->assertTrue(addProjectMember($aid, $pid, $lidUser, 'lead')['success'] ?? false);
        }
        applySanctumSchemaMigrations(getDbConnection());
        $list2 = getFirstTodoListIdForProject(getDbConnection(), $id2);
        createTask("old todo {$suffix}", 'todo', $aid, $lidUser, null, [
            'project_id' => $id2,
            'list_id' => $list2,
        ]);

        $db = getDbConnection();
        $old5 = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
        $old20 = gmdate('Y-m-d H:i:s', time() - 20 * 86400);
        $old1 = gmdate('Y-m-d H:i:s', time() - 86400);
        $db->exec("UPDATE projects SET updated_at = '" . $db->escapeString($old5) . "' WHERE id = {$id1}");
        $db->exec("UPDATE projects SET updated_at = '" . $db->escapeString($old20) . "' WHERE id = {$id2}");
        $db->exec("UPDATE projects SET updated_at = '" . $db->escapeString($old1) . "' WHERE id = {$id3}");

        setUserProjectPin($lidUser, $id3, 0);
        setUserProjectPin($lidUser, $id1, 1);

        $cards = computeHomeBoardHealthCards($viewer, 8);
        $byId = [];
        foreach ($cards as $c) {
            $byId[(int)$c['project_id']] = $c;
        }
        $this->assertArrayHasKey($id1, $byId);
        $this->assertArrayHasKey($id2, $byId);
        $this->assertArrayHasKey($id3, $byId);
        $this->assertSame('quiet', $byId[$id1]['state']);
        $this->assertSame('needs_eyes', $byId[$id2]['state']);
        $this->assertStringContainsString('yesterday', $byId[$id3]['message']);
        $this->assertSame($id3, (int)$cards[0]['project_id']);
        $this->assertSame($id1, (int)$cards[1]['project_id']);
    }
}
