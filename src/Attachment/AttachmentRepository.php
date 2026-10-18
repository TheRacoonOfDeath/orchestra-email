<?php

declare(strict_types=1);

namespace Orchestra\Email\Attachment;

use Orchestra\Core\Database\Row;
use Orchestra\Email\EmailMessage;
use Orchestra\Email\EmailSchema;
use RuntimeException;
use wpdb;

/**
 * The metadata of the attachments. Attachments without a message are still being written.
 */
final class AttachmentRepository
{
    public function __construct(private readonly wpdb $wpdb, private readonly EmailSchema $schema)
    {
    }

    public function insert(
        int $uploadedBy,
        string $storageName,
        string $fileName,
        string $mimeType,
        int $size,
    ): Attachment {
        $inserted = $this->wpdb->insert($this->schema->attachmentsTable(), [
            'uploaded_by' => $uploadedBy,
            'storage_name' => $storageName,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'size' => $size,
            'created_at' => \current_time('mysql', true),
        ], ['%d', '%s', '%s', '%s', '%d', '%s']);
        if ($inserted === false) {
            throw new RuntimeException('Could not store the attachment: ' . $this->wpdb->last_error);
        }

        $id = (int) $this->wpdb->insert_id;

        return new Attachment($id, null, $uploadedBy, $storageName, $fileName, $mimeType, $size);
    }

    /**
     * @param list<int> $ids
     * @return list<Attachment> the attachments among the IDs that the user uploaded for an email not
     *         sent yet, in upload order
     */
    public function pending(array $ids, int $userId): array
    {
        return \array_values(\array_filter(
            $ids === [] ? [] : $this->pendingOfUser($userId),
            static fn (Attachment $attachment): bool => \in_array($attachment->id, $ids, true)
        ));
    }

    /**
     * @return list<Attachment> in upload order
     */
    public function forMessage(int $messageId): array
    {
        return $this->select('message_id = %d', [$messageId]);
    }

    /**
     * @param list<int> $ids
     */
    public function assign(array $ids, int $messageId): void
    {
        // At most a few attachments per email.
        foreach ($ids as $id) {
            $this->execute($this->wpdb->prepare(
                'UPDATE %i SET message_id = %d WHERE message_id IS NULL AND id = %d',
                $this->schema->attachmentsTable(),
                $messageId,
                $id
            ));
        }
    }

    /**
     * @return list<Attachment> attachments that are no longer needed: those of emails that are no
     *         longer being sent, and those of emails never sent, uploaded before the given UTC time
     */
    public function obsolete(string $pendingBefore): array
    {
        return $this->select(
            '(message_id IS NOT NULL AND message_id NOT IN (SELECT id FROM %i WHERE status = %s))'
                . ' OR (message_id IS NULL AND created_at < %s)',
            [$this->schema->messagesTable(), EmailMessage::STATUS_SENDING, $pendingBefore]
        );
    }

    /**
     * @return list<Attachment> the attachments the user uploaded for emails not sent yet
     */
    public function pendingOfUser(int $userId): array
    {
        return $this->select('message_id IS NULL AND uploaded_by = %d', [$userId]);
    }

    /**
     * @param list<int> $ids
     */
    public function delete(array $ids): void
    {
        foreach ($ids as $id) {
            $this->wpdb->delete($this->schema->attachmentsTable(), ['id' => $id], ['%d']);
        }
    }

    /**
     * @param literal-string $where
     * @param list<int|string> $arguments
     * @return list<Attachment>
     */
    private function select(string $where, array $arguments): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE ' . $where . ' ORDER BY id',
                $this->schema->attachmentsTable(),
                ...$arguments
            ),
            ARRAY_A
        ));

        return \array_map(static fn (array $row): Attachment => new Attachment(
            Row::int($row, 'id'),
            Row::nullableInt($row, 'message_id'),
            Row::int($row, 'uploaded_by'),
            Row::string($row, 'storage_name'),
            Row::string($row, 'file_name'),
            Row::string($row, 'mime_type'),
            Row::int($row, 'size'),
        ), $rows);
    }

    private function execute(?string $query): void
    {
        if ($query !== null) {
            $this->wpdb->query($query);
        }
    }
}
