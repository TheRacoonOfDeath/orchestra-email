<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\MailtoLink;
use PHPUnit\Framework\TestCase;

final class MailtoLinkTest extends TestCase
{
    public function testPutsTheAddressesInBccWithTheSubject(): void
    {
        $this->assertSame(
            'mailto:?bcc=anna@example.com,ben%2Borchestra@example.com&subject=Rehearsal%20%26%20concert',
            MailtoLink::bcc(['anna@example.com', 'ben+orchestra@example.com'], 'Rehearsal & concert')
        );
    }

    public function testAddressesCannotAddFieldsToTheLink(): void
    {
        $this->assertSame('mailto:?bcc=a%26cc%3Dx@example.com', MailtoLink::bcc(['a&cc=x@example.com']));
    }

    public function testLongLinksAreNotSafe(): void
    {
        $this->assertTrue(MailtoLink::isSafe(\str_repeat('a', MailtoLink::SAFE_LENGTH)));
        $this->assertFalse(MailtoLink::isSafe(\str_repeat('a', MailtoLink::SAFE_LENGTH + 1)));
    }
}
