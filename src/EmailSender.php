<?php

declare(strict_types=1);

namespace Orchestra\Email;

use WP_User;

/**
 * Sends the queued emails in batches with `wp_mail()`, one email per recipient.
 *
 * Runs right after an email was queued and then with WP-Cron until the queue is empty. WP-Cron runs
 * when the site is visited; on sites with little traffic, a server cron job makes sending reliable.
 */
final class EmailSender
{
    /** Named after orchestra-core, which contained the email service before 0.7, like the lock. */
    public const CRON_HOOK = 'orchestra_core_email_send';

    /** Filter for the number of emails per batch. */
    public const BATCH_SIZE_FILTER = 'orchestra_email_batch_size';

    public const DEFAULT_BATCH_SIZE = 50;
    public const MAX_ATTEMPTS = 3;

    /** Seconds between two batches. */
    private const INTERVAL = 60;

    private const LOCK_OPTION = 'orchestra_core_email_lock';
    private const LOCK_TIMEOUT = 300;

    public function __construct(private readonly EmailLog $log)
    {
    }

    /**
     * @internal Hooked to the cron event.
     */
    public function sendBatch(): void
    {
        if (!$this->lock()) {
            return;
        }

        try {
            $batchSize = \apply_filters(self::BATCH_SIZE_FILTER, self::DEFAULT_BATCH_SIZE);
            $batchSize = \is_int($batchSize) && $batchSize > 0 ? $batchSize : self::DEFAULT_BATCH_SIZE;

            $messages = [];
            foreach ($this->log->nextRecipients($batchSize) as $entry) {
                $messages[$entry['message_id']] ??= $this->log->find($entry['message_id']);
                $message = $messages[$entry['message_id']];
                $user = \get_userdata($entry['user_id']);
                if ($message === null || $message->body === null || !$user instanceof WP_User) {
                    $this->log->markFailed($entry['id'], $entry['message_id']);
                    continue;
                }

                if ($this->deliver($message, $user)) {
                    $this->log->markSent($entry['id'], $message->id);
                } elseif ($entry['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->log->markFailed($entry['id'], $message->id);
                } else {
                    $this->log->retryLater($entry['id']);
                }
            }

            $this->log->completeFinished();
            if ($this->log->hasQueuedRecipients()) {
                $this->schedule(self::INTERVAL);
            }
        } finally {
            \delete_option(self::LOCK_OPTION);
        }
    }

    /**
     * Schedules sending again if emails are queued but no batch is scheduled. That happens when the
     * scheduled event ran while nothing handled it, e.g. after updating from orchestra-core 0.6
     * before this plugin was activated.
     */
    public function resumeIfStalled(): void
    {
        if (\wp_next_scheduled(self::CRON_HOOK) === false && $this->log->hasQueuedRecipients()) {
            $this->schedule();
        }
    }

    public function schedule(int $delay = 0): void
    {
        if (\wp_next_scheduled(self::CRON_HOOK) === false) {
            \wp_schedule_single_event(\time() + $delay, self::CRON_HOOK);
        }
    }

    private function deliver(EmailMessage $message, WP_User $recipient): bool
    {
        $sender = $message->senderId === null ? false : \get_userdata($message->senderId);
        $senderName = $sender instanceof WP_User ? $sender->display_name : \__('Former member', 'orchestra-email');
        $senderEmail = $sender instanceof WP_User ? $sender->user_email : '';
        $blogName = \get_option('blogname');
        $siteName = \wp_specialchars_decode(\is_string($blogName) ? $blogName : '', ENT_QUOTES);

        return \wp_mail(
            $recipient->user_email,
            $message->subject,
            MessageFormatter::body((string) $message->body, $senderName, $message->groupLabels, $siteName),
            MessageFormatter::headers($senderName, $senderEmail)
        );
    }

    /**
     * Prevents two requests from sending the same emails at the same time.
     */
    private function lock(): bool
    {
        if (\add_option(self::LOCK_OPTION, \time(), '', false)) {
            return true;
        }

        $since = \get_option(self::LOCK_OPTION);
        if (\is_numeric($since) && \time() - (int) $since > self::LOCK_TIMEOUT) {
            \update_option(self::LOCK_OPTION, \time(), false);
            return true;
        }

        return false;
    }
}
