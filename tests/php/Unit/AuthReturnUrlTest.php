<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthReturnUrlTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    public function testSafeReturnPathAllowsAdminDoc(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/auth.php';

        $this->assertSame(
            '/admin/doc.php?id=298',
            auth_safe_return_path('/admin/doc.php?id=298')
        );
    }

    public function testSafeReturnPathRejectsExternalAndLoginLoop(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/auth.php';

        $this->assertNull(auth_safe_return_path('https://evil.example/phish'));
        $this->assertNull(auth_safe_return_path('//evil.example/phish'));
        $this->assertNull(auth_safe_return_path('/admin/login.php'));
        $this->assertNull(auth_safe_return_path('/admin/login.php?return=%2Fadmin%2F'));
        $this->assertNull(auth_safe_return_path('/random/path.php'));
    }

    public function testLoginUrlEncodesReturn(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/auth.php';

        $url = auth_login_url('/admin/view.php?id=12');
        $this->assertStringContainsString('/admin/login.php?return=', $url);
        $this->assertStringContainsString('view.php', $url);
    }

    public function testIntendedUrlSessionRoundTrip(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/auth.php';

        auth_store_intended_url('/admin/project.php?id=7&tab=docs');
        $this->assertSame('/admin/project.php?id=7&tab=docs', auth_peek_intended_url());
        $this->assertSame('/admin/project.php?id=7&tab=docs', auth_take_intended_url());
        $this->assertNull(auth_peek_intended_url());
    }
}
