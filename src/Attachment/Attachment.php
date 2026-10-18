<?php

declare(strict_types=1);

namespace Orchestra\Email\Attachment;

/**
 * A file attached to an email. It is kept in a private directory until the email has been sent
 * to all recipients.
 */
final class Attachment
{
    public function __construct(
        public readonly int $id,
        /** null while the email is being written */
        public readonly ?int $messageId,
        public readonly int $uploadedBy,
        /** UUID; the file name in the storage directory */
        public readonly string $storageName,
        /** The sanitized name of the uploaded file, used in the email */
        public readonly string $fileName,
        public readonly string $mimeType,
        public readonly int $size,
    ) {
    }
}
