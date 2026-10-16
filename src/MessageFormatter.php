<?php

declare(strict_types=1);

namespace Orchestra\Email;

/**
 * Builds the plain-text body and the headers of the email sent to each recipient.
 */
final class MessageFormatter
{
    /**
     * @param list<string> $groupLabels
     */
    public static function body(string $body, string $senderName, array $groupLabels, string $siteName): string
    {
        $footer = \sprintf(
            /* translators: 1: sender name, 2: site name, 3: list of recipient groups */
            \__('Sent by %1$s via %2$s to: %3$s', 'orchestra-email'),
            $senderName,
            $siteName,
            \implode(', ', $groupLabels)
        );

        return $body . "\n\n-- \n" . $footer . "\n";
    }

    /**
     * Replies go to the sender. The From address stays the site's, so that the email passes SPF
     * and DKIM checks of the receiving servers.
     *
     * @return list<string>
     */
    public static function headers(string $senderName, string $senderEmail): array
    {
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if (\is_email($senderEmail) !== false) {
            $name = \trim((string) \preg_replace('/[\s"<>,;]+/', ' ', $senderName));
            $headers[] = 'Reply-To: ' . ($name === '' ? $senderEmail : "\"{$name}\" <{$senderEmail}>");
        }

        return $headers;
    }
}
