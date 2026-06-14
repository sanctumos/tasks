<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SharedDocumentAssetTest extends TestCase
{
    public function testDocumentBodyReferencesAttachmentId(): void
    {
        $this->assertTrue(documentBodyReferencesAttachmentId(
            "![shot](/api/get-asset.php?id=42)",
            42
        ));
        $this->assertTrue(documentBodyReferencesAttachmentId(
            'https://tasks.example.com/api/get-asset.php?id=42&foo=1',
            42
        ));
        $this->assertFalse(documentBodyReferencesAttachmentId(
            '![shot](/api/get-asset.php?id=421)',
            42
        ));
    }

    public function testRewriteSharedDocumentAssetUrls(): void
    {
        $tok = str_repeat('a', 64);
        $html = '<img src="/api/get-asset.php?id=7" alt="x">';
        $out = rewriteSharedDocumentAssetUrls($html, $tok);
        $this->assertStringContainsString('document_share_token=' . $tok, $out);
        $this->assertStringContainsString('/api/get-asset.php?id=7', $out);
    }
}
