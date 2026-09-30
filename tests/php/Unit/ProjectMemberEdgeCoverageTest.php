<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Coverage for sole-lead removal guard on project membership.
 */
final class ProjectMemberEdgeCoverageTest extends TestCase
{
    public function testCannotRemoveSoleLeadMember(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = createUser("pm_admin_{$suffix}", 'AdminPass123456', 'admin', false);
        $this->assertTrue($admin['success'], (string)($admin['error'] ?? 'admin'));
        $aid = (int)$admin['id'];

        $solo = createDirectoryProject($aid, "Sole {$suffix}", null, false, true);
        $this->assertTrue($solo['success'], (string)($solo['error'] ?? 'solo'));
        $soloPid = (int)$solo['id'];

        $this->assertSame('lead', getProjectMemberRole($aid, $soloPid));

        $block = removeProjectMember($aid, $soloPid, $aid);
        $this->assertFalse($block['success']);
        $this->assertStringContainsString('last member', (string)($block['error'] ?? ''));
        // Still the sole lead after the rejected remove.
        $this->assertSame('lead', getProjectMemberRole($aid, $soloPid));
    }
}
