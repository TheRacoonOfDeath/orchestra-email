<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\Attachment\AttachmentService;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase
{
    public function testMakesFileNamesUnique(): void
    {
        $this->assertSame(
            ['Notes.pdf', 'Notes (2).pdf', 'notes (3).PDF', 'Score', 'Score (2)'],
            AttachmentService::uniqueNames(['Notes.pdf', 'Notes.pdf', 'notes.PDF', 'Score', 'Score'])
        );
    }

    public function testReadsTheChosenFiles(): void
    {
        $files = ['attachments' => [
            'name' => ['a.pdf', '', 'b.png'],
            'type' => ['application/pdf', '', 'image/png'],
            'tmp_name' => ['/tmp/a', '', '/tmp/b'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE],
            'size' => [10, 0, 0],
        ]];

        $this->assertSame([
            ['name' => 'a.pdf', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 10],
            ['name' => 'b.png', 'tmp_name' => '/tmp/b', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
        ], AttachmentService::uploads($files));
        $this->assertSame([], AttachmentService::uploads([]));
    }
}
