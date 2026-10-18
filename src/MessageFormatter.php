<?php

declare(strict_types=1);

namespace Orchestra\Email;

/**
 * Builds the email sent to each recipient: HTML, with a plain-text version for email programs
 * that do not show HTML, and the headers.
 */
final class MessageFormatter
{
    /**
     * @param string $body HTML, see {@see EmailBody}
     * @param list<string> $groupLabels
     */
    public static function html(string $body, string $senderName, array $groupLabels, string $siteName): string
    {
        // Inline styles: many email programs ignore style sheets.
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
            . '<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:16px;'
            . 'line-height:1.5;color:#1d1d1f">'
            . $body
            . '<hr style="border:0;border-top:1px solid #d0d0d0;margin:24px 0 8px">'
            . '<p style="margin:0;font-size:13px;color:#6b6b6b">'
            . \htmlspecialchars(self::footer($senderName, $groupLabels, $siteName), ENT_QUOTES) . '</p>'
            . '</body></html>';
    }

    /**
     * @param string $body HTML, see {@see EmailBody}
     * @param list<string> $groupLabels
     */
    public static function text(string $body, string $senderName, array $groupLabels, string $siteName): string
    {
        return EmailBody::plainText($body) . "\n\n-- \n" . self::footer($senderName, $groupLabels, $siteName) . "\n";
    }

    /**
     * Replies go to the sender. The From address stays the site's, so that the email passes SPF
     * and DKIM checks of the receiving servers.
     *
     * @return list<string>
     */
    public static function headers(string $senderName, string $senderEmail): array
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (\is_email($senderEmail) !== false) {
            $name = \trim((string) \preg_replace('/[\s"<>,;]+/', ' ', $senderName));
            $headers[] = 'Reply-To: ' . ($name === '' ? $senderEmail : "\"{$name}\" <{$senderEmail}>");
        }

        return $headers;
    }

    /**
     * @param list<string> $groupLabels
     */
    private static function footer(string $senderName, array $groupLabels, string $siteName): string
    {
        return \sprintf(
            /* translators: 1: sender name, 2: site name, 3: list of recipient groups */
            \__('Sent by %1$s via %2$s to: %3$s', 'orchestra-email'),
            $senderName,
            $siteName,
            \implode(', ', $groupLabels)
        );
    }
}
