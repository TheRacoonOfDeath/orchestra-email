<?php

declare(strict_types=1);

namespace Orchestra\Email\Ui;

use Orchestra\Core\Ui\Notices;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Email\EmailBody;
use Orchestra\Email\EmailDraft;
use Orchestra\Email\EmailService;

/**
 * Saves or resets the text of an automatic email (admin-post.php?action=orchestra_email_automatic).
 */
final class AutomaticEmailsController
{
    public const ACTION = 'orchestra_email_automatic';
    public const FIELD_ID = 'automatic';

    public function __construct(private readonly EmailService $email)
    {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public static function nonceAction(string $automaticId): string
    {
        return self::ACTION . '_' . $automaticId;
    }

    /**
     * @internal Hooked to `admin_post_orchestra_email_automatic`.
     */
    public function handle(): never
    {
        $post = \wp_unslash($_POST);
        $id = \is_string($post[self::FIELD_ID] ?? null) ? \sanitize_key($post[self::FIELD_ID]) : '';
        \check_admin_referer(self::nonceAction($id));
        $email = $this->email->automaticEmails()->find($id);
        if ($email === null || !$email->canBeEditedBy(\get_current_user_id())) {
            \wp_die(\esc_html__('You are not allowed to change this email.', 'orchestra-email'), 403);
        }

        if (($post['op'] ?? '') === 'reset') {
            $this->email->resetAutomaticTemplate($email);
            Notices::success(\__('The default text is used again.', 'orchestra-email'));
            Redirect::back();
        }

        $subject = \is_string($post['subject'] ?? null) ? \trim(\sanitize_text_field($post['subject'])) : '';
        $body = \is_string($post['body'] ?? null) ? EmailBody::sanitize($post['body']) : '';
        if ($subject === '' || \mb_strlen($subject) > EmailDraft::SUBJECT_MAX_LENGTH) {
            Notices::error(\__('Please enter a subject.', 'orchestra-email'));
            Redirect::back();
        }
        if (EmailBody::isEmpty($body)) {
            Notices::error(\__('Please enter a message.', 'orchestra-email'));
            Redirect::back();
        }

        $this->email->saveAutomaticTemplate($email, $subject, $body);
        Notices::success(\__('The text has been saved.', 'orchestra-email'));
        Redirect::back();
    }
}
