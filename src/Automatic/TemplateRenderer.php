<?php

declare(strict_types=1);

namespace Orchestra\Email\Automatic;

/**
 * Fills the placeholders of an automatic email for one recipient. Unknown placeholders stay as
 * they are, so that a typo in the text is visible instead of silently removed.
 */
final class TemplateRenderer
{
    /**
     * @param array<string, string> $values placeholder name => HTML, escaped by the caller
     */
    public static function body(string $template, array $values): string
    {
        return self::replace($template, $values);
    }

    /**
     * The subject is plain text: tags of the values are removed and entities decoded.
     *
     * @param array<string, string> $values placeholder name => HTML, escaped by the caller
     */
    public static function subject(string $template, array $values): string
    {
        $text = \array_map(
            static fn (string $html): string => \html_entity_decode(\strip_tags($html), ENT_QUOTES, 'UTF-8'),
            $values
        );

        return \trim((string) \preg_replace('/\s+/', ' ', self::replace($template, $text)));
    }

    /**
     * @param array<string, string> $values
     */
    private static function replace(string $template, array $values): string
    {
        return (string) \preg_replace_callback(
            '/\{([a-z0-9_]+)\}/',
            static fn (array $match): string => $values[$match[1]] ?? $match[0],
            $template
        );
    }
}
