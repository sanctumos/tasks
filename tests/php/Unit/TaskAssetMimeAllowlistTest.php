<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TaskAssetMimeAllowlistTest extends TestCase
{
    public function testPdfAndDocumentsAreAllowed(): void
    {
        $this->assertTrue(isAllowedTaskAssetMimeType('application/pdf'));
        $this->assertTrue(isAllowedTaskAssetMimeType('APPLICATION/PDF'));
        $this->assertTrue(isAllowedTaskAssetMimeType('text/plain'));
        $this->assertTrue(isAllowedTaskAssetMimeType('text/csv'));
        $this->assertTrue(isAllowedTaskAssetMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertTrue(isAllowedTaskAssetMimeType('application/zip'));
        $this->assertFalse(isAllowedTaskAssetMimeType('application/x-msdownload'));
        $this->assertFalse(isAllowedTaskAssetMimeType('application/octet-stream'));
        $this->assertContains('application/pdf', allowedTaskAssetMimeTypes());
    }

    public function testStoragePathUsesPdfExtension(): void
    {
        $rel = buildTaskAssetStorageRelPath(42, 'application/pdf');
        $this->assertStringEndsWith('.pdf', $rel);
        $this->assertStringContainsString('task-42/', $rel);
    }

    public function testMarkdownSnippetIsLinkForPdfAndImageForPng(): void
    {
        $img = taskAttachmentMarkdownSnippet('shot.png', '/api/get-asset.php?id=1', 'image/png');
        $this->assertSame('![shot.png](/api/get-asset.php?id=1)', $img);

        $pdf = taskAttachmentMarkdownSnippet('spec.pdf', '/api/get-asset.php?id=2', 'application/pdf');
        $this->assertSame('[spec.pdf](/api/get-asset.php?id=2)', $pdf);

        $byExt = taskAttachmentMarkdownSnippet('notes.pdf', '/api/get-asset.php?id=3', null);
        $this->assertSame('[notes.pdf](/api/get-asset.php?id=3)', $byExt);
    }

    public function testPersistPdfUploadWritesFile(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $user = createUser("pdf_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($user['success']);
        $uid = (int)$user['id'];
        $proj = createDirectoryProject($uid, "PdfProj {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);
        $task = createTask("Pdf task {$suffix}", 'todo', $uid, null, 'body', [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        $this->assertTrue($task['success']);
        $tid = (int)$task['id'];

        // Minimal PDF bytes
        $pdf = "%PDF-1.1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $tmp = sys_get_temp_dir() . '/tasks-pdf-' . $suffix . '.pdf';
        file_put_contents($tmp, $pdf);
        $persisted = persistTaskAssetUpload($tid, $tmp, 'application/pdf');
        $this->assertTrue($persisted['success'], (string)($persisted['error'] ?? ''));
        $rel = (string)$persisted['storage_rel_path'];
        $this->assertStringEndsWith('.pdf', $rel);
        $abs = taskAttachmentAbsolutePath($rel);
        $this->assertNotNull($abs);
        $this->assertFileExists($abs);
        @unlink($abs);
    }
}
