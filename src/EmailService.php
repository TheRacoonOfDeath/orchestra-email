<?php

declare(strict_types=1);

namespace Orchestra\Email;

use Orchestra\Email\Admin\ComposePage;
use Orchestra\Email\Attachment\AttachmentService;
use Orchestra\Email\Automatic\AutomaticEmail;
use Orchestra\Email\Automatic\AutomaticEmailRegistry;
use Orchestra\Email\Automatic\TemplateRenderer;
use Orchestra\Email\Automatic\TemplateStore;
use Orchestra\Email\Ui\EmailShortcode;
use Orchestra\Core\Ui\Pages;

/**
 * The email service of the Orchestra suite. It contains no business rules: the domain plugins
 * register the recipient groups, this plugin sends one email per recipient and keeps a log.
 *
 *     add_action(EmailService::REGISTER_GROUPS_ACTION, function (EmailGroupRegistry $groups): void {
 *         $groups->add(new RecipientGroup('orchestra_members_all', 'All members', 'Members', $resolver, 'cap'));
 *     });
 *
 * Other plugins reach it with `\Orchestra\Email\Plugin::instance()->email()`, after checking that
 * `\Orchestra\Email\Plugin` exists: the plugin is optional.
 */
final class EmailService
{
    /** Fired with the {@see EmailGroupRegistry} the first time groups are needed in a request. */
    public const REGISTER_GROUPS_ACTION = 'orchestra_email_register_groups';

    /**
     * Filters the user IDs of every email: `(list<int> $userIds, list<string> $groupIds)`, when it
     * is sent and again before each batch is delivered, then without group IDs.
     * orchestra-members removes inactive members.
     */
    public const RECIPIENTS_FILTER = 'orchestra_email_recipients';

    /** Fired with the {@see AutomaticEmailRegistry} the first time automatic emails are needed in a request. */
    public const REGISTER_AUTOMATIC_ACTION = 'orchestra_email_register_automatic';

    /**
     * Filters the user IDs of every automatic email after {@see RECIPIENTS_FILTER}:
     * `(list<int> $userIds, string $automaticId)`, when it is sent and before each batch is delivered.
     * orchestra-members removes members who opted out of automatic emails.
     */
    public const AUTOMATIC_RECIPIENTS_FILTER = 'orchestra_email_automatic_recipients';

    /** Filters the number of days the log is kept. */
    public const RETENTION_FILTER = 'orchestra_email_log_retention_days';

    public const DEFAULT_RETENTION_DAYS = 365;

    /** Named after orchestra-core, which contained the email service before 0.7, to keep it scheduled. */
    public const PURGE_CRON_HOOK = 'orchestra_core_email_purge';

    private ?EmailGroupRegistry $registry = null;

    private ?AutomaticEmailRegistry $automatic = null;

    public function __construct(
        private readonly EmailLog $log,
        private readonly EmailSender $sender,
        private readonly RecipientResolver $resolver,
        private readonly AttachmentService $attachments,
        private readonly TemplateStore $templates = new TemplateStore(),
    ) {
    }

    public function attachments(): AttachmentService
    {
        return $this->attachments;
    }

    public function groups(): EmailGroupRegistry
    {
        if ($this->registry === null) {
            $this->registry = new EmailGroupRegistry();
            \do_action(self::REGISTER_GROUPS_ACTION, $this->registry);
        }

        return $this->registry;
    }

    public function automaticEmails(): AutomaticEmailRegistry
    {
        if ($this->automatic === null) {
            $this->automatic = new AutomaticEmailRegistry();
            \do_action(self::REGISTER_AUTOMATIC_ACTION, $this->automatic);
        }

        return $this->automatic;
    }

    /**
     * The current text of an automatic email: the organizers' or the default.
     *
     * @return array{subject: string, body: string, custom: bool} body as HTML; custom is false for the default
     */
    public function automaticTemplate(AutomaticEmail $email): array
    {
        return $this->templates->template($email);
    }

    /**
     * Stores the organizers' text of an automatic email. The caller checks
     * {@see AutomaticEmail::canBeEditedBy()}.
     *
     * @param string $body HTML; sanitized here
     */
    public function saveAutomaticTemplate(AutomaticEmail $email, string $subject, string $body): void
    {
        $this->templates->save($email->id, $subject, EmailBody::sanitize($body));
    }

    /**
     * Goes back to the default text of an automatic email.
     */
    public function resetAutomaticTemplate(AutomaticEmail $email): void
    {
        $this->templates->reset($email->id);
    }

    /**
     * Who of the users would receive the automatic email, e.g. without inactive members and members
     * who opted out of automatic emails.
     *
     * @param list<int> $userIds
     * @return list<int> user IDs, ascending
     */
    public function automaticRecipients(string $automaticId, array $userIds): array
    {
        return $this->resolver->automatic($automaticId, $userIds);
    }

    /**
     * Sends an automatic email with its current text, filled for each user with their own values,
     * and sends the first batch. Users who do not receive automatic emails (see
     * {@see automaticRecipients()}) are left out. Replies go to the sender, who gets no copy.
     *
     * @param array<int, array<string, string>> $values user ID => placeholder name => HTML, escaped by the caller
     * @param string $logLabel shown as the group in the log, e.g. "Changed parts: Spring Concert"
     * @return int the number of users the email is sent to
     */
    public function sendAutomatic(string $automaticId, int $senderId, string $logLabel, array $values): int
    {
        $email = $this->automaticEmails()->find($automaticId);
        if ($email === null) {
            return 0;
        }

        $template = $this->templates->template($email);
        $emails = [];
        foreach ($this->resolver->automatic($automaticId, \array_keys($values)) as $userId) {
            $emails[$userId] = [
                'subject' => TemplateRenderer::subject($template['subject'], $values[$userId]),
                'body' => TemplateRenderer::body($template['body'], $values[$userId]),
            ];
        }
        if ($emails === []) {
            return 0;
        }

        $sender = \get_userdata($senderId);
        $replyTo = $sender === false ? '' : $sender->user_email;
        $this->log->createAutomatic($automaticId, $senderId, $logLabel, $replyTo, $emails);
        $this->sender->sendBatch();

        return \count($emails);
    }

    /**
     * The URL for writing an email with the given groups selected, e.g. for a button on a project:
     * the page with `[orchestra_email]`, or the screen in wp-admin when called there or when there
     * is no such page.
     *
     * @param list<string> $groupIds
     */
    public function composeUrl(array $groupIds = []): string
    {
        $page = \is_admin() ? null : Pages::url(EmailShortcode::TAG);

        return $page === null ? ComposePage::url($groupIds) : EmailShortcode::composeUrl($page, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     * @return list<int> the user IDs that would receive an email to the groups
     */
    public function recipients(array $groupIds): array
    {
        return $this->resolver->resolve($this->findGroups($groupIds));
    }

    /**
     * @param list<string> $groupIds
     * @return list<int> the user IDs that would receive the email: the recipients of the groups and
     *     the sender, who always gets a copy
     */
    public function recipientsWithSender(array $groupIds, int $senderId): array
    {
        return $this->resolver->withSender($this->recipients($groupIds), $senderId);
    }

    /**
     * @return list<string> error messages; empty if the sender may send the draft
     */
    public function validate(EmailDraft $draft, int $senderId): array
    {
        $errors = $draft->textErrors();
        $attachments = $this->attachments->pending($draft->attachmentIds, $senderId);
        if (\count($attachments) !== \count($draft->attachmentIds)) {
            $errors[] = \__('An attachment is no longer available. Please add it again.', 'orchestra-email');
        }
        $errors = [...$errors, ...$this->attachments->errors($attachments)];

        return [...$errors, ...$this->groupErrors($draft->groupIds, $senderId)];
    }

    /**
     * @param list<string> $groupIds
     * @return list<string> error messages; empty if the user may email the groups and they have
     *     recipients
     */
    public function groupErrors(array $groupIds, int $userId): array
    {
        if ($groupIds === []) {
            return [\__('Please select at least one group.', 'orchestra-email')];
        }
        foreach ($groupIds as $id) {
            $group = $this->groups()->find($id);
            if ($group === null || !$group->canBeEmailedBy($userId)) {
                return [\__('You are not allowed to email one of the selected groups.', 'orchestra-email')];
            }
        }
        if ($this->recipients($groupIds) === []) {
            return [\__('The selected groups have no recipients.', 'orchestra-email')];
        }

        return [];
    }

    /**
     * The email addresses of the groups' recipients, for writing to them with one's own email app.
     * Check {@see groupErrors()} first: whoever may email the groups may see their addresses.
     *
     * @param list<string> $groupIds
     * @return list<string> sorted
     */
    public function addresses(array $groupIds): array
    {
        $addresses = [];
        foreach ($this->recipients($groupIds) as $userId) {
            $user = \get_userdata($userId);
            if ($user !== false) {
                $addresses[] = $user->user_email;
            }
        }
        \natcasesort($addresses);

        return \array_values(\array_unique($addresses));
    }

    /**
     * Queues the email for all current recipients of its groups and the sender, and sends the first
     * batch.
     * The draft must be valid, see {@see validate()}.
     *
     * @return int the ID of the log entry
     */
    public function send(EmailDraft $draft, int $senderId): int
    {
        $groups = $this->findGroups($draft->groupIds);
        $labels = \array_map(static fn (RecipientGroup $g): string => self::fullLabel($g), $groups);

        $recipientIds = $this->resolver->withSender($this->resolver->resolve($groups), $senderId);
        $messageId = $this->log->create($senderId, $draft, $labels, $recipientIds);
        $this->attachments->attach($this->attachments->pending($draft->attachmentIds, $senderId), $messageId);
        $this->sender->sendBatch();

        return $messageId;
    }

    public function message(int $messageId): ?EmailMessage
    {
        return $this->log->find($messageId);
    }

    /**
     * @return list<EmailMessage> newest first
     */
    public function log(int $limit = 200): array
    {
        return $this->log->latest($limit);
    }

    public function retentionDays(): int
    {
        $days = \apply_filters(self::RETENTION_FILTER, self::DEFAULT_RETENTION_DAYS);

        return \is_int($days) && $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * @internal Hooked to the daily cron event.
     */
    public function purgeLog(): void
    {
        $this->log->purgeBefore(\gmdate('Y-m-d H:i:s', \time() - $this->retentionDays() * DAY_IN_SECONDS));
        $this->attachments->cleanUp();
    }

    /**
     * @internal Hooked to `deleted_user`.
     */
    public function anonymizeUser(int $userId): void
    {
        $this->log->anonymizeSender($userId);
        $this->attachments->deletePendingOf($userId);
    }

    public static function fullLabel(RecipientGroup $group): string
    {
        return $group->category === '' ? $group->label : $group->category . ': ' . $group->label;
    }

    /**
     * @param list<string> $groupIds
     * @return list<RecipientGroup>
     */
    private function findGroups(array $groupIds): array
    {
        $groups = [];
        foreach ($groupIds as $id) {
            $group = $this->groups()->find($id);
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
