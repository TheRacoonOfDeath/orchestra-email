<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\SenderName;
use Orchestra\Email\Tests\Fakes\WordPress;
use PHPUnit\Framework\TestCase;

final class SenderNameTest extends TestCase
{
    protected function setUp(): void
    {
        WordPress::reset();
    }

    public function testUsesTheDefaultUntilANameIsSaved(): void
    {
        $this->assertSame('Orchestra', SenderName::current());
        $this->assertSame('', SenderName::custom());

        SenderName::save('  Gitarrenorchester   Speyer ');
        $this->assertSame('Gitarrenorchester Speyer', SenderName::current());

        SenderName::save('');
        $this->assertSame('Orchestra', SenderName::current());
        $this->assertSame('', SenderName::custom());
    }

    public function testRemovesWhatWouldBreakTheAddressHeader(): void
    {
        // One line without quotes or angle brackets, so it cannot add a header or an address.
        $this->assertSame(
            'Bad Name Bcc: x@example.com',
            SenderName::sanitize("\"Bad\" <b>Name</b>\r\nBcc: x@example.com <x>")
        );
        $this->assertSame(SenderName::MAX_LENGTH, \mb_strlen(SenderName::sanitize(\str_repeat('ä', 150))));
    }
}
