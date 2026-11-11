<?php

declare(strict_types=1);

namespace Orchestra\Email;

use Orchestra\Core\Database\Row;
use RuntimeException;
use wpdb;

/**
 * The emails and their recipients waiting to be sent.
 */
final class EmailLog
{
    public function __construct(private readonly wpdb $wpdb, private readonly EmailSchema $schema)
    {
    }

    /**
     * @param list<string> $groupLabels
     * @param list<int> $recipientIds
     */
    public function create(int $senderId, EmailDraft $draft, array $groupLabels, array $recipientIds): int
    {
        $inserted = $this->wpdb->insert($this->schema->messagesTable(), [
            'sender_id' => $senderId,
            'subject' => $draft->subject,
            'body' => $draft->body,
            'reply_to' => $draft->replyTo,
            'group_labels' => (string) \wp_json_encode($groupLabels),
            'recipients' => \count($recipientIds),
            'status' => EmailMessage::STATUS_SENDING,
            'created_at' => \current_time('mysql', true),
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
        if ($inserted === false) {
            throw new RuntimeException('Could not store the email: ' . $this->wpdb->last_error);
        }
        $messageId = (int) $this->wpdb->insert_id;

        foreach ($recipientIds as $userId) {
            $this->wpdb->insert(
                $this->schema->queueTable(),
                ['message_id' => $messageId, 'user_id' => $userId],
                ['%d', '%d']
            );
        }

        return $messageId;
    }

    /**
     * An automatic email: every recipient has their own subject and body.
     *
     * @param array<int, array{subject: string, body: string}> $emails user ID => their email
     */
    public function createAutomatic(
        string $automaticId,
        int $senderId,
        string $logSubject,
        string $logLabel,
        string $replyTo,
        array $emails,
    ): int {
        $inserted = $this->wpdb->insert($this->schema->messagesTable(), [
            'sender_id' => $senderId,
            'subject' => \mb_substr($logSubject, 0, 255),
            'reply_to' => $replyTo,
            'group_labels' => (string) \wp_json_encode([$logLabel]),
            'recipients' => \count($emails),
            'status' => EmailMessage::STATUS_SENDING,
            'created_at' => \current_time('mysql', true),
            'automatic' => $automaticId,
        ], ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
        if ($inserted === false) {
            throw new RuntimeException('Could not store the email: ' . $this->wpdb->last_error);
        }
        $messageId = (int) $this->wpdb->insert_id;

        foreach ($emails as $userId => $email) {
            $this->wpdb->insert(
                $this->schema->queueTable(),
                [
                    'message_id' => $messageId,
                    'user_id' => $userId,
                    'subject' => \mb_substr($email['subject'], 0, 255),
                    'body' => $email['body'],
                ],
                ['%d', '%d', '%s', '%s']
            );
        }

        return $messageId;
    }

    public function find(int $messageId): ?EmailMessage
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->schema->messagesTable(), $messageId),
            ARRAY_A
        );

        return \is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param int|null $senderId only the emails of this sender; null for all
     * @return list<EmailMessage> newest first
     */
    public function latest(int $limit, ?int $senderId = null): array
    {
        $query = $senderId === null
            ? $this->wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d', $this->schema->messagesTable(), $limit)
            : $this->wpdb->prepare(
                'SELECT * FROM %i WHERE sender_id = %d ORDER BY id DESC LIMIT %d',
                $this->schema->messagesTable(),
                $senderId,
                $limit
            );
        $rows = Row::all($this->wpdb->get_results((string) $query, ARRAY_A));

        return \array_map(self::hydrate(...), $rows);
    }

    /**
     * The emails written to the user as a single person ({@see RecipientGroup::$person}).
     *
     * @return list<EmailMessage> newest first
     */
    public function addressedTo(int $userId): array
    {
        $rows = Row::all($this->wpdb->get_results((string) $this->wpdb->prepare(
            'SELECT * FROM %i WHERE group_labels LIKE %s ORDER BY id DESC',
            $this->schema->messagesTable(),
            '%' . $this->wpdb->esc_like(self::personToken($userId)) . '%'
        ), ARRAY_A));

        return \array_map(self::hydrate(...), $rows);
    }

    /**
     * How a group label refers to a person, see {@see RecipientGroup::$person}.
     */
    public static function personToken(int $userId): string
    {
        return '{user:' . $userId . '}';
    }

    /**
     * @return list<array{id: int, message_id: int, user_id: int, attempts: int, subject: ?string, body: ?string}>
     *     oldest first; subject and body only for automatic emails
     */
    public function nextRecipients(int $limit): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare('SELECT * FROM %i ORDER BY id LIMIT %d', $this->schema->queueTable(), $limit),
            ARRAY_A
        ));

        return \array_map(static fn (array $row): array => [
            'id' => Row::int($row, 'id'),
            'message_id' => Row::int($row, 'message_id'),
            'user_id' => Row::int($row, 'user_id'),
            'attempts' => Row::int($row, 'attempts'),
            'subject' => ($row['subject'] ?? null) === null ? null : Row::string($row, 'subject'),
            'body' => ($row['body'] ?? null) === null ? null : Row::string($row, 'body'),
        ], $rows);
    }

    public function hasQueuedRecipients(): bool
    {
        return $this->wpdb->get_var(
            $this->wpdb->prepare('SELECT COUNT(*) FROM %i', $this->schema->queueTable())
        ) > 0;
    }

    public function markSent(int $queueId, int $messageId): void
    {
        $this->wpdb->delete($this->schema->queueTable(), ['id' => $queueId]);
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET sent = sent + 1 WHERE id = %d',
            $this->schema->messagesTable(),
            $messageId
        ));
    }

    public function markFailed(int $queueId, int $messageId): void
    {
        $this->wpdb->delete($this->schema->queueTable(), ['id' => $queueId]);
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET failed = failed + 1 WHERE id = %d',
            $this->schema->messagesTable(),
            $messageId
        ));
    }

    /**
     * Removes a recipient who may no longer receive the email from the queue and from its count.
     */
    public function drop(int $queueId, int $messageId): void
    {
        $this->wpdb->delete($this->schema->queueTable(), ['id' => $queueId]);
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET recipients = recipients - 1 WHERE id = %d AND recipients > 0',
            $this->schema->messagesTable(),
            $messageId
        ));
    }

    public function retryLater(int $queueId): void
    {
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET attempts = attempts + 1 WHERE id = %d',
            $this->schema->queueTable(),
            $queueId
        ));
    }

    /**
     * Marks emails without queued recipients as sent and deletes their body and reply address.
     */
    public function completeFinished(): void
    {
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET status = %s, body = NULL, reply_to = NULL, completed_at = %s'
                . ' WHERE status = %s AND id NOT IN (SELECT message_id FROM %i)',
            $this->schema->messagesTable(),
            EmailMessage::STATUS_SENT,
            \current_time('mysql', true),
            EmailMessage::STATUS_SENDING,
            $this->schema->queueTable()
        ));
    }

    /**
     * Deletes log entries created before the given UTC time (Y-m-d H:i:s).
     */
    public function purgeBefore(string $time): void
    {
        $this->execute($this->wpdb->prepare(
            'DELETE FROM %i WHERE message_id IN (SELECT id FROM %i WHERE created_at < %s)',
            $this->schema->queueTable(),
            $this->schema->messagesTable(),
            $time
        ));
        $this->execute($this->wpdb->prepare(
            'DELETE FROM %i WHERE created_at < %s',
            $this->schema->messagesTable(),
            $time
        ));
    }

    /**
     * Keeps the log entries but removes every reference to the user: as the sender (with the reply
     * address of emails still being sent), as a single recipient in the groups, and as a recipient
     * still waiting in the queue.
     *
     * @return int the number of log entries and queued emails changed
     */
    public function anonymizeUser(int $userId): int
    {
        $changed = $this->update($this->wpdb->prepare(
            'UPDATE %i SET sender_id = NULL, reply_to = NULL WHERE sender_id = %d',
            $this->schema->messagesTable(),
            $userId
        ));
        $changed += $this->update($this->wpdb->prepare(
            'UPDATE %i SET group_labels = REPLACE(group_labels, %s, %s) WHERE group_labels LIKE %s',
            $this->schema->messagesTable(),
            self::personToken($userId),
            self::personToken(0),
            '%' . $this->wpdb->esc_like(self::personToken($userId)) . '%'
        ));

        $queued = Row::all($this->wpdb->get_results((string) $this->wpdb->prepare(
            'SELECT id, message_id FROM %i WHERE user_id = %d',
            $this->schema->queueTable(),
            $userId
        ), ARRAY_A));
        foreach ($queued as $row) {
            $this->drop(Row::int($row, 'id'), Row::int($row, 'message_id'));
        }

        return $changed + \count($queued);
    }

    private function execute(?string $query): void
    {
        if ($query !== null) {
            $this->wpdb->query($query);
        }
    }

    /**
     * @return int the number of changed rows
     */
    private function update(?string $query): int
    {
        if ($query === null) {
            return 0;
        }
        $changed = $this->wpdb->query($query);

        return \is_int($changed) ? $changed : 0;
    }

    /**
     * @param array<mixed> $row
     */
    private static function hydrate(array $row): EmailMessage
    {
        $labels = \json_decode(Row::string($row, 'group_labels'), true);

        return new EmailMessage(
            Row::int($row, 'id'),
            Row::nullableInt($row, 'sender_id'),
            Row::string($row, 'subject'),
            ($row['body'] ?? null) === null ? null : Row::string($row, 'body'),
            \is_array($labels) ? \array_values(\array_filter($labels, \is_string(...))) : [],
            Row::int($row, 'recipients'),
            Row::int($row, 'sent'),
            Row::int($row, 'failed'),
            Row::string($row, 'status'),
            Row::string($row, 'created_at'),
            ($row['reply_to'] ?? null) === null ? null : Row::string($row, 'reply_to'),
            ($row['automatic'] ?? null) === null ? null : Row::string($row, 'automatic'),
        );
    }
}
