<?php

declare(strict_types=1);

namespace Orchestra\Email\Ui;

use Orchestra\Email\Attachment\Attachment;
use Orchestra\Email\Attachment\AttachmentService;
use Orchestra\Email\EmailDraft;
use Orchestra\Email\EmailMessage;
use Orchestra\Email\EmailService;
use Orchestra\Email\RecipientGroup;
use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Core\Ui\Notices;

/**
 * Writing, reviewing and sending emails, and the log. Shown by `[orchestra_email]` and in wp-admin.
 *
 * Review is a POST to the page itself; sending posts to {@see EmailController}.
 */
final class EmailView
{
    public const REVIEW_NONCE = 'orchestra_email_review';

    /** Recipients listed by name on the review step. */
    private const MAX_LISTED_RECIPIENTS = 200;

    /** The editor; wp_editor() allows only lowercase letters and underscores. */
    private const EDITOR_ID = 'orchestra_email_body';

    /** Font sizes of the editor, in pixels. */
    private const FONT_SIZES = [12, 14, 16, 18, 20, 24, 28, 32];

    public function __construct(private readonly EmailService $email)
    {
    }

    /**
     * @return list<RecipientGroup>
     */
    public function allowedGroups(): array
    {
        return $this->email->groups()->forUser(\get_current_user_id());
    }

    /**
     * @param string $pageUrl URL of the page showing the view
     * @param string $logUrl URL of the log
     */
    public function compose(string $pageUrl, string $logUrl): string
    {
        $operation = \is_string($_POST['op'] ?? null) ? $_POST['op'] : '';
        $nonce = \is_string($_POST['_wpnonce'] ?? null) ? $_POST['_wpnonce'] : '';
        $submitted = $operation !== '' && \wp_verify_nonce($nonce, self::REVIEW_NONCE) !== false;

        $draft = $submitted
            ? EmailDraft::fromForm(\wp_unslash($_POST))
            : new EmailDraft(self::requestedGroupIds(), '', '');
        $errors = [];
        if (self::uploadTooLarge()) {
            $errors[] = \sprintf(
                /* translators: %s: maximum size, e.g. "10 MB" */
                \__('The attachments are too large. At most %s can be uploaded at once.', 'orchestra-email'),
                \size_format(\wp_max_upload_size())
            );
        }
        if ($submitted && $operation === 'review') {
            // New files are uploaded on review and kept for the next steps.
            [$uploaded, $errors] = $this->email->attachments()->upload($_FILES, \get_current_user_id());
            $draft = new EmailDraft($draft->groupIds, $draft->subject, $draft->body, [
                ...$draft->attachmentIds,
                ...\array_map(static fn (Attachment $a): int => $a->id, $uploaded),
            ]);
            $errors = [...$errors, ...$this->email->validate($draft, \get_current_user_id())];
        }

        $html = '';
        foreach ($errors as $error) {
            $html .= Notices::html('error', $error);
        }
        if ($submitted && $operation === 'review' && $errors === []) {
            return $html . $this->review($draft, $pageUrl, $logUrl);
        }

        return $html . $this->form($draft, $pageUrl);
    }

    public function log(int $sentMessageId = 0): string
    {
        $html = '';
        $sent = $sentMessageId > 0 ? $this->email->message($sentMessageId) : null;
        if ($sent !== null) {
            $message = $sent->status === EmailMessage::STATUS_SENT
                /* translators: %d: number of recipients */
                ? \__('Your email has been sent to %d recipients.', 'orchestra-email')
                /* translators: %d: number of recipients */
                : \__('Your email is being sent to %d recipients. Large groups take a few minutes.', 'orchestra-email');
            $html .= '<div class="orchestra-notice orchestra-notice--success" role="status"><p>'
                . \esc_html(\sprintf($message, $sent->recipients)) . '</p></div>';
        }

        $rows = [];
        $format = self::dateTimeFormat();
        foreach ($this->email->log() as $message) {
            $sender = $message->senderId === null ? false : \get_userdata($message->senderId);
            $status = $message->status === EmailMessage::STATUS_SENT
                ? Html::badge(\__('Sent', 'orchestra-email'), 'success')
                : Html::badge(\sprintf(
                    /* translators: 1: number of sent emails, 2: number of recipients */
                    \__('Sending (%1$d of %2$d)', 'orchestra-email'),
                    $message->sent,
                    $message->recipients
                ), 'info');
            if ($message->failed > 0) {
                /* translators: %d: number of emails that could not be sent */
                $status .= ' ' . Html::badge(\sprintf(\__('%d failed', 'orchestra-email'), $message->failed), 'danger');
            }
            $rows[] = [
                \esc_html(self::fromGmt($message->createdAt, $format)),
                \esc_html($sender === false ? \__('Former member', 'orchestra-email') : $sender->display_name),
                '<strong>' . \esc_html($message->subject) . '</strong>',
                \esc_html(\implode(', ', $message->groupLabels)),
                (string) $message->recipients,
                $status,
            ];
        }

        return $html . Html::table(
            [
                \__('Date', 'orchestra-email'),
                \__('Sender', 'orchestra-email'),
                \__('Subject', 'orchestra-email'),
                \__('Groups', 'orchestra-email'),
                \__('Recipients', 'orchestra-email'),
                \__('Status', 'orchestra-email'),
            ],
            $rows,
            \__('No emails have been sent yet.', 'orchestra-email')
        ) . '<p class="orchestra-muted">' . \esc_html(\sprintf(
            /* translators: %d: number of days */
            \__('Texts are deleted once sent. The log shows who emailed which groups for %d days.', 'orchestra-email'),
            $this->email->retentionDays()
        )) . '</p>';
    }

    private function form(EmailDraft $draft, string $pageUrl): string
    {
        $html = '<form method="post" action="' . \esc_url($pageUrl) . '" enctype="multipart/form-data"'
            . ' class="orchestra-form orchestra-form--wide">'
            . \wp_nonce_field(self::REVIEW_NONCE, '_wpnonce', false, false)
            . Form::hidden('op', 'review')
            . '<fieldset class="orchestra-fieldset"><legend>' . \esc_html__('Recipients', 'orchestra-email')
            . '</legend>';

        $byCategory = [];
        foreach ($this->allowedGroups() as $group) {
            $byCategory[$group->category][] = $group;
        }
        foreach ($byCategory as $category => $groups) {
            $selected = \array_filter(
                $groups,
                static fn (RecipientGroup $g): bool => \in_array($g->id, $draft->groupIds, true)
            );
            $open = $selected !== [] || \count($byCategory) <= 2;
            $html .= '<details class="orchestra-group"' . ($open ? ' open' : '') . '><summary>'
                . \esc_html((string) $category)
                . ($selected !== [] ? ' ' . Html::badge((string) \count($selected), 'info') : '')
                . '</summary><div class="orchestra-checklist">';
            foreach ($groups as $group) {
                $html .= '<label class="orchestra-checkbox"><input type="checkbox" name="groups[]" value="'
                    . \esc_attr($group->id) . '"' . \checked(\in_array($group->id, $draft->groupIds, true), true, false)
                    . '> <span>' . \esc_html($group->label) . '</span></label>';
            }
            $html .= '</div></details>';
        }
        $html .= '</fieldset>';

        $html .= Html::field(
            'orchestra-email-subject',
            \__('Subject', 'orchestra-email'),
            '<input type="text" id="orchestra-email-subject" name="subject" required maxlength="'
                . EmailDraft::SUBJECT_MAX_LENGTH . '" value="' . \esc_attr($draft->subject) . '">'
        );
        $html .= Html::field(
            self::EDITOR_ID,
            \__('Message', 'orchestra-email'),
            self::editor($draft->body),
            \__('Every recipient gets their own email; replies go to your email address.', 'orchestra-email')
        );
        $html .= $this->attachmentFields($draft);

        return $html . '<div class="orchestra-actions">' . Form::submit(\__('Review', 'orchestra-email'), 'primary')
            . '</div></form>';
    }

    private function review(EmailDraft $draft, string $pageUrl, string $logUrl): string
    {
        $recipients = $this->email->recipients($draft->groupIds);
        $attachments = $this->email->attachments()->pending($draft->attachmentIds, \get_current_user_id());
        $labels = [];
        foreach ($draft->groupIds as $id) {
            $group = $this->email->groups()->find($id);
            if ($group !== null) {
                $labels[] = EmailService::fullLabel($group);
            }
        }
        $names = [];
        foreach (\array_slice($recipients, 0, self::MAX_LISTED_RECIPIENTS) as $userId) {
            $user = \get_userdata($userId);
            if ($user !== false) {
                $names[] = $user->display_name;
            }
        }
        \natcasesort($names);
        $count = \count($recipients);

        $html = '<div class="orchestra-card">' . Html::details([
            [\__('Groups', 'orchestra-email'), \esc_html(\implode(', ', $labels))],
            [
                \__('Recipients', 'orchestra-email'),
                /* translators: %d: number of recipients */
                '<strong>' . \esc_html(\sprintf(\_n('%d person', '%d people', $count, 'orchestra-email'), $count))
                    . '</strong><br><span class="orchestra-muted">' . \esc_html(\implode(', ', $names))
                    . ($count > self::MAX_LISTED_RECIPIENTS ? ', …' : '') . '</span>',
            ],
            [\__('Subject', 'orchestra-email'), \esc_html($draft->subject)],
            // The body was sanitized by EmailDraft::fromForm() in this request.
            [\__('Message', 'orchestra-email'), '<div class="orchestra-email-preview">' . $draft->body . '</div>'],
            ...($attachments === [] ? [] : [[
                \__('Attachments', 'orchestra-email'),
                \esc_html(\implode(', ', \array_map(self::attachmentLabel(...), $attachments))),
            ]]),
        ]) . '</div>';

        $fields = ['subject' => $draft->subject];
        $groupFields = '';
        foreach ($draft->groupIds as $id) {
            $groupFields .= Form::hidden('groups[]', $id);
        }
        foreach ($draft->attachmentIds as $id) {
            $groupFields .= Form::hidden(EmailDraft::FIELD_ATTACHMENT_IDS . '[]', (string) $id);
        }
        $body = '<textarea name="body" hidden>' . \esc_textarea($draft->body) . '</textarea>';

        $confirm = \sprintf(
            /* translators: %d: number of recipients */
            \_n('Send the email to %d person now?', 'Send the email to %d people now?', $count, 'orchestra-email'),
            $count
        );
        $send = Form::open(
            EmailController::ACTION,
            EmailController::ACTION,
            $fields + [EmailController::LOG_FIELD => $logUrl],
            ['class' => 'orchestra-inline-form', 'data-orchestra-confirm' => $confirm]
        ) . $groupFields . $body . Form::submit(\__('Send', 'orchestra-email'), 'primary') . '</form>';

        $edit = '<form method="post" action="' . \esc_url($pageUrl) . '" class="orchestra-inline-form">'
            . \wp_nonce_field(self::REVIEW_NONCE, '_wpnonce', false, false) . Form::hidden('op', 'edit')
            . Form::hidden('subject', $draft->subject) . $groupFields . $body
            . Form::submit(\__('Edit', 'orchestra-email')) . '</form>';

        return $html . '<div class="orchestra-actions">' . $send . $edit . '</div>';
    }

    /**
     * The editor of WordPress with a few formatting buttons. Without JavaScript, it is a text field.
     */
    private static function editor(string $body): string
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
        foreach ($colors as $hex => $name) {
            \array_push($colorMap, $hex, $name);
        }

        // Without the color picker plugin, only the colors above can be chosen.
        $withoutColorPicker = static fn (mixed $plugins, string $editorId = ''): mixed
            => $editorId === self::EDITOR_ID && \is_array($plugins)
                ? \array_values(\array_filter($plugins, static fn (mixed $plugin): bool => $plugin !== 'colorpicker'))
                : $plugins;
        \add_filter('tiny_mce_plugins', $withoutColorPicker, 10, 2);
        \ob_start();
        \wp_editor($body, self::EDITOR_ID, [
            'textarea_name' => 'body',
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

    private function attachmentFields(EmailDraft $draft): string
    {
        $service = $this->email->attachments();
        $html = '<fieldset class="orchestra-fieldset orchestra-stack"><legend>'
            . \esc_html__('Attachments', 'orchestra-email') . '</legend>';

        $attachments = $service->pending($draft->attachmentIds, \get_current_user_id());
        if ($attachments !== []) {
            $html .= '<div class="orchestra-checklist">';
            foreach ($attachments as $attachment) {
                $html .= '<label class="orchestra-checkbox"><input type="checkbox" name="'
                    . EmailDraft::FIELD_ATTACHMENT_IDS . '[]" value="' . $attachment->id . '" checked> <span>'
                    . \esc_html(self::attachmentLabel($attachment)) . '</span></label>';
            }
            $html .= '</div>';
        }

        return $html . '<input type="file" name="' . AttachmentService::FIELD . '[]" multiple accept="'
            . \esc_attr(AttachmentService::acceptAttribute()) . '" aria-label="'
            . \esc_attr__('Add attachments', 'orchestra-email') . '">'
            . '<p class="orchestra-field__hint">' . \esc_html(\sprintf(
                /* translators: 1: maximum number of files, 2: maximum size, e.g. "10 MB" */
                \__('Up to %1$d files with %2$s in total, e.g. PDF, images or Office documents.', 'orchestra-email'),
                AttachmentService::MAX_FILES,
                \size_format($service->maxSize())
            )) . '</p></fieldset>';
    }

    private static function attachmentLabel(Attachment $attachment): string
    {
        return $attachment->fileName . ' (' . \size_format($attachment->size) . ')';
    }

    /**
     * Whether the request was larger than PHP accepts: PHP then drops all form data.
     */
    private static function uploadTooLarge(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $_POST === [] && $_FILES === []
            && \is_numeric($_SERVER['CONTENT_LENGTH'] ?? null) && (int) $_SERVER['CONTENT_LENGTH'] > 0;
    }

    /**
     * The site's date and time format. Formats that are still WordPress's English defaults are
     * replaced by the defaults of the language the Orchestra pages are shown in.
     */
    private static function dateTimeFormat(): string
    {
        $date = self::option('date_format', 'Y-m-d');
        $time = self::option('time_format', 'H:i');

        /* translators: date format, see https://www.php.net/manual/datetime.format.php; German: j. F Y */
        $date = $date === 'F j, Y' ? \__('F j, Y', 'orchestra-email') : $date;
        /* translators: time format, see https://www.php.net/manual/datetime.format.php; German: H:i */
        $time = $time === 'g:i a' ? \__('g:i a', 'orchestra-email') : $time;

        return $date . ' ' . $time;
    }

    /**
     * A point in time stored in UTC, in the site's time zone with translated month names.
     */
    private static function fromGmt(string $dateTime, string $format): string
    {
        $timestamp = \strtotime($dateTime . ' UTC');

        return $timestamp === false ? $dateTime : (string) \wp_date($format, $timestamp);
    }

    private static function option(string $name, string $default): string
    {
        $value = \get_option($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private static function requestedGroupIds(): array
    {
        $groups = $_GET['groups'] ?? [];

        return \is_array($groups)
            ? \array_values(\array_filter(\array_map(
                static fn (mixed $id): string => \is_string($id) ? \sanitize_key($id) : '',
                $groups
            )))
            : [];
    }
}
