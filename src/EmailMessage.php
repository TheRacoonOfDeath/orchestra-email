<?php

declare(strict_types=1);

namespace Orchestra\Email;

/**
 * An entry of the email log.
 */
final class EmailMessage
{
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';

    /**
     * @param list<string> $groupLabels The groups' labels when the email was sent
     */
    public function __construct(
        public readonly int $id,
        /** null once the sender's account has been deleted */
        public readonly ?int $senderId,
        public readonly string $subject,
        /** null once the email has been sent */
        public readonly ?string $body,
        public readonly array $groupLabels,
        public readonly int $recipients,
        public readonly int $sent,
        public readonly int $failed,
        public readonly string $status,
        /** UTC, Y-m-d H:i:s */
        public readonly string $createdAt,
    ) {
    }
}
