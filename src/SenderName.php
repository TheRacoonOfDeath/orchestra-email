<?php

declare(strict_types=1);

namespace Orchestra\Email;

/**
 * The name shown as the sender of the emails orchestra-email sends, instead of WordPress's
 * "WordPress". Administrators change it on the "Automatic emails" tab; without a custom name the
 * translated default "Orchestra" is used. The address stays the one of WordPress or an SMTP plugin.
 */
final class SenderName
{
    public const OPTION = 'orchestra_email_sender_name';
    public const MAX_LENGTH = 100;
    /** The capability that may change the name: a site-wide setting, for administrators. */
    public const CAPABILITY = 'manage_options';

    public static function current(): string
    {
        $custom = self::custom();

        return $custom === '' ? self::default() : $custom;
    }

    /**
     * The name an administrator entered, or '' for the default.
     */
    public static function custom(): string
    {
        $value = \get_option(self::OPTION, '');

        return \is_string($value) ? $value : '';
    }

    public static function default(): string
    {
        return \__('Orchestra', 'orchestra-email');
    }

    /**
     * Saves the name; '' goes back to the default.
     */
    public static function save(string $name): void
    {
        $name = self::sanitize($name);
        if ($name === '') {
            \delete_option(self::OPTION);
            return;
        }
        \update_option(self::OPTION, $name, false);
    }

    /**
     * One line of plain text without the characters that have a meaning in an address header.
     */
    public static function sanitize(string $name): string
    {
        $name = \str_replace(['"', '<', '>', '\\'], '', \sanitize_text_field($name));

        return \mb_substr(\trim((string) \preg_replace('/\s+/u', ' ', $name)), 0, self::MAX_LENGTH);
    }
}
