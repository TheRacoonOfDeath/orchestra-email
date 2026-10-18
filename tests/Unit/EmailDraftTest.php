<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\EmailDraft;
use PHPUnit\Framework\TestCase;

final class EmailDraftTest extends TestCase
{
    public function testReadsTheForm(): void
    {
        $draft = EmailDraft::fromForm([
            'groups' => ['orchestra_members_all', 'orchestra_members_all', ['nested'], ''],
            'subject' => " Rehearsal\r\nBcc: someone@example.com ",
            'body' => '<p>Hello <b>all</b>,</p><script>alert(1)</script>',
            'attachment_ids' => ['3', 3, 'x', '-1', '7'],
        ]);

        $this->assertSame(['orchestra_members_all'], $draft->groupIds);
        $this->assertSame('Rehearsal Bcc: someone@example.com', $draft->subject, 'no header injection');
        $this->assertSame('<p>Hello <b>all</b>,</p>alert(1)', $draft->body);
        $this->assertSame([3, 7], $draft->attachmentIds);
        $this->assertSame([], $draft->textErrors());
    }

    public function testRequiresSubjectAndMessage(): void
    {
        $this->assertCount(2, EmailDraft::fromForm([])->textErrors());
        $this->assertCount(1, EmailDraft::fromForm(['subject' => 'Hi', 'body' => '<p>&nbsp;</p>'])->textErrors());
    }

    public function testKeepsTheLineBreaksOfPlainText(): void
    {
        $draft = EmailDraft::fromForm(['body' => "I <3 you all,\r\nsee you!"]);

        $this->assertSame('<p>I &lt;3 you all,<br>' . "\n" . 'see you!</p>', $draft->body);
    }

    public function testLimitsTheLength(): void
    {
        $subject = \str_repeat('a', EmailDraft::SUBJECT_MAX_LENGTH + 1);
        $draft = new EmailDraft([], $subject, \str_repeat('b', EmailDraft::BODY_MAX_LENGTH + 1));

        $this->assertCount(2, $draft->textErrors());
    }
}
