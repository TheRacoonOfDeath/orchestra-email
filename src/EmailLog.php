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

    public function find(int $messageId): ?EmailMessage
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->schema->messagesTable(), $messageId),
            ARRAY_A
        );

        return \is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return list<EmailMessage> newest first
     */
    public function latest(int $limit): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d', $this->schema->messagesTable(), $limit),
            ARRAY_A
        ));

        return \array_map(self::hydrate(...), $rows);
    }

    /**
     * @return list<array{id: int, message_id: int, user_id: int, attempts: int}> oldest first
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
     * Keeps the log entries but removes the reference to the sender.
     */
    public function anonymizeSender(int $userId): void
    {
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET sender_id = NULL WHERE sender_id = %d',
            $this->schema->messagesTable(),
            $userId
        ));
        $this->execute($this->wpdb->prepare(
            'DELETE FROM %i WHERE user_id = %d',
            $this->schema->queueTable(),
            $userId
        ));
    }

    private function execute(?string $query): void
    {
        if ($query !== null) {
            $this->wpdb->query($query);
        }
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
        );
    }
}
