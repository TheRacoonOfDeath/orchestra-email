<?php

declare(strict_types=1);

namespace Orchestra\Email\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Email\Automatic\AutomaticEmail;
use Orchestra\Email\EmailDraft;
use Orchestra\Email\EmailService;

/**
 * The texts of the automatic emails, for the users who may change them. Shown on the "Automatic
 * emails" tab of `[orchestra_email]` and in wp-admin; saving posts to {@see AutomaticEmailsController}.
 */
final class AutomaticEmailsView
{
    public function __construct(private readonly EmailService $email)
    {
    }

    /**
     * @return list<AutomaticEmail>
     */
    public function editable(): array
    {
        return $this->email->automaticEmails()->editableBy(\get_current_user_id());
    }

    public function render(): string
    {
        $emails = $this->editable();
        if ($emails === []) {
            return Html::empty(\__('There are no automatic emails you may change.', 'orchestra-email'));
        }

        $html = '<p class="orchestra-muted">' . \esc_html__(
            'The system sends these emails by itself. Members can turn them off in their profile.',
            'orchestra-email'
        ) . '</p>';
        foreach ($emails as $index => $email) {
            $html .= $this->card($email, $index);
        }

        return $html;
    }

    private function card(AutomaticEmail $email, int $index): string
    {
        $template = $this->email->automaticTemplate($email);
        $prefix = 'orchestra-email-automatic-' . $index;

        $placeholders = [];
        foreach ($email->placeholders as $name => $description) {
            $placeholders[] = '<li><code>{' . \esc_html($name) . '}</code> ' . \esc_html($description) . '</li>';
        }

        $html = '<div class="orchestra-card orchestra-stack"><h3 class="orchestra-card__title">'
            . \esc_html($email->label) . ' '
            . ($template['custom']
                ? Html::badge(\__('Changed', 'orchestra-email'), 'info')
                : Html::badge(\__('Default', 'orchestra-email')))
            . '</h3><p>' . \esc_html($email->description) . '</p>'
            . Form::open(
                AutomaticEmailsController::ACTION,
                AutomaticEmailsController::nonceAction($email->id),
                [AutomaticEmailsController::FIELD_ID => $email->id],
                ['class' => 'orchestra-form orchestra-form--wide']
            )
            . Html::field(
                $prefix . '-subject',
                \__('Subject', 'orchestra-email'),
                '<input type="text" id="' . $prefix . '-subject" name="subject" required maxlength="'
                    . EmailDraft::SUBJECT_MAX_LENGTH . '" value="' . \esc_attr($template['subject']) . '">'
            )
            . Html::field(
                // wp_editor() allows only lowercase letters and underscores.
                'orchestra_email_automatic_' . $index,
                \__('Message', 'orchestra-email'),
                Editor::html('orchestra_email_automatic_' . $index, 'body', $template['body'])
            );
        if ($placeholders !== []) {
            $html .= '<div class="orchestra-field__hint">' . \esc_html__(
                'These placeholders are replaced for every recipient:',
                'orchestra-email'
            ) . '<ul>' . \implode('', $placeholders) . '</ul></div>';
        }

        $html .= '<div class="orchestra-actions">'
            . Form::submit(\__('Save', 'orchestra-email'), 'primary', 'op', 'save');
        if ($template['custom']) {
            $html .= '<button type="submit" class="orchestra-button" name="op" value="reset" formnovalidate'
                . ' data-orchestra-confirm="' . \esc_attr__(
                    'Replace your text with the default text?',
                    'orchestra-email'
                ) . '">' . \esc_html__('Reset to default', 'orchestra-email') . '</button>';
        }

        return $html . '</div></form></div>';
    }
}
