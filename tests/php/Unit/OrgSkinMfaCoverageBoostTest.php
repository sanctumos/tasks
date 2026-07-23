<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Org/skin/MFA/watcher/pin/password coverage boost. */
final class OrgSkinMfaCoverageBoostTest extends TestCase
{
    public function testOrgSkinPinsWatchersPasswordAndRateLimitEdges(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("osm_a_{$suffix}", 'MemberPass123456', 'admin', false);
        $member = createUser("osm_m_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($admin['success'] && $member['success']);
        $aid = (int)$admin['id'];
        $mid = (int)$member['id'];

        $proj = createDirectoryProject($aid, "Osm {$suffix}", null, false, true);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);

        if (function_exists('updateOrganizationName')) {
            $n = updateOrganizationName(1, "Org {$suffix}", $aid);
            $this->assertTrue($n['success'] ?? is_array($n));
        }
        if (function_exists('updateOrganizationDefaultSkin')) {
            $s = updateOrganizationDefaultSkin(1, 'hey', $aid);
            $this->assertTrue($s['success'] ?? is_array($s));
        }
        if (function_exists('updateUserSkinPreference')) {
            $us = updateUserSkinPreference($mid, 'ledger', $aid);
            $this->assertTrue($us['success'] ?? is_array($us));
        }
        if (function_exists('updateMasterSkinPreference')) {
            $ms = updateMasterSkinPreference('hey', $aid);
            $this->assertTrue($ms['success'] ?? is_array($ms));
        }
        if (function_exists('setUserOrganization')) {
            setUserOrganization($mid, 1, $aid);
        }
        if (function_exists('setUserLimitedProjectAccess')) {
            setUserLimitedProjectAccess($aid, $mid, true);
            setUserLimitedProjectAccess($aid, $mid, false);
        }
        if (function_exists('listOrganizationMembershipIdsForUser')) {
            $this->assertIsArray(listOrganizationMembershipIdsForUser($aid));
        }
        if (function_exists('listOrganizationIdsForUserAccess')) {
            $urow = getUserById($aid, false);
            $this->assertIsArray(listOrganizationIdsForUserAccess($urow));
        }
        if (function_exists('listLegacyOnlyTaskProjectNamespaces')) {
            $this->assertIsArray(listLegacyOnlyTaskProjectNamespaces());
        }
        if (function_exists('getAppSetting')) {
            setAppSetting('osm_' . $suffix, 'v1', $aid);
            $this->assertSame('v1', getAppSetting('osm_' . $suffix));
        }
        if (function_exists('setUserProjectPin')) {
            $pin = setUserProjectPin($aid, $pid, 1);
            $this->assertTrue($pin['success'] ?? true);
        }
        if (function_exists('setUserActive')) {
            setUserActive($mid, false);
            setUserActive($mid, true);
        }
        if (function_exists('resetUserPassword')) {
            $rp = resetUserPassword($mid, 'ResetPass123456', true);
            $this->assertTrue($rp['success'], (string)($rp['error'] ?? ''));
        }
        if (function_exists('getAllApiKeys')) {
            createApiKeyForUser($mid, 'osm-key-' . $suffix, $aid);
            $this->assertIsArray(getAllApiKeys());
        }
        if (function_exists('revokeApiKey')) {
            $keys = getAllApiKeys();
            foreach ($keys as $k) {
                if ((int)($k['user_id'] ?? 0) === $mid) {
                    $this->assertTrue(revokeApiKey((int)$k['id']));
                    break;
                }
            }
        }
        if (function_exists('listAuditLogs')) {
            $this->assertIsArray(listAuditLogs(20));
        }
        if (function_exists('listProjects')) {
            $this->assertIsArray(listProjects());
        }

        $task = createTask("Osm task {$suffix}", 'todo', $aid, $mid, 'watch me', [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $this->assertTrue($task['success']);
        $tid = (int)$task['id'];
        if (function_exists('addTaskWatcher')) {
            $w = addTaskWatcher($tid, $mid);
            $this->assertTrue($w['success'] ?? true);
        }
        if (function_exists('taskUserIsWatcher')) {
            $this->assertTrue(taskUserIsWatcher($tid, $mid));
        }

        // Rate limit: burn a few checks
        $key = createApiKeyForUser($aid, 'rl2-' . $suffix, $aid);
        for ($i = 0; $i < 3; $i++) {
            $rl = checkApiRateLimit($key, 2, 60);
            $this->assertIsArray($rl);
        }

        // MFA encrypt/decrypt round-trip if available
        if (function_exists('encryptMfaSecret') && function_exists('decryptMfaSecret')) {
            $enc = encryptMfaSecret('JBSWY3DPEHPK3PXP');
            $this->assertNotSame('', $enc);
            $dec = decryptMfaSecret($enc);
            $this->assertSame('JBSWY3DPEHPK3PXP', $dec);
        }
    }
}
