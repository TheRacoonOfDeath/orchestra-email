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
            'body' => "Hello <b>all</b>,\r\nsee you!\n",
        ]);

        $this->assertSame(['orchestra_members_all'], $draft->groupIds);
        $this->assertSame('Rehearsal Bcc: someone@example.com', $draft->subject, 'no header injection');
        $this->assertSame("Hello all,\nsee you!", $draft->body);
        $this->assertSame([], $draft->textErrors());
    }

    public function testRequiresSubjectAndMessage(): void
    {
        $this->assertCount(2, EmailDraft::fromForm([])->textErrors());
    }

    public function testLimitsTheLength(): void
    {
        $subject = \str_repeat('a', EmailDraft::SUBJECT_MAX_LENGTH + 1);
        $draft = new EmailDraft([], $subject, \str_repeat('b', EmailDraft::BODY_MAX_LENGTH + 1));

        $this->assertCount(2, $draft->textErrors());
    }
}
