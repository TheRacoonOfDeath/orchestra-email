<?php

declare(strict_types=1);

namespace Orchestra\Email\Ui;

use Orchestra\Core\Ui\Notices;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Email\SenderName;

/**
 * Saves or resets the sender name (admin-post.php?action=orchestra_email_sender_name).
 */
final class SenderNameController
{
    public const ACTION = 'orchestra_email_sender_name';
    public const FIELD_NAME = 'sender_name';

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * @internal Hooked to `admin_post_orchestra_email_sender_name`.
     */
    public function handle(): never
    {
        \check_admin_referer(self::ACTION);
        if (!\current_user_can(SenderName::CAPABILITY)) {
            \wp_die(\esc_html__('You are not allowed to change the sender name.', 'orchestra-email'), 403);
        }

        $post = \wp_unslash($_POST);
        $name = ($post['op'] ?? '') === 'reset' || !\is_string($post[self::FIELD_NAME] ?? null)
            ? ''
            : $post[self::FIELD_NAME];
        SenderName::save($name);
        Notices::success(\sprintf(
            /* translators: %s: sender name */
            \__('Emails are now sent as "%s".', 'orchestra-email'),
            SenderName::current()
        ));
        Redirect::back();
    }
}
