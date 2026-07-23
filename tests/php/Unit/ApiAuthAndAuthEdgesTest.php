<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Extra api_auth / auth pure-path coverage (no exit wrappers). */
final class ApiAuthAndAuthEdgesTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TASKS_APP_BASE_URL');
        unset($_ENV['TASKS_APP_BASE_URL'], $_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['SERVER_NAME'], $_SERVER['SERVER_ADDR'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_X_FORWARDED_PORT'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI']);
        parent::tearDown();
    }

    public function testApiAuthHelpersAndKeys(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/api_auth.php';

        $this->assertNull(sanitizeUrlHost(''));
        $this->assertNull(sanitizeUrlHost('[bad'));
        $this->assertSame('127.0.0.1', sanitizeUrlHost('127.0.0.1:8080'));
        $this->assertNull(sanitizeUrlHost('host:badport'));
        $this->assertSame('localhost', sanitizeUrlHost('localhost'));
        $this->assertSame('[::1]', sanitizeUrlHost('[::1]'));

        $_SERVER['SERVER_PORT'] = '8080';
        $this->assertSame(8080, normalizedPortFromServer('http'));
        $_SERVER['SERVER_PORT'] = '80';
        $this->assertNull(normalizedPortFromServer('http'));
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertNull(normalizedPortFromServer('https'));
        unset($_SERVER['SERVER_PORT']);
        $this->assertNull(normalizedPortFromServer('http'));

        $this->assertSame('https://a.example', sanitizeTasksEnvAppBaseUrl('https://a.examplehttps://b.example'));
        $this->assertSame('', sanitizeTasksEnvAppBaseUrl(''));

        putenv('TASKS_APP_BASE_URL=ftp://bad');
        $_ENV['TASKS_APP_BASE_URL'] = 'ftp://bad';
        $this->assertNull(configuredAppOrigin());

        putenv('TASKS_APP_BASE_URL=https://tasks.example.com:8443');
        $_ENV['TASKS_APP_BASE_URL'] = 'https://tasks.example.com:8443';
        $this->assertSame('https://tasks.example.com:8443', configuredAppOrigin());

        putenv('TASKS_APP_BASE_URL');
        unset($_ENV['TASKS_APP_BASE_URL']);
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_NAME'] = 'tasks.local';
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertStringContainsString('tasks.local', requestOrigin());
        $this->assertSame('https', requestScheme());

        $_SERVER['HTTP_X_API_KEY'] = '  abc  ';
        $this->assertSame('abc', getApiKeyFromRequest());
        unset($_SERVER['HTTP_X_API_KEY']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-123';
        $this->assertSame('tok-123', getApiKeyFromRequest());
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->assertNull(getApiKeyFromRequest());

        $body = readJsonBody();
        $this->assertIsArray($body);
    }

    public function testAuthUriHelpers(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/auth.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SERVER['REQUEST_URI'] = '/admin/view.php?id=1#frag';
        $this->assertSame('/admin/view.php?id=1', auth_current_request_uri());
        unset($_SERVER['REQUEST_URI']);
        $this->assertSame('/admin/', auth_current_request_uri());

        $this->assertNull(auth_safe_return_path(''));
        $this->assertNull(auth_safe_return_path(str_repeat('x', 3000)));
        $this->assertNull(auth_safe_return_path('//evil.example'));
        $ok = auth_safe_return_path('/admin/index.php?x=1');
        $this->assertNotNull($ok);

        auth_store_intended_url('/admin/settings.php');
        $this->assertSame('/admin/settings.php', auth_resolve_intended_url(null));
        $this->assertSame('/admin/index.php', auth_resolve_intended_url('/admin/index.php'));
        $login = login('no_such_user_xyz', 'bad');
        $this->assertFalse($login['success'] ?? true);
    }
}
