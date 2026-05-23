<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/public/admin/_helpers.php';

final class StMarkdownMermaidTest extends TestCase
{
    public function testMermaidFenceBecomesDiagramDiv(): void
    {
        $raw = "```mermaid\nflowchart LR\n  A-->B\n```";
        $html = st_markdown($raw);
        $this->assertStringContainsString('class="mermaid st-mermaid-diagram"', $html);
        $this->assertStringContainsString('flowchart LR', $html);
        $this->assertStringNotContainsString('<pre>', $html);
    }

    public function testNonMermaidCodeStaysPre(): void
    {
        $raw = "```php\necho 1;\n```";
        $html = st_markdown($raw);
        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringNotContainsString('st-mermaid-diagram', $html);
    }
}
