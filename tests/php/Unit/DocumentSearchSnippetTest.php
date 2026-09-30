<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DocumentSearchSnippetTest extends TestCase
{
    public function testHighlightSearchMatchWrapsFirstHit(): void
    {
        $html = highlightSearchMatch('Hello Retainer World', 'retainer');
        $this->assertStringContainsString('<mark class="st-hl">Retainer</mark>', $html);
        $this->assertStringContainsString('Hello ', $html);
        $this->assertStringContainsString(' World', $html);
    }

    public function testDocumentSearchSnippetCentersOnMatch(): void
    {
        $body = str_repeat('word ', 40) . 'uniquezebra token ' . str_repeat('tail ', 40);
        $snip = documentSearchSnippet($body, 'uniquezebra', 80);
        $this->assertStringContainsString('<mark class="st-hl">uniquezebra</mark>', $snip);
        $this->assertStringContainsString('…', $snip);
    }

    public function testDocumentSearchSnippetEmptyBody(): void
    {
        $this->assertSame('', documentSearchSnippet(null, 'x'));
        $this->assertSame('', documentSearchSnippet('   ', 'x'));
    }
}
