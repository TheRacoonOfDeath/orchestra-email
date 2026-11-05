<?php

declare(strict_types=1);

namespace Orchestra\Email\Automatic;

/**
 * An email the system sends by itself, e.g. when a member's parts change. Registered by the domain
 * plugins during {@see \Orchestra\Email\EmailService::REGISTER_AUTOMATIC_ACTION}; organizers change
 * its text on the "Automatic emails" tab.
 *
 * Subject and body contain placeholders like `{project}`, which the sending plugin fills for each
 * recipient.
 */
final class AutomaticEmail
{
    /**
     * @param string $id Unique, `orchestra_{plugin}_{name}`, e.g. `orchestra_projects_changed_parts`
     * @param string $description When the email is sent, shown above its text
     * @param string $capability Required to change the text
     * @param string $defaultSubject Plain text with placeholders
     * @param string $defaultBody HTML with placeholders, see {@see \Orchestra\Email\EmailBody}
     * @param array<string, string> $placeholders name without braces => what it is replaced with
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly string $capability,
        public readonly string $defaultSubject,
        public readonly string $defaultBody,
        public readonly array $placeholders,
    ) {
    }

    public function canBeEditedBy(int $userId): bool
    {
        return $userId > 0 && \user_can($userId, $this->capability);
    }
}
