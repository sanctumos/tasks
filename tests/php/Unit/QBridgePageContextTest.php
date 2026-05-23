<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/public/q-bridge/includes/page_context.php';

final class QBridgePageContextTest extends TestCase
{
    public function testFormatChatContextBlockIncludesProjectAndTask(): void
    {
        $block = q_bridge_format_chat_context_block([
            'surface' => 'task',
            'admin_origin' => 'https://tasks.example.com',
            'project_id' => 10,
            'project_name' => 'Sanctum Tasks — platform upgrade',
            'task_id' => 313,
            'task_title' => 'Program epic',
            'task_status' => 'doing',
            'task_link' => 'https://tasks.example.com/admin/view.php?id=313',
            'url' => '/admin/view.php?id=313',
            'page_link' => 'https://tasks.example.com/admin/view.php?id=313',
        ]);
        $this->assertStringContainsString('[Chat context — Sanctum Tasks UI]', $block);
        $this->assertStringContainsString('get-task', $block);
        $this->assertStringContainsString('task_id=313', $block);
        $this->assertStringContainsString('project_id=10', $block);
        $this->assertStringContainsString('Program epic', $block);
        $this->assertStringContainsString('Task link:', $block);
        $this->assertStringContainsString('IDs and titles only', $block);
    }

    public function testFormatChatContextBlockDocumentWithToolHint(): void
    {
        $block = q_bridge_format_chat_context_block([
            'surface' => 'document',
            'document_id' => 298,
            'document_title' => 'Meeting transcript',
            'document_link' => 'https://tasks.example.com/admin/doc.php?id=298',
            'project_id' => 7,
            'project_name' => 'BlackCert: AuthLokr',
        ]);
        $this->assertStringContainsString('document_id=298', $block);
        $this->assertStringContainsString('get-document', $block);
        $this->assertStringContainsString('Document link:', $block);
    }

    public function testDetectDocumentPhpAlias(): void
    {
        $prev = $_SERVER['REQUEST_URI'] ?? '';
        $_SERVER['REQUEST_URI'] = '/admin/document.php?id=302';
        try {
            $ctx = q_bridge_detect_admin_page_context();
            $this->assertSame('document', $ctx['surface']);
            $this->assertSame(302, $ctx['document_id']);
        } finally {
            $_SERVER['REQUEST_URI'] = $prev;
        }
    }

    public function testAttachContextLinks(): void
    {
        $ctx = q_bridge_attach_context_links([
            'admin_origin' => 'https://tasks.example.com',
            'document_id' => 5,
        ]);
        $this->assertSame('https://tasks.example.com/admin/doc.php?id=5', $ctx['document_link']);
    }

    public function testNormalizeStripsUnknownKeys(): void
    {
        $n = q_bridge_normalize_page_context([
            'surface' => 'project',
            'project_id' => 5,
            'evil' => '<script>',
        ]);
        $this->assertSame('project', $n['surface']);
        $this->assertSame(5, $n['project_id']);
        $this->assertArrayNotHasKey('evil', $n);
    }

    public function testUrlOverridesStaleProjectId(): void
    {
        $n = q_bridge_normalize_page_context([
            'surface' => 'project',
            'project_id' => 1,
            'url' => '/admin/project.php?id=7&tab=docs',
        ]);
        $this->assertSame(7, $n['project_id']);
        $this->assertSame('project', $n['surface']);
    }

    public function testIndexProjectFilterDetectsProjectId(): void
    {
        $prev = $_SERVER['REQUEST_URI'] ?? '';
        $_SERVER['REQUEST_URI'] = '/admin/index.php?project_id=7';
        try {
            $ctx = q_bridge_detect_admin_page_context();
            $this->assertSame('project', $ctx['surface']);
            $this->assertSame(7, $ctx['project_id']);
        } finally {
            $_SERVER['REQUEST_URI'] = $prev;
        }
    }
}
