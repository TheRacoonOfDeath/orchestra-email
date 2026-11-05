<?php

declare(strict_types=1);

namespace Orchestra\Email\Automatic;

/**
 * The texts of the automatic emails changed by the organizers (option
 * `orchestra_email_automatic_templates`). An email without a stored text uses its default, which
 * follows the language of the Orchestra pages.
 */
final class TemplateStore
{
    public const OPTION = 'orchestra_email_automatic_templates';

    /**
     * @return array{subject: string, body: string, custom: bool}
     */
    public function template(AutomaticEmail $email): array
    {
        $stored = $this->stored()[$email->id] ?? null;

        return $stored === null
            ? ['subject' => $email->defaultSubject, 'body' => $email->defaultBody, 'custom' => false]
            : $stored + ['custom' => true];
    }

    public function save(string $id, string $subject, string $body): void
    {
        $stored = $this->stored();
        $stored[$id] = ['subject' => $subject, 'body' => $body];
        \update_option(self::OPTION, $stored, false);
    }

    public function reset(string $id): void
    {
        $stored = $this->stored();
        unset($stored[$id]);
        \update_option(self::OPTION, $stored, false);
    }

    /**
     * @return array<string, array{subject: string, body: string}>
     */
    private function stored(): array
    {
        $option = \get_option(self::OPTION, []);
        $stored = [];
        foreach (\is_array($option) ? $option : [] as $id => $template) {
            if (
                \is_string($id) && \is_array($template)
                && \is_string($template['subject'] ?? null) && \is_string($template['body'] ?? null)
            ) {
                $stored[$id] = ['subject' => $template['subject'], 'body' => $template['body']];
            }
        }

        return $stored;
    }
}
