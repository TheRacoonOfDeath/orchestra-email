<?php

declare(strict_types=1);

namespace Orchestra\Email\Ui;

/**
 * The editor for email texts, shared by writing emails and the automatic emails.
 */
final class Editor
{
    /** Font sizes of the editor, in pixels. */
    private const FONT_SIZES = [12, 14, 16, 18, 20, 24, 28, 32];

    /**
     * The editor of WordPress with a few formatting buttons. Without JavaScript, it is a text field.
     */
    public static function html(string $id, string $name, string $body): string
    {
        $colors = [
            '1d1d1f' => \__('Black', 'orchestra-email'),
            '6b6b6b' => \__('Gray', 'orchestra-email'),
            'c0392b' => \__('Red', 'orchestra-email'),
            'd35400' => \__('Orange', 'orchestra-email'),
            '1e8449' => \__('Green', 'orchestra-email'),
            '1f618d' => \__('Blue', 'orchestra-email'),
            '7d3c98' => \__('Purple', 'orchestra-email'),
        ];
        $colorMap = [];
        foreach ($colors as $hex => $colorName) {
            \array_push($colorMap, $hex, $colorName);
        }

        // Without the color picker plugin, only the colors above can be chosen.
        $withoutColorPicker = static fn (mixed $plugins, string $editorId = ''): mixed
            => $editorId === $id && \is_array($plugins)
                ? \array_values(\array_filter($plugins, static fn (mixed $plugin): bool => $plugin !== 'colorpicker'))
                : $plugins;
        \add_filter('tiny_mce_plugins', $withoutColorPicker, 10, 2);
        \ob_start();
        \wp_editor($body, $id, [
            'textarea_name' => $name,
            'textarea_rows' => 12,
            // The submitted HTML keeps its paragraphs instead of WordPress's line break convention.
            'wpautop' => false,
            'media_buttons' => false,
            'quicktags' => false,
            'drag_drop_upload' => false,
            'tinymce' => [
                'toolbar1' => 'bold,italic,underline,|,fontsizeselect,forecolor,|,bullist,numlist,|,'
                    . 'removeformat,undo,redo',
                'toolbar2' => '',
                'fontsize_formats' => \implode(' ', \array_map(
                    static fn (int $size): string => $size . 'px',
                    self::FONT_SIZES
                )),
                'textcolor_map' => (string) \wp_json_encode($colorMap),
                'textcolor_cols' => \count($colors),
                'custom_colors' => false,
                'menubar' => false,
                'elementpath' => false,
            ],
        ]);
        \remove_filter('tiny_mce_plugins', $withoutColorPicker);

        return (string) \ob_get_clean();
    }
}
