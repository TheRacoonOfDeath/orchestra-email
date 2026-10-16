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
            ['Content-Type: text/plain; charset=UTF-8', 'Reply-To: "Olga Organizer" <olga@example.com>'],
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
        $this->assertSame(['Content-Type: text/plain; charset=UTF-8'], MessageFormatter::headers('Former member', ''));
    }

    public function testAddsAFooterWithSenderAndGroups(): void
    {
        $body = MessageFormatter::body('Hello', 'Olga', ['Members: All members', 'Spring: Accepted'], 'Orchestra');

        $expected = "Hello\n\n-- \nSent by Olga via Orchestra to: Members: All members, Spring: Accepted\n";
        $this->assertSame($expected, $body);
    }
}
