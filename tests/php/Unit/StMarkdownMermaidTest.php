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

    public function testBareFenceStaysPreWhenMermaidAlsoPresent(): void
    {
        $raw = <<<'MD'
Pseudocode block:

```
FUNCTION score_sender(signals) -> INTEGER
  score <- 0
```

Diagram:

```mermaid
flowchart LR
  A-->B
```
MD;
        $html = st_markdown($raw);
        $this->assertSame(1, substr_count($html, 'st-mermaid-diagram'));
        $this->assertStringContainsString('FUNCTION score_sender', $html);
        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('flowchart LR', $html);
    }

    public function testProseMentioningMermaidDoesNotPromoteBareFences(): void
    {
        $raw = <<<'MD'
Section 8 includes inline mermaid figures below.

```
STATE classification_queue.status:
  pending --> done
```
MD;
        $html = st_markdown($raw);
        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringNotContainsString('st-mermaid-diagram', $html);
    }
}
