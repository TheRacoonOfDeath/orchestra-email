<?php

declare(strict_types=1);

namespace Orchestra\Email\Ui;

use Orchestra\Email\EmailDraft;
use Orchestra\Email\EmailService;
use Orchestra\Core\Ui\Notices;
use Orchestra\Core\Ui\Redirect;

/**
 * Sends a reviewed email (admin-post.php?action=orchestra_email_send) and shows the log.
 */
final class EmailController
{
    public const ACTION = 'orchestra_email_send';

    /** Hidden field with the URL of the log to show after sending. */
    public const LOG_FIELD = 'orchestra_log_url';

    public function __construct(private readonly EmailService $email)
    {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * @internal Hooked to `admin_post_orchestra_email_send`.
     */
    public function handle(): never
    {
        \check_admin_referer(self::ACTION);
        $draft = EmailDraft::fromForm(\wp_unslash($_POST));
        $errors = $this->email->validate($draft, \get_current_user_id());
        if ($errors !== []) {
            Notices::errors($errors);
            Redirect::back();
        }

        $messageId = $this->email->send($draft, \get_current_user_id());
        $log = \is_string($_POST[self::LOG_FIELD] ?? null) ? \wp_unslash($_POST[self::LOG_FIELD]) : '';
        $log = \wp_validate_redirect($log, Redirect::target());

        \wp_safe_redirect(\add_query_arg('sent', $messageId, $log));
        exit;
    }
}
