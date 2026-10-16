<?php

declare(strict_types=1);

namespace Orchestra\Email;

/**
 * An email as written on the compose screen, before it is sent.
 */
final class EmailDraft
{
    public const SUBJECT_MAX_LENGTH = 200;
    public const BODY_MAX_LENGTH = 50000;

    /**
     * @param list<string> $groupIds
     */
    public function __construct(
        public readonly array $groupIds,
        public readonly string $subject,
        public readonly string $body,
    ) {
    }

    /**
     * @param array<mixed> $input Unslashed form data with `groups`, `subject` and `body`
     */
    public static function fromForm(array $input): self
    {
        $groups = \is_array($input['groups'] ?? null) ? $input['groups'] : [];
        $groupIds = [];
        foreach ($groups as $id) {
            if (\is_string($id) && $id !== '') {
                $groupIds[$id] = $id;
            }
        }
        $subject = \is_string($input['subject'] ?? null) ? $input['subject'] : '';
        $body = \is_string($input['body'] ?? null) ? $input['body'] : '';

        return new self(
            \array_values($groupIds),
            // A line break in the subject would inject headers.
            \trim((string) \preg_replace('/[\r\n\t]+/', ' ', \strip_tags($subject))),
            \trim(\str_replace("\r\n", "\n", \strip_tags($body)))
        );
    }

    /**
     * Checks the text. Groups and permissions are checked by {@see EmailService::validate()}.
     *
     * @return list<string> error messages
     */
    public function textErrors(): array
    {
        $errors = [];
        if ($this->subject === '') {
            $errors[] = \__('Please enter a subject.', 'orchestra-email');
        } elseif (\mb_strlen($this->subject) > self::SUBJECT_MAX_LENGTH) {
            /* translators: %d: maximum number of characters */
            $message = \__('The subject must not be longer than %d characters.', 'orchestra-email');
            $errors[] = \sprintf($message, self::SUBJECT_MAX_LENGTH);
        }
        if ($this->body === '') {
            $errors[] = \__('Please enter a message.', 'orchestra-email');
        } elseif (\mb_strlen($this->body) > self::BODY_MAX_LENGTH) {
            /* translators: %d: maximum number of characters */
            $message = \__('The message must not be longer than %d characters.', 'orchestra-email');
            $errors[] = \sprintf($message, self::BODY_MAX_LENGTH);
        }

        return $errors;
    }
}
