<?php

declare(strict_types=1);

namespace Orchestra\Email\Admin;

use Orchestra\Email\Ui\EmailShortcode;
use Orchestra\Email\Ui\EmailView;
use Orchestra\Core\Ui\Html;

/**
 * The "Email" screens in wp-admin, showing the same {@see EmailView} as `[orchestra_email]`.
 *
 * The menu is shown to users who may email at least one group.
 */
final class ComposePage
{
    public const SLUG = 'orchestra-email';
    public const LOG_SLUG = 'orchestra-email-log';

    public function __construct(private readonly EmailView $view)
    {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'addMenu']);
    }

    /**
     * @param list<string> $groupIds
     */
    public static function url(array $groupIds = []): string
    {
        return EmailShortcode::composeUrl(\add_query_arg('page', self::SLUG, \admin_url('admin.php')), $groupIds);
    }

    public static function logUrl(): string
    {
        return \add_query_arg('page', self::LOG_SLUG, \admin_url('admin.php'));
    }

    /**
     * @internal Hooked to `admin_menu`.
     */
    public function addMenu(): void
    {
        if ($this->view->allowedGroups() === []) {
            return;
        }

        // Access is decided by the groups; `read` only keeps logged-out users away.
        \add_menu_page(
            \__('Email', 'orchestra-email'),
            \__('Email', 'orchestra-email'),
            'read',
            self::SLUG,
            [$this, 'renderCompose'],
            'dashicons-email',
            73
        );
        \add_submenu_page(
            self::SLUG,
            \__('Write Email', 'orchestra-email'),
            \__('Write Email', 'orchestra-email'),
            'read',
            self::SLUG,
            [$this, 'renderCompose']
        );
        \add_submenu_page(
            self::SLUG,
            \__('Sent Emails', 'orchestra-email'),
            \__('Sent Emails', 'orchestra-email'),
            'read',
            self::LOG_SLUG,
            [$this, 'renderLog']
        );
    }

    /**
     * @internal Menu page callback.
     */
    public function renderCompose(): void
    {
        echo Html::adminPage(
            \__('Write Email', 'orchestra-email'),
            $this->view->compose(self::url(), self::logUrl())
        ); // escaped by the view
    }

    /**
     * @internal Submenu page callback.
     */
    public function renderLog(): void
    {
        $sent = \absint(\is_string($_GET['sent'] ?? null) ? $_GET['sent'] : 0);
        echo Html::adminPage(
            \__('Sent Emails', 'orchestra-email'),
            $this->view->log($sent),
            ' <a class="page-title-action" href="' . \esc_url(self::url()) . '">'
                . \esc_html__('Write Email', 'orchestra-email') . '</a>'
        ); // escaped by the view
    }
}
