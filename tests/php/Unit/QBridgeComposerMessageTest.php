<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QBridgeComposerMessageTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/public/q-bridge/config/settings.php';
        require_once dirname(__DIR__, 3) . '/public/q-bridge/includes/api_response.php';
        require_once dirname(__DIR__, 3) . '/public/q-bridge/includes/composer_message.php';
    }

    public function testPlainMessagePassthrough(): void
    {
        $out = q_bridge_normalize_composer_payload([
            'message' => 'Hello Q',
        ]);
        $this->assertSame('Hello Q', $out['message']);
        $this->assertSame('', $out['caption']);
        $this->assertSame([], $out['attachments']);
        $this->assertSame([], $out['metadata']);
    }

    public function testCaptionPlusAttachmentAssemblesMessage(): void
    {
        $out = q_bridge_normalize_composer_payload([
            'caption' => 'Summarize this log',
            'attachments' => [[
                'kind' => 'text',
                'filename' => 'error.log',
                'text' => "line one\nline two",
            ]],
        ]);
        $this->assertStringContainsString('Summarize this log', $out['message']);
        $this->assertStringContainsString('[Attached text 1: error.log', $out['message']);
        $this->assertStringContainsString('line two', $out['message']);
        $this->assertSame('Summarize this log', $out['caption']);
        $this->assertCount(1, $out['attachments']);
        $this->assertSame('error.log', $out['metadata']['attachments'][0]['filename']);
        $this->assertArrayNotHasKey('text', $out['metadata']['attachments'][0]);
    }

    public function testRejectsOversizedAttachment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        q_bridge_normalize_composer_payload([
            'attachments' => [[
                'kind' => 'text',
                'text' => str_repeat('x', Q_BRIDGE_MAX_TEXT_ATTACHMENT_BYTES + 1),
            ]],
        ]);
    }

    public function testDisplayPayloadFromMetadata(): void
    {
        $display = q_bridge_display_payload_from_metadata([
            'caption' => 'See attached',
            'attachments' => [[
                'id' => 'att-1',
                'kind' => 'text',
                'filename' => 'paste.txt',
                'size_bytes' => 42,
            ]],
        ]);
        $this->assertIsArray($display);
        $this->assertSame('See attached', $display['caption']);
        $this->assertCount(1, $display['attachments']);
    }

    public function testSanitizeStripsNullBytes(): void
    {
        $this->assertSame('ab', sanitize_chat_message_body("a\0b"));
    }
}
