<?php

declare(strict_types=1);

namespace Orchestra\Email\Attachment;

use Closure;
use RuntimeException;

/**
 * Uploads, checks and deletes the attachments of emails.
 *
 * Files are uploaded when the email is reviewed and belong to the uploader until the email is
 * sent. They are deleted when the email has been sent to all recipients, like its text; files of
 * emails that are never sent are deleted after a day.
 */
final class AttachmentService
{
    /** The name of the file field: `<input type="file" name="attachments[]" multiple>`. */
    public const FIELD = 'attachments';

    /** Filters the allowed extensions and MIME types, in the format of WordPress's `upload_mimes`. */
    public const FILE_TYPES_FILTER = 'orchestra_email_allowed_file_types';

    /** Filters the maximum size of all attachments of an email, in bytes. */
    public const MAX_SIZE_FILTER = 'orchestra_email_max_attachment_size';

    public const DEFAULT_MAX_SIZE = 10 * 1024 * 1024;
    public const MAX_FILES = 10;

    /** Documents and images that every email program can open. */
    public const DEFAULT_FILE_TYPES = [
        'pdf' => 'application/pdf',
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'txt' => 'text/plain',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];

    /** Seconds after which attachments of emails that were never sent are deleted. */
    private const PENDING_LIFETIME = 86400;

    /**
     * @param Closure(string $path): bool $isUploadedFile Defaults to PHP's is_uploaded_file()
     */
    public function __construct(
        private readonly AttachmentRepository $repository,
        private readonly AttachmentStorage $storage,
        private readonly ?Closure $isUploadedFile = null,
    ) {
    }

    /**
     * Stores the files chosen in the form.
     *
     * @param array<mixed> $files `$_FILES`
     * @return array{list<Attachment>, list<string>} the stored attachments and error messages for
     *         the rejected files
     */
    public function upload(array $files, int $userId): array
    {
        $attachments = [];
        $errors = [];
        foreach (self::uploads($files) as $upload) {
            $error = $this->rejection($upload);
            if ($error !== null) {
                $errors[] = $error;
                continue;
            }
            $check = \wp_check_filetype_and_ext($upload['tmp_name'], $upload['name'], self::allowedFileTypes());
            if (!\is_string($check['type']) || $check['type'] === '') {
                $errors[] = \sprintf(
                    /* translators: 1: file name, 2: list of allowed file extensions */
                    \__('"%1$s" has a file type that cannot be attached. Allowed types: %2$s.', 'orchestra-email'),
                    $upload['name'],
                    self::allowedExtensionsLabel()
                );
                continue;
            }
            $fileName = \is_string($check['proper_filename']) && $check['proper_filename'] !== ''
                ? $check['proper_filename']
                : $upload['name'];

            try {
                $storageName = $this->storage->store($upload['tmp_name']);
            } catch (RuntimeException) {
                /* translators: %s: file name */
                $errors[] = \sprintf(\__('"%s" could not be stored.', 'orchestra-email'), $upload['name']);
                continue;
            }
            $attachments[] = $this->repository->insert(
                $userId,
                $storageName,
                \sanitize_file_name($fileName),
                $check['type'],
                $upload['size']
            );
        }

        return [$attachments, $errors];
    }

    /**
     * @param list<int> $ids
     * @return list<Attachment> the attachments among the IDs that the user uploaded for an email not
     *         sent yet; others are ignored
     */
    public function pending(array $ids, int $userId): array
    {
        return $this->repository->pending($ids, $userId);
    }

    /**
     * @param list<Attachment> $attachments
     * @return list<string> error messages if there are too many or too large attachments
     */
    public function errors(array $attachments): array
    {
        $errors = [];
        if (\count($attachments) > self::MAX_FILES) {
            /* translators: %d: maximum number of attachments */
            $message = \__('An email can have at most %d attachments.', 'orchestra-email');
            $errors[] = \sprintf($message, self::MAX_FILES);
        }
        $size = \array_sum(\array_map(static fn (Attachment $a): int => $a->size, $attachments));
        if ($size > $this->maxSize()) {
            /* translators: %s: maximum size, e.g. "10 MB" */
            $message = \__('The attachments must not be larger than %s in total.', 'orchestra-email');
            $errors[] = \sprintf($message, \size_format($this->maxSize()));
        }

        return $errors;
    }

    /**
     * @param list<Attachment> $attachments Pending attachments, see {@see pending()}
     */
    public function attach(array $attachments, int $messageId): void
    {
        $this->repository->assign(\array_map(static fn (Attachment $a): int => $a->id, $attachments), $messageId);
    }

    /**
     * @return array<string, string> file name => path, for `wp_mail()`
     */
    public function filesOf(int $messageId): array
    {
        $attachments = $this->repository->forMessage($messageId);
        $names = self::uniqueNames(\array_map(static fn (Attachment $a): string => $a->fileName, $attachments));

        $files = [];
        foreach ($attachments as $index => $attachment) {
            $files[$names[$index]] = $this->storage->path($attachment->storageName);
        }

        return $files;
    }

    /**
     * Deletes the attachments of sent emails and of emails not sent within a day.
     */
    public function cleanUp(): void
    {
        $this->delete($this->repository->obsolete(\gmdate('Y-m-d H:i:s', \time() - self::PENDING_LIFETIME)));
    }

    /**
     * Deletes the attachments a user uploaded for emails not sent yet, e.g. when the user is deleted.
     */
    public function deletePendingOf(int $userId): void
    {
        $this->delete($this->repository->pendingOfUser($userId));
    }

    /**
     * The maximum size of all attachments of an email, limited by the uploads PHP accepts.
     */
    public function maxSize(): int
    {
        $size = \apply_filters(self::MAX_SIZE_FILTER, self::DEFAULT_MAX_SIZE);
        $size = \is_int($size) && $size > 0 ? $size : self::DEFAULT_MAX_SIZE;
        $uploadLimit = \wp_max_upload_size();

        return $uploadLimit > 0 ? \min($size, $uploadLimit) : $size;
    }

    /**
     * @return array<string, string>
     */
    public static function allowedFileTypes(): array
    {
        $types = \apply_filters(self::FILE_TYPES_FILTER, self::DEFAULT_FILE_TYPES);
        if (!\is_array($types)) {
            return self::DEFAULT_FILE_TYPES;
        }

        $allowed = [];
        foreach ($types as $extensions => $mimeType) {
            if (\is_string($extensions) && \is_string($mimeType)) {
                $allowed[$extensions] = $mimeType;
            }
        }

        return $allowed;
    }

    /**
     * The value for the `accept` attribute of the file field.
     */
    public static function acceptAttribute(): string
    {
        $extensions = \explode('|', \implode('|', \array_keys(self::allowedFileTypes())));

        return \implode(',', \array_map(static fn (string $extension): string => '.' . $extension, $extensions));
    }

    /**
     * Makes file names unique, e.g. two "Notes.pdf" become "Notes.pdf" and "Notes (2).pdf", because
     * `wp_mail()` takes the names as array keys.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function uniqueNames(array $names): array
    {
        $used = [];
        $unique = [];
        foreach ($names as $name) {
            $candidate = $name;
            $extension = \pathinfo($name, PATHINFO_EXTENSION);
            $base = $extension === '' ? $name : \substr($name, 0, -\strlen($extension) - 1);
            for ($number = 2; isset($used[\strtolower($candidate)]); $number++) {
                $candidate = $base . ' (' . $number . ')' . ($extension === '' ? '' : '.' . $extension);
            }
            $used[\strtolower($candidate)] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @param array<mixed> $files `$_FILES`
     * @return list<array{name: string, tmp_name: string, error: int, size: int}> the chosen files
     */
    public static function uploads(array $files): array
    {
        $field = $files[self::FIELD] ?? null;
        if (!\is_array($field) || !\is_array($field['name'] ?? null)) {
            return [];
        }

        $uploads = [];
        foreach (\array_keys($field['name']) as $key) {
            $name = $field['name'][$key] ?? null;
            $error = \is_array($field['error'] ?? null) ? ($field['error'][$key] ?? null) : null;
            if (!\is_string($name) || $name === '' || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $temporaryPath = \is_array($field['tmp_name'] ?? null) ? ($field['tmp_name'][$key] ?? '') : '';
            $size = \is_array($field['size'] ?? null) ? ($field['size'][$key] ?? 0) : 0;
            $uploads[] = [
                'name' => $name,
                'tmp_name' => \is_string($temporaryPath) ? $temporaryPath : '',
                'error' => \is_int($error) ? $error : UPLOAD_ERR_NO_FILE,
                'size' => \is_int($size) ? $size : 0,
            ];
        }

        return $uploads;
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $upload
     */
    private function rejection(array $upload): ?string
    {
        $isUploadedFile = $this->isUploadedFile ?? \is_uploaded_file(...);
        $message = match (true) {
            $upload['error'] === UPLOAD_ERR_INI_SIZE, $upload['error'] === UPLOAD_ERR_FORM_SIZE
                /* translators: %s: file name */
                => \__('"%s" is larger than the maximum upload size.', 'orchestra-email'),
            $upload['error'] !== UPLOAD_ERR_OK, $upload['tmp_name'] === '', !$isUploadedFile($upload['tmp_name'])
                /* translators: %s: file name */
                => \__('"%s" could not be uploaded.', 'orchestra-email'),
            /* translators: %s: file name */
            $upload['size'] <= 0 => \__('"%s" is empty.', 'orchestra-email'),
            default => null,
        };

        return $message === null ? null : \sprintf($message, $upload['name']);
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function delete(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $this->storage->delete($attachment->storageName);
        }
        $this->repository->delete(\array_map(static fn (Attachment $a): int => $a->id, $attachments));
    }

    private static function allowedExtensionsLabel(): string
    {
        return \str_replace('|', ', ', \implode(', ', \array_keys(self::allowedFileTypes())));
    }
}
