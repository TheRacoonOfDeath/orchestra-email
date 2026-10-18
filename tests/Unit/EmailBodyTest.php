<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\EmailBody;
use PHPUnit\Framework\TestCase;

final class EmailBodyTest extends TestCase
{
    public function testKeepsOnlyColorFontSizeAndUnderline(): void
    {
        $html = '<span style="color: #c0392b; font-size: 20px; background: url(x); position: fixed">a</span>'
            . '<span style="text-decoration: underline;">b</span><p style="margin: 0">c</p>'
            . '<span style="color: expression(alert(1))">d</span>';

        $this->assertSame(
            '<span style="color: #c0392b; font-size: 20px;">a</span>'
                . '<span style="text-decoration: underline;">b</span><p>c</p><span>d</span>',
            EmailBody::filterStyles($html)
        );
    }

    public function testConvertsToPlainText(): void
    {
        $html = '<p>Hello <strong>all</strong>,<br>see you &amp; bring:</p><ul><li>Music</li><li>Stand</li></ul>'
            . '<p>Olga</p>';

        $this->assertSame("Hello all,\nsee you & bring:\n\n- Music\n- Stand\n\nOlga", EmailBody::plainText($html));
    }

    public function testRecognizesEmptyText(): void
    {
        $this->assertTrue(EmailBody::isEmpty('<p>&nbsp;</p><p><br></p>'));
        $this->assertFalse(EmailBody::isEmpty('<p>x</p>'));
    }
}
