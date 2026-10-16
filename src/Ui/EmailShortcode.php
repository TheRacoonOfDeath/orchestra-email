<?php

declare(strict_types=1);

namespace Orchestra\Email\Ui;

use Orchestra\Core\Ui\Html;
use Orchestra\Core\Ui\Redirect;

/**
 * `[orchestra_email]`: write emails to groups and see the log, on a normal page.
 * `?orchestra_view=log` shows the log.
 */
final class EmailShortcode
{
    public const TAG = 'orchestra_email';
    private const VIEW_PARAMETER = 'orchestra_view';

    public function __construct(private readonly EmailView $view)
    {
    }

    public function register(): void
    {
        \add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * @internal Shortcode callback.
     */
    public function render(): string
    {
        if (!\is_user_logged_in()) {
            return Html::loginRequired(\__('Please log in to write emails.', 'orchestra-email'));
        }
        if ($this->view->allowedGroups() === []) {
            return Html::notAllowed(\__('You are not allowed to send emails.', 'orchestra-email'));
        }

        $pageUrl = \remove_query_arg([self::VIEW_PARAMETER, 'sent', 'groups'], Redirect::currentUrl());
        $logUrl = \add_query_arg(self::VIEW_PARAMETER, 'log', $pageUrl);
        $showLog = ($_GET[self::VIEW_PARAMETER] ?? '') === 'log';

        $tabs = Html::tabs(
            ['compose' => \__('Write email', 'orchestra-email'), 'log' => \__('Sent emails', 'orchestra-email')],
            $showLog ? 'log' : 'compose',
            static fn (string $tab): string => $tab === 'log' ? $logUrl : $pageUrl
        );
        $sent = \absint(\is_string($_GET['sent'] ?? null) ? $_GET['sent'] : 0);

        return Html::page($tabs . ($showLog ? $this->view->log($sent) : $this->view->compose($pageUrl, $logUrl)));
    }

    /**
     * Link target for other plugins: the compose view with groups selected.
     *
     * @param list<string> $groupIds
     */
    public static function composeUrl(string $pageUrl, array $groupIds): string
    {
        foreach ($groupIds as $id) {
            $pageUrl .= (\str_contains($pageUrl, '?') ? '&' : '?') . 'groups%5B%5D=' . \rawurlencode($id);
        }

        return $pageUrl;
    }
}
