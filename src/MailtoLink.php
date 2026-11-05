<?php

declare(strict_types=1);

namespace Orchestra\Email;

/**
 * A `mailto:` link that opens the user's own email app with the recipients in BCC.
 */
final class MailtoLink
{
    /** Some email apps (e.g. Outlook on Windows) cut off or refuse longer links. */
    public const SAFE_LENGTH = 2000;

    /**
     * @param list<string> $addresses
     */
    public static function bcc(array $addresses, string $subject = ''): string
    {
        $query = ['bcc=' . \implode(',', \array_map(self::encode(...), $addresses))];
        if ($subject !== '') {
            $query[] = 'subject=' . \rawurlencode($subject);
        }

        return 'mailto:?' . \implode('&', $query);
    }

    public static function isSafe(string $link): bool
    {
        return \strlen($link) <= self::SAFE_LENGTH;
    }

    /**
     * Encodes everything but the "@", which every email app understands and which keeps the link short.
     */
    private static function encode(string $address): string
    {
        return \str_replace('%40', '@', \rawurlencode($address));
    }
}
