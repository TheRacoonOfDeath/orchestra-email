<?php

declare(strict_types=1);

namespace Orchestra\Email;

/**
 * The text of an email: HTML with the formatting of the editor on the compose screen (bold,
 * italic, underline, font size, color, lists). Everything else is removed, so that the email looks
 * the same in every email program and contains nothing that could harm the recipients.
 */
final class EmailBody
{
    /** @var array<string, array<string, bool>> */
    private const ALLOWED_TAGS = [
        'p' => ['style' => true],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'span' => ['style' => true],
        'ul' => [],
        'ol' => [],
        'li' => ['style' => true],
        'a' => ['href' => true],
        'blockquote' => [],
    ];

    /** CSS properties kept in `style` attributes, with the pattern their value must match. */
    private const ALLOWED_STYLES = [
        'color' => '/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\)|[a-z]{3,20})$/i',
        'font-size' => '/^(\d{1,2}(\.\d+)?(px|pt|em|rem)|\d{2,3}%|(x{1,2}-)?(small|large)|medium)$/',
        'text-decoration' => '/^(underline|line-through|none)( (underline|line-through))?$/',
    ];

    /**
     * @param string $html From the editor; plain text from browsers without JavaScript is accepted too
     */
    public static function sanitize(string $html): string
    {
        $html = \str_replace("\r\n", "\n", $html);
        if (\preg_match('/<\/?(p|br|div|strong|b|em|i|u|s|span|ul|ol|li|a|blockquote)\b/i', $html) !== 1) {
            // Plain text, e.g. from browsers without JavaScript: keep the line breaks.
            return \trim(\wpautop(\esc_html($html)));
        }

        $html = \wp_kses($html, self::ALLOWED_TAGS, ['http', 'https', 'mailto', 'tel']);

        return \trim(self::filterStyles($html));
    }

    /**
     * Reduces every `style` attribute to the allowed properties, and removes it if none is left.
     */
    public static function filterStyles(string $html): string
    {
        return (string) \preg_replace_callback(
            '/\sstyle="([^"]*)"/i',
            static function (array $match): string {
                $kept = [];
                foreach (\explode(';', \html_entity_decode($match[1], ENT_QUOTES)) as $declaration) {
                    [$property, $value] = \array_map('trim', \explode(':', $declaration, 2) + [1 => '']);
                    $property = \strtolower($property);
                    $pattern = self::ALLOWED_STYLES[$property] ?? null;
                    if ($pattern !== null && \preg_match($pattern, $value) === 1) {
                        $kept[] = $property . ': ' . $value;
                    }
                }

                return $kept === [] ? '' : ' style="' . \htmlspecialchars(\implode('; ', $kept), ENT_QUOTES) . ';"';
            },
            $html
        );
    }

    public static function isEmpty(string $html): bool
    {
        return \trim(\str_replace("\u{A0}", ' ', self::plainText($html))) === '';
    }

    /**
     * The text without formatting, for email programs that do not show HTML.
     */
    public static function plainText(string $html): string
    {
        $text = (string) \preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = (string) \preg_replace('/<li[^>]*>/i', '- ', $text);
        $text = (string) \preg_replace('/<\/(p|blockquote|ul|ol)>/i', "\n\n", $text);
        $text = (string) \preg_replace('/<\/li>/i', "\n", $text);
        $text = \html_entity_decode(\strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) \preg_replace('/^[ \t]+|[ \t]+$/m', '', $text);

        return \trim((string) \preg_replace('/\n{3,}/', "\n\n", $text));
    }

    /**
     * Converts the text of emails written before formatting was possible.
     */
    public static function fromPlainText(string $text): string
    {
        return \trim(\wpautop(\esc_html($text)));
    }
}
