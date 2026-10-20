<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\MessageFormatter;
use PHPUnit\Framework\TestCase;

final class MessageFormatterTest extends TestCase
{
    public function testRepliesGoToTheSender(): void
    {
        $this->assertSame(
            ['Content-Type: text/html; charset=UTF-8', 'Reply-To: "Olga Organizer" <olga@example.com>'],
            MessageFormatter::headers('Olga Organizer', 'olga@example.com')
        );
    }

    public function testSenderNamesCannotInjectHeaders(): void
    {
        $headers = MessageFormatter::headers("Olga\r\nBcc: x@example.com <\"evil\">", 'olga@example.com');

        $this->assertSame('Reply-To: "Olga Bcc: x@example.com evil" <olga@example.com>', $headers[1]);
    }

    public function testNoReplyToWithoutAValidAddress(): void
    {
        $this->assertSame(['Content-Type: text/html; charset=UTF-8'], MessageFormatter::headers('Former member', ''));
    }

    public function testAddsAFooterWithSenderAndGroups(): void
    {
        $footer = MessageFormatter::footer('Olga <3', ['Members: All members'], 'Orchestra');
        $html = MessageFormatter::html('<p>Hello</p>', $footer);
        $text = MessageFormatter::text('<p>Hello <b>all</b></p>', $footer);

        $this->assertStringContainsString('<p>Hello</p><hr', $html);
        $this->assertStringContainsString('Sent by Olga &lt;3 via Orchestra to: Members: All members', $html);
        $this->assertSame("Hello all\n\n-- \nSent by Olga <3 via Orchestra to: Members: All members\n", $text);
    }

    public function testEmailsWithoutFooterContainOnlyTheMessage(): void
    {
        $html = MessageFormatter::html('<p>Hello</p>', null);

        $this->assertStringContainsString('<p>Hello</p></body>', $html);
        $this->assertStringNotContainsString('<hr', $html);
        $this->assertSame("Hello all\n", MessageFormatter::text('<p>Hello <b>all</b></p>', null));
    }
}
