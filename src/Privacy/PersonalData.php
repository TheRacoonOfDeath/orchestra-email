<?php

declare(strict_types=1);

namespace Orchestra\Email\Privacy;

use Orchestra\Email\EmailMessage;
use Orchestra\Email\EmailService;
use WP_User;

/**
 * Connects the email log to the WordPress privacy tools (Tools → Export / Erase Personal Data) and
 * suggests a text for the privacy policy.
 *
 * The log keeps who sent which email to which groups, never the recipients' addresses; the texts
 * and attachments only until the email has been sent. Erasing keeps the entries (other senders'
 * history) but removes every reference to the person, like deleting the account.
 */
final class PersonalData
{
    private const ID = 'orchestra-email';

    public function __construct(private readonly EmailService $email)
    {
    }

    public function register(): void
    {
        \add_filter('wp_privacy_personal_data_exporters', [$this, 'addExporter']);
        \add_filter('wp_privacy_personal_data_erasers', [$this, 'addEraser']);
        \add_action('admin_init', [$this, 'addPolicyText']);
    }

    /**
     * @internal Hooked to `wp_privacy_personal_data_exporters`.
     * @param array<mixed> $exporters
     * @return array<mixed>
     */
    public function addExporter(array $exporters): array
    {
        $exporters[self::ID] = [
            'exporter_friendly_name' => \__('Orchestra emails', 'orchestra-email'),
            'callback' => [$this, 'export'],
        ];

        return $exporters;
    }

    /**
     * @internal Hooked to `wp_privacy_personal_data_erasers`.
     * @param array<mixed> $erasers
     * @return array<mixed>
     */
    public function addEraser(array $erasers): array
    {
        $erasers[self::ID] = [
            'eraser_friendly_name' => \__('Orchestra emails', 'orchestra-email'),
            'callback' => [$this, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @internal Exporter callback.
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $user = \get_user_by('email', $email);
        if (!$user instanceof WP_User) {
            return ['data' => [], 'done' => true];
        }

        $data = [];
        foreach ($this->email->log(\PHP_INT_MAX, $user->ID) as $message) {
            $data[] = $this->item('sent', \__('Emails sent', 'orchestra-email'), $message, false);
        }
        foreach ($this->email->addressedTo($user->ID) as $message) {
            $label = \__('Emails written to you personally', 'orchestra-email');
            $data[] = $this->item('received', $label, $message, true);
        }

        return ['data' => $data, 'done' => true];
    }

    /**
     * @internal Eraser callback.
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $user = \get_user_by('email', $email);
        if (!$user instanceof WP_User) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $changed = $this->email->anonymizeUser($user->ID);

        return [
            'items_removed' => $changed > 0,
            'items_retained' => false,
            'messages' => $changed > 0 ? [\__(
                'The log of sent emails no longer refers to this person; the entries of other senders are kept.',
                'orchestra-email'
            )] : [],
            'done' => true,
        ];
    }

    /**
     * @internal Hooked to `admin_init`.
     */
    public function addPolicyText(): void
    {
        if (!\function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $sentences = [
            \__(
                'Conductors and organizers can send emails to groups of members, e.g. the participants of a project. '
                    . 'Every recipient gets an email of their own and does not see the other recipients.',
                'orchestra-email'
            ),
            \sprintf(
                /* translators: %d: number of days */
                \__(
                    'A log keeps who sent which email to which groups for %d days. It does not keep the email '
                        . 'addresses of the recipients; the text and the attachments are deleted once the email has '
                        . 'been sent. Senders see their own emails in the log, administrators all of them.',
                    'orchestra-email'
                ),
                $this->email->retentionDays()
            ),
            \__(
                'Whoever may email a group may also see the email addresses of its members, to write to them with '
                    . 'their own email program. Members who are inactive are never emailed.',
                'orchestra-email'
            ),
            \__(
                'Emails are delivered through the mail server of the website or the email service it uses, which '
                    . 'processes the addresses and contents on behalf of the orchestra.',
                'orchestra-email'
            ),
        ];

        \wp_add_privacy_policy_content(
            \__('Orchestra Email', 'orchestra-email'),
            \wp_kses_post('<p>' . \esc_html(\implode(' ', $sentences)) . '</p>')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $type, string $groupLabel, EmailMessage $message, bool $withSender): array
    {
        $data = [
            ['name' => \__('Date', 'orchestra-email'), 'value' => $message->createdAt . ' UTC'],
            ['name' => \__('Subject', 'orchestra-email'), 'value' => $message->subject],
            [
                'name' => \__('Groups', 'orchestra-email'),
                'value' => \implode(', ', \array_map(EmailService::displayLabel(...), $message->groupLabels)),
            ],
        ];
        if ($withSender) {
            $sender = $message->senderId === null ? false : \get_userdata($message->senderId);
            $data[] = [
                'name' => \__('Sender', 'orchestra-email'),
                'value' => $sender === false ? \__('Former member', 'orchestra-email') : $sender->display_name,
            ];
        } else {
            $data[] = ['name' => \__('Recipients', 'orchestra-email'), 'value' => (string) $message->recipients];
        }

        return [
            'group_id' => self::ID . '-' . $type,
            'group_label' => $groupLabel,
            'item_id' => self::ID . '-' . $type . '-' . $message->id,
            'data' => $data,
        ];
    }
}
