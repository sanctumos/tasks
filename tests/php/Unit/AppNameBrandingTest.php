<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AppNameBrandingTest extends TestCase
{
    public function testGetAppNameFallsBackToDefault(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("brand_a_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $aid = (int)$admin['id'];

        setAppSetting('app_name', null, $aid);
        $this->assertSame(TASKS_APP_NAME_DEFAULT, getAppName());
    }

    public function testUpdateAppNameSettingValidatesAndPersists(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("brand_b_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $aid = (int)$admin['id'];

        $bad = updateAppNameSetting('', $aid);
        $this->assertFalse($bad['success']);
        $this->assertSame('Application name is required.', $bad['error']);

        $html = updateAppNameSetting('<b>Acme</b>', $aid);
        $this->assertFalse($html['success']);

        $long = updateAppNameSetting(str_repeat('x', 101), $aid);
        $this->assertFalse($long['success']);

        $ok = updateAppNameSetting('Acme Tasks', $aid);
        $this->assertTrue($ok['success']);
        $this->assertSame('Acme Tasks', getAppName());
        $this->assertSame('Acme Tasks', getAppSetting('app_name'));
    }
}
